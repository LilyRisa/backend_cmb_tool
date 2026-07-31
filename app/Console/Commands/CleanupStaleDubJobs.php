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
        $creditsDeductedUser = null;

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

            // getTaskStatus() reconciles the real charge on the linked TtsHistory
            // (full refund on failure, adjust up/down to actual usage on success) —
            // accumulate it so applyTtsResult() can sync it back onto the job. Only
            // summed across tasks that resolved, which is all of them by the time
            // $allCompleted survives this loop.
            if (isset($result['data']['credits_deducted_user'])) {
                $creditsDeductedUser = (int) $creditsDeductedUser + (int) $result['data']['credits_deducted_user'];
            }

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
            // Shared with VideoDubController::status()'s finalizer so the two paths
            // can't write different fields — see VideoDubJob::applyTtsResult().
            $job->applyTtsResult([
                'status' => $anyFailed && empty($audioUrls) ? 'failed' : 'completed',
                'audio_url' => $audioUrls[0] ?? null,
                'error' => 'All TTS tasks failed (finalized by cron)',
                'credits_deducted_user' => $creditsDeductedUser,
            ]);

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
