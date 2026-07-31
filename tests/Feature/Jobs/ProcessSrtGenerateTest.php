<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessSrtGenerate;
use App\Models\SrtGenerateJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessSrtGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'srtgen');
        file_put_contents($path, 'fake audio bytes');
        return $path;
    }

    public function test_handle_transcribes_and_completes_job_deducting_credits(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [['start' => 0, 'end' => 3, 'text' => 'Hello world this is a test sentence']],
            ], 200),
        ]);

        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10), 'monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $job = SrtGenerateJob::create(['user_id' => $user->id, 'original_filename' => 'audio.mp3', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtGenerate($job, $path, 'audio.mp3', null))->handle(app(\App\Services\GroqService::class));

        $fresh = $job->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertGreaterThan(0, $fresh->characters_used);
        $this->assertGreaterThan(0, $fresh->credits_deducted);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
        $this->assertFileDoesNotExist($path);
    }

    public function test_handle_marks_failed_when_transcription_throws(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'bad audio']], 400)]);

        $user = User::factory()->create();
        $job = SrtGenerateJob::create(['user_id' => $user->id, 'original_filename' => 'audio.mp3', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtGenerate($job, $path, 'audio.mp3', null))->handle(app(\App\Services\GroqService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Transcription failed', $fresh->error);
    }

    public function test_handle_marks_failed_with_no_deduction_when_credits_insufficient(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 3, 'text' => str_repeat('a', 500)]]], 200),
        ]);

        $user = User::factory()->create(['monthly_credits' => 1, 'purchased_credits' => 0, 'credits' => 1]);
        $job = SrtGenerateJob::create(['user_id' => $user->id, 'original_filename' => 'audio.mp3', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtGenerate($job, $path, 'audio.mp3', null))->handle(app(\App\Services\GroqService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Không đủ credit', $fresh->error);
        $this->assertEquals(1, $user->fresh()->monthly_credits);
    }

    public function test_handle_marks_failed_and_cleans_up_when_user_missing(): void
    {
        $user = User::factory()->create();
        $job = SrtGenerateJob::create(['user_id' => $user->id, 'original_filename' => 'audio.mp3', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        // Simulate the user having been deleted between dispatch and processing.
        // The srt_generate_jobs.user_id column has an "on delete cascade" FK, so
        // a normal delete would cascade and remove the job row too. Disable FK
        // enforcement for the delete so the job row is left dangling, pointing
        // at a user_id that no longer resolves via the belongsTo relation.
        DB::statement('PRAGMA foreign_keys = OFF');
        User::where('id', $user->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->assertNull($job->fresh()->user);

        (new ProcessSrtGenerate($job, $path, 'audio.mp3', null))->handle(app(\App\Services\GroqService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('User not found', $fresh->error);
        $this->assertFileDoesNotExist($path);
    }

    public function test_failed_method_marks_job_as_permanently_failed(): void
    {
        $user = User::factory()->create();
        $job = SrtGenerateJob::create(['user_id' => $user->id, 'original_filename' => 'audio.mp3', 'status' => 'processing', 'stage' => 'transcribing']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtGenerate($job, $path, 'audio.mp3', null))->failed(new \RuntimeException('queue worker crashed'));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('queue worker crashed', $fresh->error);
    }
}
