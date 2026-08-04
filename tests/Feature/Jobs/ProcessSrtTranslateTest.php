<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessSrtTranslate;
use App\Models\SrtTranslateJob;
use App\Models\User;
use App\Services\GroqService;
use App\Services\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessSrtTranslateTest extends TestCase
{
    use RefreshDatabase;

    private function makeTempAudioFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'srttranslate');
        file_put_contents($path, 'fake audio bytes');
        return $path;
    }

    public function test_handle_transcribes_translates_and_completes_job(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [['start' => 0, 'end' => 3, 'text' => 'Hello world this is a test sentence']],
            ], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "1\n00:00:00,000 --> 00:00:03,000\nXin chào thế giới"]]],
            ], 200),
        ]);

        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10), 'monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->handle(app(GroqService::class), app(AiTextService::class));

        $fresh = $job->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertStringContainsString('Hello world', $fresh->srt_original);
        $this->assertStringContainsString('Xin chào', $fresh->srt_translated);
        $this->assertGreaterThan(0, $fresh->credits_deducted);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
        $this->assertFileDoesNotExist($path);
    }

    public function test_handle_marks_failed_when_transcription_throws(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'bad audio']], 400)]);

        $user = User::factory()->create();
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->handle(app(GroqService::class), app(AiTextService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Transcription failed', $fresh->error);
    }

    public function test_handle_marks_failed_when_translation_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hello']]], 200),
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        $user = User::factory()->create();
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->handle(app(GroqService::class), app(AiTextService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Translation failed', $fresh->error);
        $this->assertNotNull($fresh->srt_original);
    }

    public function test_handle_marks_failed_with_no_deduction_when_credits_insufficient(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 3, 'text' => str_repeat('a', 500)]]], 200),
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => "1\n00:00:00,000 --> 00:00:03,000\n" . str_repeat('b', 500)]]]], 200),
        ]);

        $user = User::factory()->create(['monthly_credits' => 1, 'purchased_credits' => 0, 'credits' => 1]);
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->handle(app(GroqService::class), app(AiTextService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Không đủ credit', $fresh->error);
        $this->assertEquals(1, $user->fresh()->monthly_credits);
    }

    public function test_handle_marks_failed_and_cleans_up_when_user_missing(): void
    {
        $user = User::factory()->create();
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'queued', 'stage' => 'queued']);
        $path = $this->makeTempAudioFile();

        // Simulate the user having been deleted between dispatch and processing.
        // The srt_translate_jobs.user_id column has an "on delete cascade" FK, so
        // a normal delete would cascade and remove the job row too. Disable FK
        // enforcement for the delete so the job row is left dangling, pointing
        // at a user_id that no longer resolves via the belongsTo relation.
        DB::statement('PRAGMA foreign_keys = OFF');
        User::where('id', $user->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->assertNull($job->fresh()->user);

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->handle(app(GroqService::class), app(AiTextService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('User not found', $fresh->error);
        $this->assertFileDoesNotExist($path);
    }

    public function test_failed_method_marks_job_as_permanently_failed(): void
    {
        $user = User::factory()->create();
        $job = SrtTranslateJob::create(['user_id' => $user->id, 'target_language' => 'vi', 'status' => 'processing', 'stage' => 'translating']);
        $path = $this->makeTempAudioFile();

        (new ProcessSrtTranslate($job, $path, 'audio.mp3', ['target_language' => 'vi']))
            ->failed(new \RuntimeException('queue worker crashed'));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('queue worker crashed', $fresh->error);
    }
}
