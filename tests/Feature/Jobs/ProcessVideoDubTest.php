<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessVideoDub;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VideoDubJob;
use App\Services\GenMaxService;
use App\Services\GroqService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessVideoDubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
    }

    private function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'videodub');
        file_put_contents($path, 'fake audio bytes');
        return $path;
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'target_language' => 'vi',
            'voice_id' => 'voice_abc',
            'provider' => 'elevenlabs',
            'model_id' => null,
            'voice_settings' => null,
        ], $overrides);
    }

    public function test_handle_runs_full_pipeline_and_marks_tts_pending(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [['start' => 0, 'end' => 3, 'text' => 'Hello world this is a test sentence']],
            ], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "1\n00:00:00,000 --> 00:00:03,000\nXin chào thế giới"]]],
            ], 200),
            'api.genmax.io/*' => Http::response(['id' => 'genmax_task_1'], 200),
        ]);

        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10), 'monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->handle(app(GroqService::class), app(OpenRouterService::class), app(GenMaxService::class));

        $fresh = $job->fresh();
        $this->assertEquals('tts_pending', $fresh->status);
        $this->assertStringContainsString('Hello world', $fresh->srt_original);
        $this->assertStringContainsString('Xin chào', $fresh->srt_translated);
        $this->assertNotEmpty($fresh->tts_task_ids);
        $this->assertGreaterThan(0, $fresh->credits_deducted);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
        $this->assertFileDoesNotExist($path);
    }

    public function test_handle_marks_failed_when_transcription_throws(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'bad audio']], 400)]);

        $user = User::factory()->create();
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->handle(app(GroqService::class), app(OpenRouterService::class), app(GenMaxService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Transcription failed', $fresh->error);
        $this->assertFileDoesNotExist($path);
    }

    public function test_handle_marks_failed_when_translation_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hello']]], 200),
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        $user = User::factory()->create();
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->handle(app(GroqService::class), app(OpenRouterService::class), app(GenMaxService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Translation failed', $fresh->error);
        $this->assertNotNull($fresh->srt_original);
    }

    public function test_handle_marks_failed_with_no_deduction_when_tts_submission_rejected_for_insufficient_credit(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 3, 'text' => 'Hello world']]], 200),
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => "1\n00:00:00,000 --> 00:00:03,000\nXin chào"]]]], 200),
        ]);

        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10), 'monthly_credits' => 0, 'purchased_credits' => 0, 'credits' => 0]);
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->handle(app(GroqService::class), app(OpenRouterService::class), app(GenMaxService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertEquals(0, $fresh->credits_deducted);
        $this->assertEquals(0, $user->fresh()->monthly_credits);
    }

    public function test_handle_marks_failed_and_cleans_up_when_user_missing(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        // Simulate the user having been deleted between dispatch and processing.
        // video_dub_jobs.user_id has an "on delete cascade" FK, so a normal
        // delete would cascade and remove the job row too — disable FK
        // enforcement for the delete so the job row is left dangling.
        DB::statement('PRAGMA foreign_keys = OFF');
        User::where('id', $user->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->assertNull($job->fresh()->user);

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->handle(app(GroqService::class), app(OpenRouterService::class), app(GenMaxService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('User not found', $fresh->error);
        $this->assertFileDoesNotExist($path);
    }

    public function test_real_dispatch_through_database_queue_serializes_and_runs_the_pipeline(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [['start' => 0, 'end' => 3, 'text' => 'Hello world this is a test sentence']],
            ], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "1\n00:00:00,000 --> 00:00:03,000\nXin chào thế giới"]]],
            ], 200),
            'api.genmax.io/*' => Http::response(['id' => 'genmax_task_1'], 200),
        ]);

        // phpunit.xml pins QUEUE_CONNECTION=sync, under which dispatch() runs the
        // handler inline and never serializes. Every other test in this phase either
        // fakes the queue or calls handle() on a hand-built instance, so the
        // dispatch -> jobs table -> worker -> deserialize -> handle round-trip
        // (where the SerializesModels orphan/temp-file-leak failure modes live) was
        // never exercised. Force the database driver for this one test.
        config(['queue.default' => 'database']);

        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10), 'monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        ProcessVideoDub::dispatch($job, $path, 'audio.mp3', $this->params());

        // Genuinely queued, not run inline.
        $this->assertDatabaseCount('jobs', 1);
        $this->assertEquals('queued', $job->fresh()->status);

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->assertExitCode(0);

        $fresh = $job->fresh();
        $this->assertEquals('tts_pending', $fresh->status);
        $this->assertNotEmpty($fresh->tts_task_ids);
        $this->assertStringContainsString('Xin chào', $fresh->srt_translated);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_failed_method_marks_job_as_permanently_failed(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'voice_id' => 'voice_abc', 'status' => 'processing', 'stage' => 'translating']);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->failed(new \RuntimeException('queue worker crashed'));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('queue worker crashed', $fresh->error);
    }

    public function test_failed_method_leaves_already_submitted_tts_job_pollable(): void
    {
        // A worker killed between handle()'s 'tts_pending' update and its return
        // (e.g. $timeout elapsing) triggers failed() on a job whose TTS task is
        // already created and paid for. Overwriting it back to 'failed' would hide
        // it from both finalizers forever — credits charged, no audio, no refund.
        $user = User::factory()->create();
        $job = VideoDubJob::create([
            'user_id' => $user->id,
            'target_language' => 'vi',
            'voice_id' => 'voice_abc',
            'status' => 'tts_pending',
            'stage' => 'tts',
            'tts_task_ids' => [777],
            'credits_deducted' => 42,
            'error' => null,
        ]);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->failed(new \RuntimeException('worker timed out'));

        $fresh = $job->fresh();
        $this->assertEquals('tts_pending', $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertEquals([777], $fresh->tts_task_ids);
        $this->assertEquals(42, $fresh->credits_deducted);
        // cleanup() must not have run either — handle() owns the temp file's
        // lifetime once it has reached this state.
        $this->assertFileExists($path);

        @unlink($path);
    }

    public function test_failed_method_leaves_completed_job_untouched(): void
    {
        $user = User::factory()->create();
        $job = VideoDubJob::create([
            'user_id' => $user->id,
            'target_language' => 'vi',
            'voice_id' => 'voice_abc',
            'status' => 'completed',
            'stage' => 'done',
            'audio_url' => 'https://cdn/final.mp3',
        ]);
        $path = $this->makeTempAudioFile();

        (new ProcessVideoDub($job, $path, 'audio.mp3', $this->params()))
            ->failed(new \RuntimeException('worker crashed after completion'));

        $fresh = $job->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertEquals('https://cdn/final.mp3', $fresh->audio_url);
        $this->assertNull($fresh->error);

        @unlink($path);
    }
}
