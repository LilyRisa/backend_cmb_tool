<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoDubControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function premiumUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ], $attributes));
    }

    public function test_dub_creates_job_and_dispatches_pipeline(): void
    {
        Queue::fake();
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/video-dub', [
                'file' => $file,
                'target_language' => 'vi',
                'voice_id' => 'voice_abc',
            ]);

        $response->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('video_dub_jobs', [
            'user_id' => $user->id,
            'target_language' => 'vi',
            'voice_id' => 'voice_abc',
            'status' => 'queued',
        ]);
        Queue::assertPushed(\App\Jobs\ProcessVideoDub::class);
    }

    public function test_dub_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/video-dub', ['file' => $file, 'target_language' => 'vi', 'voice_id' => 'voice_abc'])
            ->assertStatus(403);
    }

    public function test_dub_rejects_premium_user_with_zero_credits(): void
    {
        Queue::fake();
        $user = $this->premiumUser(['monthly_credits' => 0, 'purchased_credits' => 0, 'credits' => 0]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/video-dub', ['file' => $file, 'target_language' => 'vi', 'voice_id' => 'voice_abc'])
            ->assertStatus(402);

        $this->assertDatabaseCount('video_dub_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_dub_requires_target_language_and_voice_id(): void
    {
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/video-dub', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_status_returns_current_state_while_processing(): void
    {
        $user = $this->premiumUser();
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'processing', 'stage' => 'translating']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/video-dub/status/{$job->id}");

        $response->assertOk()->assertJsonPath('status', 'processing');
    }

    public function test_status_polls_genmax_and_finalizes_completed_tts(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['status' => 'completed', 'audio_url' => 'https://cdn/final.mp3'], 200)]);

        $user = $this->premiumUser();

        // GenMaxService::getTaskStatus() (Phase 3A, unmodified) resolves the
        // historyId in tts_task_ids against a real TtsHistory row scoped to
        // the requesting user — tts_task_ids stores TtsHistory primary keys
        // (see VideoDubJob::getTtsHistories()/ProcessVideoDub), not arbitrary
        // provider task IDs. A bare placeholder int here would make
        // getTaskStatus() return success=false (row not found) and the
        // status endpoint would correctly leave the job at 'tts_pending'
        // instead of finalizing it, so a real row must be seeded.
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'stage' => 'tts',
            'tts_task_ids' => [$history->id],
            'srt_translated' => "1\n00:00:00,000 --> 00:00:05,000\nXin chào\n",
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/video-dub/status/{$job->id}");

        $response->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('audio_url', 'https://cdn/final.mp3');
        $this->assertEquals('completed', $job->fresh()->status);
        $this->assertEquals(5, $job->fresh()->duration_seconds);
    }

    public function test_status_withholds_srt_and_audio_for_failed_job(): void
    {
        $user = $this->premiumUser();
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => 'Không đủ credit',
            'srt_original' => 'paid original',
            'srt_translated' => 'paid translated',
            'audio_url' => 'https://cdn/should-not-leak.mp3',
        ]);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/video-dub/status/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('success', false)
            ->assertJsonPath('srt_original', null)
            ->assertJsonPath('srt_translated', null)
            ->assertJsonPath('audio_url', null);
    }

    public function test_status_404s_for_another_users_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = VideoDubJob::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->authHeader($other))
            ->getJson("/api/tool/video-dub/status/{$job->id}")
            ->assertStatus(404);
    }
}
