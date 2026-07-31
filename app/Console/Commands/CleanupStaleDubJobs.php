<?php

namespace App\Console\Commands;

use App\Models\VideoDubJob;
use App\Services\GenMaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleDubJobs extends Command
{
    protected $signature = 'dub:cleanup-stale';
    protected $description = 'Poll and finalize orphaned video-dub jobs that clients stopped polling';

    /** Jobs older than this (minutes) will be polled for finalization. */
    const STALE_AFTER_MINUTES = 30;

    /** Jobs older than this (minutes) will be force-marked as timed out. */
    const TIMEOUT_AFTER_MINUTES = 120;

    public function handle(GenMaxService $genMax): int
    {
        $staleJobs = VideoDubJob::where('status', 'tts_pending')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->with('user')
            ->limit(50)
            ->get();

        if ($staleJobs->isEmpty()) {
            $this->info('No stale jobs found.');
            return self::SUCCESS;
        }

        $this->info("Found {$staleJobs->count()} stale job(s). Processing...");

        foreach ($staleJobs as $job) {
            $this->processStaleJob($job, $genMax);
        }

        return self::SUCCESS;
    }

    protected function processStaleJob(VideoDubJob $job, GenMaxService $genMax): void
    {
        $user = $job->user;
        if (!$user) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'User not found (deleted?)',
            ]);
            Log::warning("Stale dub job #{$job->id}: user not found");
            return;
        }

        $ttsTaskIds = $job->tts_task_ids ?? [];

        if ($job->updated_at->lt(now()->subMinutes(self::TIMEOUT_AFTER_MINUTES))) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'Job timed out after ' . self::TIMEOUT_AFTER_MINUTES . ' minutes',
            ]);
            // NO REFUND — the TTS task was already created and credits consumed.
            Log::info("Stale dub job #{$job->id}: force timeout, no refund (TTS tasks exist)", [
                'tts_tasks' => count($ttsTaskIds),
            ]);
            return;
        }

        $allCompleted = true;
        $anyFailed = false;
        $audioUrls = [];

        foreach ($ttsTaskIds as $historyId) {
            try {
                $result = $genMax->getTaskStatus($user, $historyId);
            } catch (\Throwable $e) {
                $allCompleted = false;
                continue;
            }

            if (!($result['success'] ?? false)) {
                $allCompleted = false;
                continue;
            }

            $status = $result['data']['status'] ?? 'pending';

            if ($status === 'failed') {
                $anyFailed = true;
            } elseif ($status === 'completed') {
                if (!empty($result['data']['audio_url'])) {
                    $audioUrls[] = $result['data']['audio_url'];
                }
            } else {
                $allCompleted = false;
            }
        }

        if ($allCompleted && !empty($ttsTaskIds)) {
            if ($anyFailed && empty($audioUrls)) {
                $job->update([
                    'status' => 'failed',
                    'stage' => 'done',
                    'error' => 'All TTS tasks failed (finalized by cron)',
                ]);
            } else {
                $job->update([
                    'status' => 'completed',
                    'stage' => 'done',
                    'audio_url' => $audioUrls[0] ?? null,
                    'audio_urls' => $audioUrls,
                ]);
            }

            $this->line("  Job #{$job->id}: finalized as {$job->status}");
            Log::info("Stale dub job #{$job->id} finalized", [
                'status' => $job->status,
                'audio_urls' => count($audioUrls),
            ]);
        } else {
            $this->line("  Job #{$job->id}: still pending, will retry next run");
        }
    }
}
