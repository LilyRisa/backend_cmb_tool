<?php

namespace Tests\Feature\Tool;

use App\Models\SrtGenerateJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SrtGenerateControllerTest extends TestCase
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

    public function test_generate_creates_job_and_dispatches_pipeline(): void
    {
        Queue::fake();
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/generate-srt', ['file' => $file]);

        $response->assertStatus(202)->assertJsonPath('status', 'queued');
        $this->assertDatabaseHas('srt_generate_jobs', ['user_id' => $user->id, 'status' => 'queued']);
        Queue::assertPushed(\App\Jobs\ProcessSrtGenerate::class);
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/generate-srt', ['file' => $file])
            ->assertStatus(403);
    }

    public function test_generate_validates_file_size_limit(): void
    {
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 70000, 'audio/mpeg'); // over 60MB

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/generate-srt', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_status_returns_job_state(): void
    {
        $user = $this->premiumUser();
        $job = SrtGenerateJob::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'srt_content' => '1\n00:00:01,000 --> 00:00:02,000\nHi']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/generate-srt/status/{$job->id}");

        $response->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('is_final', true);
    }

    public function test_status_withholds_srt_content_for_failed_job(): void
    {
        $user = $this->premiumUser();
        $job = SrtGenerateJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'failed',
            'error' => 'Không đủ credit',
            'srt_content' => "1\n00:00:01,000 --> 00:00:02,000\nPaid content",
        ]);

        $this->assertDatabaseHas('srt_generate_jobs', ['id' => $job->id, 'status' => 'failed']);
        $this->assertNotNull($job->fresh()->srt_content);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/generate-srt/status/{$job->id}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('success', false)
            ->assertJsonPath('srt_content', null);
    }

    public function test_generate_rejects_premium_user_with_zero_credits(): void
    {
        Queue::fake();
        $user = $this->premiumUser(['monthly_credits' => 0, 'purchased_credits' => 0]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/generate-srt', ['file' => $file])
            ->assertStatus(402);

        $this->assertDatabaseCount('srt_generate_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_status_404s_for_another_users_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = SrtGenerateJob::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->authHeader($other))
            ->getJson("/api/tool/generate-srt/status/{$job->id}")
            ->assertStatus(404);
    }
}
