<?php

namespace Tests\Unit;

use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDubJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_resolves_owning_user(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($job->user->is($user));
    }

    public function test_casts_json_columns_to_arrays(): void
    {
        $job = VideoDubJob::factory()->create([
            'voice_settings' => ['stability' => 0.5],
            'tts_task_ids' => [1, 2],
            'audio_urls' => ['https://cdn/a.mp3'],
        ]);

        $fresh = $job->fresh();
        $this->assertIsArray($fresh->voice_settings);
        $this->assertIsArray($fresh->tts_task_ids);
        $this->assertIsArray($fresh->audio_urls);
        $this->assertEquals(0.5, $fresh->voice_settings['stability']);
    }

    public function test_get_tts_histories_returns_empty_collection_when_no_task_ids(): void
    {
        $job = VideoDubJob::factory()->create(['tts_task_ids' => null]);

        $this->assertCount(0, $job->getTtsHistories());
    }

    public function test_get_tts_histories_returns_matching_records(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id]);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $histories = $job->getTtsHistories();

        $this->assertCount(1, $histories);
        $this->assertTrue($histories->first()->is($history));
    }

    public function test_all_tts_completed_is_false_when_no_task_ids(): void
    {
        $job = VideoDubJob::factory()->create(['tts_task_ids' => null]);

        $this->assertFalse($job->allTtsCompleted());
    }

    public function test_all_tts_completed_is_true_when_every_linked_task_is_terminal(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $this->assertTrue($job->allTtsCompleted());
    }

    public function test_all_tts_completed_is_false_when_a_task_is_still_pending(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $this->assertFalse($job->allTtsCompleted());
    }

    public function test_has_failed_tts_detects_a_failed_linked_task(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'failed']);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $this->assertTrue($job->hasFailedTts());
    }

    public function test_get_completed_audio_urls_returns_only_completed_urls_in_order(): void
    {
        $user = User::factory()->create();
        $h1 = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'audio_url' => 'https://cdn/1.mp3']);
        $h2 = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'failed', 'audio_url' => null]);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$h1->id, $h2->id]]);

        $this->assertEquals(['https://cdn/1.mp3'], $job->getCompletedAudioUrls());
    }
}
