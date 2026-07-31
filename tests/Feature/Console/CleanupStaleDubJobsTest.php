<?php

namespace Tests\Feature\Console;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CleanupStaleDubJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
    }

    public function test_ignores_jobs_updated_recently(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [1],
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $this->assertEquals('tts_pending', $job->fresh()->status);
    }

    public function test_finalizes_stale_job_when_tts_task_completed(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['status' => 'completed', 'audio_url' => 'https://cdn/done.mp3'], 200)]);

        $user = User::factory()->create();
        // getTaskStatus() looks up a real TtsHistory row by id + user_id and only
        // calls the (mocked) provider HTTP endpoint when its local status is not
        // already completed/failed — so the referenced tts_task_ids entry must be
        // a genuine, still-pending TtsHistory row, not an arbitrary integer.
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'credits_deducted_user' => 10,
        ]);
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [$history->id],
            // 8s of subtitles, so the duration assertion below can't pass vacuously on 0.
            'srt_translated' => "1\n00:00:00,000 --> 00:00:08,000\nXin chào\n",
            'credits_deducted' => 10,
            'updated_at' => now()->subMinutes(35),
        ]);

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $fresh = $job->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertEquals('https://cdn/done.mp3', $fresh->audio_url);
        // The cron finalizer used to skip duration_seconds entirely, leaving every
        // cron-finalized job NULL forever — it now shares the controller's finalizer.
        $this->assertEquals(8, $fresh->duration_seconds);
        // getTaskStatus() reconciled the pre-deduction (10) down to the provider's
        // actual usage (0 characters reported) and refunded the difference; the job
        // must report that reconciled charge, not the stale estimate.
        $this->assertEquals(0, $fresh->credits_deducted);
    }

    public function test_marks_job_failed_when_tts_task_failed(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['status' => 'failed', 'error' => 'provider error'], 200)]);

        $user = User::factory()->create();
        // See test_finalizes_stale_job_when_tts_task_completed() above for why a
        // real, still-pending TtsHistory row is required here.
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [$history->id],
            'updated_at' => now()->subMinutes(35),
        ]);

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $this->assertEquals('failed', $job->fresh()->status);
    }

    public function test_force_times_out_jobs_older_than_120_minutes_without_refund(): void
    {
        $user = User::factory()->create(['monthly_credits' => 500]);
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [42],
            'credits_deducted' => 100,
            'updated_at' => now()->subMinutes(125),
        ]);

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('timed out', $fresh->error);
        $this->assertEquals(500, $user->fresh()->monthly_credits);
    }

    public function test_marks_failed_when_user_deleted(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [42],
            'updated_at' => now()->subMinutes(35),
        ]);

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        User::where('id', $user->id)->delete();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $this->assertEquals('failed', $job->fresh()->status);
    }
}
