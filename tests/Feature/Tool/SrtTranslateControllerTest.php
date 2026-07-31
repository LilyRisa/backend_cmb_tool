<?php

namespace Tests\Feature\Tool;

use App\Models\SrtTranslateJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SrtTranslateControllerTest extends TestCase
{
    use RefreshDatabase;

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
        ], $attributes));
    }

    public function test_translate_creates_job_and_dispatches_pipeline(): void
    {
        Queue::fake();
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/translate-srt', ['file' => $file, 'target_language' => 'vi']);

        $response->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('srt_translate_jobs', ['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued']);
        Queue::assertPushed(\App\Jobs\ProcessSrtTranslate::class);
    }

    public function test_translate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/translate-srt', ['file' => $file, 'target_language' => 'vi'])
            ->assertStatus(403);
    }

    public function test_translate_requires_target_language(): void
    {
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/translate-srt', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_status_returns_job_state(): void
    {
        $user = $this->premiumUser();
        $job = SrtTranslateJob::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'srt_original' => 'orig', 'srt_translated' => 'trans']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/translate-srt/status/{$job->id}");

        $response->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('srt_translated', 'trans');
    }

    public function test_status_withholds_srt_payload_for_failed_job(): void
    {
        $user = $this->premiumUser();
        $job = SrtTranslateJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => 'Không đủ credit',
            'srt_original' => 'orig paid content',
            'srt_translated' => 'trans paid content',
        ]);

        $this->assertNotNull($job->fresh()->srt_translated);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/translate-srt/status/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('success', false)
            ->assertJsonPath('srt_original', null)
            ->assertJsonPath('srt_translated', null);
    }

    public function test_translate_rejects_premium_user_with_zero_credits(): void
    {
        Queue::fake();
        $user = $this->premiumUser(['monthly_credits' => 0, 'purchased_credits' => 0]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/translate-srt', ['file' => $file, 'target_language' => 'vi'])
            ->assertStatus(402);

        $this->assertDatabaseCount('srt_translate_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_status_404s_for_another_users_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = SrtTranslateJob::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->authHeader($other))
            ->getJson("/api/tool/translate-srt/status/{$job->id}")
            ->assertStatus(404);
    }
}
