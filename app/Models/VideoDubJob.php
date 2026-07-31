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
