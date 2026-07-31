<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoDubJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'target_language',
        'voice_id',
        'provider',
        'model_id',
        'voice_settings',
        'source_language',
        'srt_original',
        'srt_translated',
        'status',
        'stage',
        'error',
        'characters_used',
        'credits_deducted',
        'audio_url',
        'audio_urls',
        'duration_seconds',
        'tts_task_ids',
    ];

    protected $casts = [
        'voice_settings' => 'array',
        'tts_task_ids' => 'array',
        'audio_urls' => 'array',
        'characters_used' => 'integer',
        'credits_deducted' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * NOT an Eloquent relationship — tts_task_ids is a plain JSON array of
     * TtsHistory primary keys, not a foreign key column, so this looks them
     * up directly and returns a Collection.
     */
    public function getTtsHistories()
    {
        $ids = $this->tts_task_ids ?? [];
        if (empty($ids)) {
            return collect();
        }
        return TtsHistory::whereIn('id', $ids)->get();
    }

    public function allTtsCompleted(): bool
    {
        $ids = $this->tts_task_ids ?? [];
        if (empty($ids)) {
            return false;
        }

        $pending = TtsHistory::whereIn('id', $ids)
            ->whereNotIn('status', ['completed', 'failed'])
            ->count();

        return $pending === 0;
    }

    public function hasFailedTts(): bool
    {
        $ids = $this->tts_task_ids ?? [];
        if (empty($ids)) {
            return false;
        }

        return TtsHistory::whereIn('id', $ids)
            ->where('status', 'failed')
            ->exists();
    }

    /**
     * Write the terminal state implied by a resolved TTS task onto this job.
     *
     * Single source of truth for "TTS resolved → job finalized", shared by BOTH
     * finalization paths (VideoDubController::status()'s client poll and the
     * dub:cleanup-stale cron) so they cannot drift apart again — they previously
     * hand-rolled this independently and the cron path never wrote
     * duration_seconds, leaving every cron-finalized job at NULL forever.
     *
     * $taskData is GenMaxService::getTaskStatus()'s `data` payload (i.e.
     * formatHistoryResponse()'s array), or an equivalent array built by the caller.
     * Its `credits_deducted_user` key carries the RECONCILED charge — getTaskStatus()
     * refunds in full on failure and adjusts up/down to actual provider usage on
     * completion — so it, not ProcessVideoDub's pre-deduction estimate, is what this
     * job should report to the API and to the admin dashboard's summed credit stat.
     */
    public function applyTtsResult(array $taskData): void
    {
        $status = $taskData['status'] ?? 'pending';

        if ($status === 'completed') {
            $audioUrl = $taskData['audio_url'] ?? null;

            $this->update([
                'status' => 'completed',
                'stage' => 'done',
                'audio_url' => $audioUrl,
                'audio_urls' => $audioUrl ? [$audioUrl] : [],
                'duration_seconds' => self::estimateDurationFromSrt($this->srt_translated ?? $this->srt_original),
                'credits_deducted' => $taskData['credits_deducted_user'] ?? $this->credits_deducted,
            ]);
        } elseif ($status === 'failed') {
            $this->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => $taskData['error'] ?? 'TTS task failed',
                'credits_deducted' => $taskData['credits_deducted_user'] ?? $this->credits_deducted,
            ]);
        }
        // else: still pending — caller leaves the row untouched.
    }

    /**
     * Derive a duration (seconds) from an SRT payload's last timestamp.
     */
    public static function estimateDurationFromSrt(?string $srt): int
    {
        if (empty($srt)) {
            return 0;
        }

        preg_match_all('/(\d{2}):(\d{2}):(\d{2})[,.](\d{3})/', $srt, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return 0;
        }

        $lastMatch = end($matches);
        $hours = (int) $lastMatch[1];
        $minutes = (int) $lastMatch[2];
        $seconds = (int) $lastMatch[3];

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    public function getCompletedAudioUrls(): array
    {
        $ids = $this->tts_task_ids ?? [];
        if (empty($ids)) {
            return [];
        }

        return TtsHistory::whereIn('id', $ids)
            ->where('status', 'completed')
            ->whereNotNull('audio_url')
            ->orderBy('id')
            ->pluck('audio_url')
            ->toArray();
    }
}
