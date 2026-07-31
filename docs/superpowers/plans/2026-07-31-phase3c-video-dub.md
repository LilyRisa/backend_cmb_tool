# Phase 3C: Video Dub Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the full audio→STT→translate→TTS "video dubbing" background pipeline by orchestrating services already built in Phases 3A/3B, plus an admin UI foundation (Bootstrap shell) and a management page for video-dub jobs.

**Architecture:** `ProcessVideoDub` (a `ShouldQueue` job) chains four already-shipped services in sequence — `GroqService::transcribe()` (STT) → `SrtChunkTranslationService::translate()` via `OpenRouterService` (chunked LLM translation) → `SrtTimeRedistributionService::redistribute()` (retiming) → `SrtParserService::sanitizeSrt()` (junk removal) — then hands the final SRT to `GenMaxService::textToSpeechSrt()`, which pre-deducts credits atomically and submits a single TTS request covering the whole SRT. The client polls `GET /api/tool/video-dub/status/{id}`, which polls `GenMaxService::getTaskStatus()` once per request; a scheduled `dub:cleanup-stale` command finalizes jobs whose clients stopped polling. No new external API integration — every provider call in this phase goes through a service Phase 3A or 3B already built and tested.

**Tech Stack:** Laravel 10 (from Phases 1-3B), `ShouldQueue` (tested via direct `handle()` calls with `Http::fake()`, matching Phase 3B's convention — not `Queue::fake()`, since we want to verify the job's actual business logic), Blade views for the new admin UI (Bootstrap 5 + Font Awesome via CDN — no build step, no npm dependency, matching this project's zero-frontend-tooling posture so far).

## Global Constraints

- Every inline `throttle:` middleware MUST carry an explicit, unique 3rd segment (e.g. `throttle:3,1,video-dub`) — Laravel's default throttle key is only user-ID/IP, and a bare `throttle:N,M` collides across routes. This bug class has recurred multiple times in this project; the source project's own `/video-dub` route uses a bare `throttle:3,1` — do NOT port that verbatim, add the 3rd segment.
- Money/content safety: nothing paywalled may be persisted into a job row and exposed via an API/status response before the corresponding credit deduction has actually succeeded. (This phase's design avoids the failure mode entirely — `GenMaxService::textToSpeechSrt()` pre-deducts before ever creating a `TtsHistory` row — but double-check this invariant in every task that touches `VideoDubJob`'s fields.)
- All new job/queue code targets the `database` queue connection already wired up in Phase 3B's final fix wave (`jobs` table migration + `.env.example`'s `QUEUE_CONNECTION=database`) — no new queue infrastructure needed here, only a scheduled `queue:work --stop-when-empty` drain (Task 4) matching the source project's actual production mechanism.
- Vietnamese user-facing error strings, matching every other controller in this project (`'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'`, `'Không tìm thấy job'`, etc.).
- New admin Blade views must not introduce a JS/CSS build step — Bootstrap 5 and Font Awesome via `<link>`/`<script>` CDN tags only, matching the fact that no `package.json`/frontend bundler exists anywhere in this project yet.
- The `admin.analytics.user` route referenced by the source project's video-dub Blade views does NOT exist in this project (`UserAnalyticsController` has not been built) — every task touching those views must replace that link with plain (non-linked) text, not port it verbatim.

---

### Task 1: `VideoDubJob` model, migration, and factory

**Files:**
- Create: `database/migrations/2026_07_31_000005_create_video_dub_jobs_table.php`
- Create: `app/Models/VideoDubJob.php`
- Create: `database/factories/VideoDubJobFactory.php`
- Test: `tests/Unit/VideoDubJobTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (Phase 1), `App\Models\TtsHistory` (Phase 3A).
- Produces: `VideoDubJob` model with fillable columns `user_id, target_language, voice_id, provider, model_id, voice_settings, source_language, srt_original, srt_translated, status, stage, error, characters_used, credits_deducted, audio_url, audio_urls, duration_seconds, tts_task_ids`; casts `voice_settings`/`tts_task_ids`/`audio_urls` to `array`, `characters_used`/`credits_deducted`/`duration_seconds` to `integer`; relation `user(): BelongsTo`; methods `getTtsHistories(): \Illuminate\Support\Collection`, `allTtsCompleted(): bool`, `hasFailedTts(): bool`, `getCompletedAudioUrls(): array`. Consumed by `ProcessVideoDub` (Task 2), `VideoDubController` (Task 3), `CleanupStaleDubJobs` (Task 4), `VideoDubManagementController` (Task 6).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/VideoDubJobTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoDubJobTest`
Expected: FAIL (`Class "App\Models\VideoDubJob" not found` / migration table missing)

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_31_000005_create_video_dub_jobs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_dub_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Pipeline config
            $table->string('target_language');
            $table->string('voice_id');
            $table->string('provider')->default('elevenlabs');
            $table->string('model_id')->nullable();
            $table->json('voice_settings')->nullable();

            // Results
            $table->string('source_language')->nullable();
            $table->longText('srt_original')->nullable();
            $table->longText('srt_translated')->nullable();

            // Status tracking
            $table->string('status')->default('queued'); // queued, processing, tts_pending, completed, failed
            $table->string('stage')->default('queued');  // queued, transcribing, translating, tts, done
            $table->text('error')->nullable();

            // Credits
            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted')->default(0);

            // TTS results
            $table->string('audio_url')->nullable();
            $table->json('audio_urls')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Link to TtsHistory rows
            $table->json('tts_task_ids')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_dub_jobs');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/VideoDubJob.php`:

```php
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
```

- [ ] **Step 5: Write the factory**

Create `database/factories/VideoDubJobFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoDubJobFactory extends Factory
{
    protected $model = VideoDubJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_language' => 'vi',
            'voice_id' => 'voice_test',
            'provider' => 'elevenlabs',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=VideoDubJobTest`
Expected: PASS (8 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_31_000005_create_video_dub_jobs_table.php app/Models/VideoDubJob.php database/factories/VideoDubJobFactory.php tests/Unit/VideoDubJobTest.php
git commit -m "Add VideoDubJob model, migration, and factory"
```

---

### Task 2: `ProcessVideoDub` job

**Files:**
- Create: `app/Jobs/ProcessVideoDub.php`
- Test: `tests/Feature/Jobs/ProcessVideoDubTest.php`

**Interfaces:**
- Consumes: `VideoDubJob` (Task 1); `GroqService::transcribe(\Illuminate\Http\UploadedFile $file, ?string $language = null): string` (Phase 3B); `SrtChunkTranslationService::translate(string $srtContent, string $targetLanguage, callable $translator): string` (Phase 3B); `OpenRouterService::translate(string $chunk, string $lang, string $format, string $context): string` (Phase 3B); `SrtTimeRedistributionService::redistribute(string $srtContent): string` (Phase 3B); `SrtParserService::sanitizeSrt(string $srtContent): string` (Phase 3A); `GenMaxService::textToSpeechSrt(User $user, string $voiceId, string $srtContent, array $params): array` (Phase 3A — returns `['success' => bool, 'status' => int, 'data' => [...]]`; on success `data['id']` is the new `TtsHistory` row id, `data['total_characters']`, `data['credits_deducted']`).
- Produces: `ProcessVideoDub` constructed as `new ProcessVideoDub(VideoDubJob $dubJob, string $audioFilePath, string $audioFileName, array $params)` where `$params` has keys `target_language`, `voice_id`, `provider`, `model_id`, `voice_settings`. `handle(GroqService $groq, OpenRouterService $openRouter, GenMaxService $genMax): void`. Consumed by `VideoDubController` (Task 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessVideoDubTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessVideoDubTest`
Expected: FAIL (`Class "App\Jobs\ProcessVideoDub" not found`)

- [ ] **Step 3: Write the job**

Create `app/Jobs/ProcessVideoDub.php`:

```php
<?php

namespace App\Jobs;

use App\Models\VideoDubJob;

use App\Services\GenMaxService;
use App\Services\GroqService;
use App\Services\OpenRouterService;
use App\Services\SrtChunkTranslationService;
use App\Services\SrtParserService;
use App\Services\SrtTimeRedistributionService;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoDub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No auto-retry — a retry after TTS submission would double-charge, since
    // GenMaxService::textToSpeechSrt() has already deducted credits by then.
    public int $tries = 1;

    // Allow up to 10 minutes for the full STT + chunked-translate + TTS-submit pipeline.
    public int $timeout = 600;

    protected VideoDubJob $dubJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected array $params;

    public function __construct(
        VideoDubJob $dubJob,
        string $audioFilePath,
        string $audioFileName,
        array $params
    ) {
        $this->dubJob = $dubJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->params = $params;
    }

    public function handle(
        GroqService $groq,
        OpenRouterService $openRouter,
        GenMaxService $genMax,
    ): void {
        $job = $this->dubJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            $this->cleanup();
            return;
        }

        // ── Step 1: Whisper STT ──────────────────────────────────────────
        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $fakeFile = new \Illuminate\Http\UploadedFile(
                $this->audioFilePath,
                $this->audioFileName,
                null,
                null,
                true // test mode — skip is_uploaded_file check
            );
            $srtOriginal = $groq->transcribe($fakeFile);
        } catch (\Throwable $e) {
            Log::error('VideoDub STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_original' => $srtOriginal,
            'stage' => 'translating',
        ]);

        // ── Step 2: Translate SRT (chunked to avoid LLM truncation) ─────
        try {
            $chunkTranslator = app(SrtChunkTranslationService::class);
            $srtTranslated = $chunkTranslator->translate(
                $srtOriginal,
                $this->params['target_language'],
                fn(string $chunk, string $lang, string $context = '') => $openRouter->translate($chunk, $lang, 'srt', $context)
            );
        } catch (\Throwable $e) {
            Log::error('VideoDub translate failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'translating', 'error' => 'Translation failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        // ── Step 2.5: Redistribute SRT timing ─────────────────────────
        // Borrow time from neighbouring gaps so longer translated segments
        // get the extra seconds they need for natural TTS. Non-fatal.
        try {
            $redistributor = app(SrtTimeRedistributionService::class);
            $srtTranslated = $redistributor->redistribute($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT redistribution skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        // ── Step 2.6: Sanitize junk subtitles ─────────────────────────
        // Remove entries with no real spoken content (e.g. ".", "...", pure
        // punctuation) that would cause TTS to fail. Non-fatal.
        try {
            $srtParser = app(SrtParserService::class);
            $srtTranslated = $srtParser->sanitizeSrt($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        $job->update([
            'srt_translated' => $srtTranslated,
            'stage' => 'tts',
        ]);

        // ── Step 3: Submit TTS with full SRT (single request) ────────────
        $ttsParams = array_filter([
            'model_id' => $this->params['model_id'] ?? null,
            'provider' => $this->params['provider'] ?? 'elevenlabs',
            'language_code' => $this->params['target_language'],
            'voice_settings' => $this->params['voice_settings'] ?? null,
        ]);

        $ttsResult = $genMax->textToSpeechSrt(
            $user,
            $this->params['voice_id'],
            $srtTranslated,
            $ttsParams
        );

        if (!($ttsResult['success'] ?? false)) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => $ttsResult['data']['error'] ?? 'TTS submission failed',
            ]);
            $this->cleanup();
            return;
        }

        $ttsTaskId = $ttsResult['data']['id'] ?? null;

        if (!$ttsTaskId) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'TTS returned no task ID',
            ]);
            $this->cleanup();
            return;
        }

        $job->update([
            'status' => 'tts_pending',
            'stage' => 'tts',
            'tts_task_ids' => [$ttsTaskId],
            'characters_used' => $ttsResult['data']['total_characters'] ?? 0,
            'credits_deducted' => $ttsResult['data']['credits_deducted'] ?? 0,
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessVideoDub job failed permanently', [
            'job_id' => $this->dubJob->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->dubJob->update([
            'status' => 'failed',
            'error' => 'Pipeline crashed: ' . ($exception?->getMessage() ?? 'Unknown error'),
        ]);

        $this->cleanup();
    }

    protected function cleanup(): void
    {
        if (file_exists($this->audioFilePath)) {
            @unlink($this->audioFilePath);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ProcessVideoDubTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessVideoDub.php tests/Feature/Jobs/ProcessVideoDubTest.php
git commit -m "Add ProcessVideoDub job orchestrating STT, translation, retiming, and TTS submission"
```

---

### Task 3: `VideoDubController` (dub + status) and API routes

**Files:**
- Create: `app/Http/Controllers/API/VideoDubController.php`
- Modify: `routes/api.php` (add inside the existing `tool` prefix group)
- Test: `tests/Feature/Tool/VideoDubControllerTest.php`

**Interfaces:**
- Consumes: `VideoDubJob` (Task 1), `ProcessVideoDub` (Task 2), `GenMaxService::getTaskStatus(User $user, int $historyId): array` (Phase 3A — returns `['success' => bool, 'status' => int, 'data' => [...]]`; on success `data['status']` is one of `pending|completed|failed`, `data['audio_url']` present when `completed`).
- Produces: `POST /api/tool/video-dub` (throttled `throttle:3,1,video-dub`, `email.verified`, premium-gated, zero-credit-gated), `GET /api/tool/video-dub/status/{id}`. Consumed by end users and by `CleanupStaleDubJobs`'s test coverage pattern (Task 4 reuses `GenMaxService::getTaskStatus`, not this controller directly).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/VideoDubControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
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
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'stage' => 'tts',
            'tts_task_ids' => [999],
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoDubControllerTest`
Expected: FAIL (`Class "App\Http\Controllers\API\VideoDubController" not found` / route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/VideoDubController.php`. Note this project's established C1 fix pattern (Phase 3B final review): gate every paywalled field in the status response on `status === 'completed'`, and pre-check the user has any credits before dispatching:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVideoDub;
use App\Models\VideoDubJob;
use App\Services\GenMaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoDubController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    /**
     * POST /api/tool/video-dub
     *
     * Validate input, create job record, dispatch background worker.
     * Returns job_id immediately for client polling via status().
     */
    public function dub(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a',
            'target_language' => 'required|string|max:10',
            'voice_id' => 'required|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'model_id' => 'nullable|string',
            'voice_settings' => 'nullable|array',
            'voice_settings.stability' => 'nullable|numeric|between:0,1',
            'voice_settings.similarity_boost' => 'nullable|numeric|between:0,1',
            'voice_settings.style' => 'nullable|numeric|between:0,1',
            'voice_settings.use_speaker_boost' => 'nullable|boolean',
            'voice_settings.speed' => 'nullable|numeric|between:0.5,2.0',
            'voice_settings.pitch' => 'nullable|integer|between:-12,12',
            'voice_settings.vol' => 'nullable|numeric|between:0.01,10',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        // Pre-check for zero credits before spending any provider budget on
        // STT/translation (Phase 3B's paywall-bypass fix pattern) — the exact
        // TTS cost isn't known until GenMaxService::textToSpeechSrt() runs, so
        // this only blocks users who have nothing to spend at all.
        if ($user->monthly_credits + $user->purchased_credits <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'Không đủ credit để thực hiện thao tác này.',
                'credits_available' => $user->monthly_credits + $user->purchased_credits,
            ], 402);
        }

        $file = $request->file('file');
        $tempPath = $file->store('video-dub-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        try {
            $job = VideoDubJob::create([
                'user_id' => $user->id,
                'target_language' => $request->input('target_language'),
                'voice_id' => $request->input('voice_id'),
                'provider' => $request->input('provider', 'elevenlabs'),
                'model_id' => $request->input('model_id'),
                'voice_settings' => $request->input('voice_settings'),
                'status' => 'queued',
                'stage' => 'queued',
            ]);

            $params = [
                'target_language' => $request->input('target_language'),
                'voice_id' => $request->input('voice_id'),
                'provider' => $request->input('provider', 'elevenlabs'),
                'model_id' => $request->input('model_id'),
                'voice_settings' => $request->input('voice_settings'),
            ];

            ProcessVideoDub::dispatch($job, $fullTempPath, $file->getClientOriginalName(), $params);
        } catch (\Throwable $e) {
            if (file_exists($fullTempPath)) {
                @unlink($fullTempPath);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/video-dub/status/' . $job->id . ' for progress.',
        ], 202);
    }

    /**
     * GET /api/tool/video-dub/status/{id}
     *
     * Poll the status of a video dubbing job. When the linked TTS task
     * completes, finalizes the job with the audio URL.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = VideoDubJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        if (in_array($job->status, ['queued', 'processing', 'completed', 'failed'])) {
            return response()->json($this->formatJobResponse($job));
        }

        // Status is 'tts_pending' — poll the single linked TTS task.
        $ttsTaskIds = $job->tts_task_ids ?? [];

        if (empty($ttsTaskIds)) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'TTS produced no task — submission may have failed',
            ]);
            return response()->json($this->formatJobResponse($job));
        }

        $historyId = $ttsTaskIds[0];
        $taskResult = $this->genMax->getTaskStatus($user, $historyId);

        $taskStatus = 'pending';
        $taskData = [];

        if ($taskResult['success'] ?? false) {
            $taskData = $taskResult['data'];
            $taskStatus = $taskData['status'] ?? 'pending';
        }

        if ($taskStatus === 'completed') {
            $audioUrl = $taskData['audio_url'] ?? null;
            $duration = $this->estimateDurationFromSrt($job->srt_translated ?? $job->srt_original);

            $job->update([
                'status' => 'completed',
                'stage' => 'done',
                'audio_url' => $audioUrl,
                'audio_urls' => $audioUrl ? [$audioUrl] : [],
                'duration_seconds' => $duration,
            ]);
        } elseif ($taskStatus === 'failed') {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => $taskData['error'] ?? 'TTS task failed',
            ]);
        }
        // else: still pending — return current state.

        $response = $this->formatJobResponse($job->fresh());

        $response['tts_progress'] = [
            'completed' => in_array($taskStatus, ['completed', 'failed']) ? 1 : 0,
            'total' => 1,
        ];

        return response()->json($response);
    }

    protected function formatJobResponse(VideoDubJob $job): array
    {
        $isCompleted = $job->status === 'completed';

        return [
            'success' => $job->status !== 'failed',
            'job_id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'is_final' => in_array($job->status, ['completed', 'failed']),
            'target_language' => $job->target_language,
            'characters_used' => $job->characters_used,
            'credits_deducted' => $job->credits_deducted,
            'audio_url' => $isCompleted ? $job->audio_url : null,
            'audio_urls' => $isCompleted ? $job->audio_urls : null,
            'srt_original' => $isCompleted ? $job->srt_original : null,
            'srt_translated' => $isCompleted ? $job->srt_translated : null,
            'duration_seconds' => $job->duration_seconds,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    protected function estimateDurationFromSrt(?string $srt): int
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
}
```

Note: unlike the source project (which gates `srt_original`/`srt_translated`/`audio_url` only implicitly by never writing them until the `completed` update), this version explicitly nulls them in `formatJobResponse()` whenever `status !== 'completed'` — applying Phase 3B's C1 fix pattern proactively so a `failed` job (e.g. one that failed mid-`tts_pending` after `audio_url` briefly held a value from a since-superseded flow) never leaks paywalled content.

- [ ] **Step 4: Register the routes**

In `routes/api.php`, add the import near the other `API\` controller imports:

```php
use App\Http\Controllers\API\VideoDubController;
```

And inside the existing `Route::prefix('tool')->middleware([...])->group(function () { ... });` block, after the `/translate-srt` routes:

```php
    Route::post('/video-dub', [VideoDubController::class, 'dub'])->middleware(['throttle:3,1,video-dub', 'email.verified']);
    Route::get('/video-dub/status/{id}', [VideoDubController::class, 'status'])->where('id', '[0-9]+');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=VideoDubControllerTest`
Expected: PASS (8 tests)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/VideoDubController.php routes/api.php tests/Feature/Tool/VideoDubControllerTest.php
git commit -m "Add VideoDubController with dub/status endpoints"
```

---

### Task 4: `dub:cleanup-stale` command and scheduler wiring

**Files:**
- Create: `app/Console/Commands/CleanupStaleDubJobs.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Feature/Console/CleanupStaleDubJobsTest.php`

**Interfaces:**
- Consumes: `VideoDubJob` (Task 1), `GenMaxService::getTaskStatus()` (Phase 3A).
- Produces: Artisan command `dub:cleanup-stale`, scheduled `everyFiveMinutes()`. Also adds a scheduled `queue:work --stop-when-empty --tries=1 --timeout=600` drain (`everyMinute()->withoutOverlapping()`) — this project's queue driver is `database` (Phase 3B's final fix wave), and this is the source project's actual mechanism for running queued jobs without a separately-managed supervisor process.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/CleanupStaleDubJobsTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\SystemSetting;
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
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [42],
            'updated_at' => now()->subMinutes(35),
        ]);

        $this->artisan('dub:cleanup-stale')->assertExitCode(0);

        $fresh = $job->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertEquals('https://cdn/done.mp3', $fresh->audio_url);
    }

    public function test_marks_job_failed_when_tts_task_failed(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['status' => 'failed', 'error' => 'provider error'], 200)]);

        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'tts_pending',
            'tts_task_ids' => [42],
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CleanupStaleDubJobsTest`
Expected: FAIL (`Command "dub:cleanup-stale" is not defined`)

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/CleanupStaleDubJobs.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\VideoDubJob;
use App\Services\GenMaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleDubJobs extends Command
{
    protected $signature = 'dub:cleanup-stale';
    protected $description = 'Poll and finalize orphaned video-dub jobs that clients stopped polling';

    /** Jobs older than this (minutes) will be polled for finalization. */
    const STALE_AFTER_MINUTES = 30;

    /** Jobs older than this (minutes) will be force-marked as timed out. */
    const TIMEOUT_AFTER_MINUTES = 120;

    public function handle(GenMaxService $genMax): int
    {
        $staleJobs = VideoDubJob::where('status', 'tts_pending')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->with('user')
            ->limit(50)
            ->get();

        if ($staleJobs->isEmpty()) {
            $this->info('No stale jobs found.');
            return self::SUCCESS;
        }

        $this->info("Found {$staleJobs->count()} stale job(s). Processing...");

        foreach ($staleJobs as $job) {
            $this->processStaleJob($job, $genMax);
        }

        return self::SUCCESS;
    }

    protected function processStaleJob(VideoDubJob $job, GenMaxService $genMax): void
    {
        $user = $job->user;
        if (!$user) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'User not found (deleted?)',
            ]);
            Log::warning("Stale dub job #{$job->id}: user not found");
            return;
        }

        $ttsTaskIds = $job->tts_task_ids ?? [];

        if ($job->updated_at->lt(now()->subMinutes(self::TIMEOUT_AFTER_MINUTES))) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'Job timed out after ' . self::TIMEOUT_AFTER_MINUTES . ' minutes',
            ]);
            // NO REFUND — the TTS task was already created and credits consumed.
            Log::info("Stale dub job #{$job->id}: force timeout, no refund (TTS tasks exist)", [
                'tts_tasks' => count($ttsTaskIds),
            ]);
            return;
        }

        $allCompleted = true;
        $anyFailed = false;
        $audioUrls = [];

        foreach ($ttsTaskIds as $historyId) {
            try {
                $result = $genMax->getTaskStatus($user, $historyId);
            } catch (\Throwable $e) {
                $allCompleted = false;
                continue;
            }

            if (!($result['success'] ?? false)) {
                $allCompleted = false;
                continue;
            }

            $status = $result['data']['status'] ?? 'pending';

            if ($status === 'failed') {
                $anyFailed = true;
            } elseif ($status === 'completed') {
                if (!empty($result['data']['audio_url'])) {
                    $audioUrls[] = $result['data']['audio_url'];
                }
            } else {
                $allCompleted = false;
            }
        }

        if ($allCompleted && !empty($ttsTaskIds)) {
            if ($anyFailed && empty($audioUrls)) {
                $job->update([
                    'status' => 'failed',
                    'stage' => 'done',
                    'error' => 'All TTS tasks failed (finalized by cron)',
                ]);
            } else {
                $job->update([
                    'status' => 'completed',
                    'stage' => 'done',
                    'audio_url' => $audioUrls[0] ?? null,
                    'audio_urls' => $audioUrls,
                ]);
            }

            $this->line("  Job #{$job->id}: finalized as {$job->status}");
            Log::info("Stale dub job #{$job->id} finalized", [
                'status' => $job->status,
                'audio_urls' => count($audioUrls),
            ]);
        } else {
            $this->line("  Job #{$job->id}: still pending, will retry next run");
        }
    }
}
```

- [ ] **Step 4: Schedule the command and the queue drain**

In `app/Console/Kernel.php`, inside `schedule()`, add (the existing `$schedule->call(...)` prunes stay unchanged):

```php
        // Finalize video-dub jobs whose client stopped polling before the
        // linked TTS task finished — see CleanupStaleDubJobs for the
        // 30-minute stale / 120-minute force-timeout thresholds.
        $schedule->command('dub:cleanup-stale')->everyFiveMinutes();

        // This project's queue driver is 'database' (no persistent supervisor
        // process configured) — draining it on a schedule, rather than via a
        // long-running `queue:work` process, matches how the source project
        // actually runs its queue in production.
        $schedule->command('queue:work --stop-when-empty --tries=1 --timeout=600')
            ->everyMinute()
            ->withoutOverlapping();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CleanupStaleDubJobsTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CleanupStaleDubJobs.php app/Console/Kernel.php tests/Feature/Console/CleanupStaleDubJobsTest.php
git commit -m "Add dub:cleanup-stale command and schedule it alongside a queue:work drain"
```

---

### Task 5: `admin.layout` Bootstrap shell + breadcrumb partial

**Files:**
- Create: `resources/views/admin/layout.blade.php`
- Create: `resources/views/admin/_partials/_breadcrumb.blade.php`
- Test: `tests/Feature/Admin/AdminLayoutTest.php`

**Interfaces:**
- Produces: a Blade layout usable via `@extends('admin.layout')` with sections `title`, `page-title`, `breadcrumb`, `content`; a breadcrumb partial included via `@include('admin._partials._breadcrumb', ['items' => [['label' => '...', 'url' => '...'], ['label' => '... (current, no url)']]])`. Consumed by `VideoDubManagementController`'s views (Task 6) and every future admin page.

This is a foundational shell with no controller logic — the test renders it directly via Blade to confirm it compiles and the sections/partial work, rather than hitting a route.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/AdminLayoutTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    public function test_layout_renders_page_title_and_content(): void
    {
        $html = view('admin.dashboard', [
            'totalUsers' => 1,
            'premiumUsers' => 1,
            'newUsersToday' => 1,
        ])->render();

        // Existing dashboard view is intentionally untouched (Global
        // Constraints: minimize blast radius) — this just proves the
        // rendering pipeline still works after adding the new layout files
        // alongside it.
        $this->assertStringContainsString('Admin Dashboard', $html);
    }

    public function test_breadcrumb_partial_renders_linked_and_current_items(): void
    {
        $html = view('admin._partials._breadcrumb', [
            'items' => [
                ['label' => 'Video Dub Jobs', 'url' => '/admin/videodub'],
                ['label' => 'Job #5'],
            ],
        ])->render();

        $this->assertStringContainsString('Video Dub Jobs', $html);
        $this->assertStringContainsString('/admin/videodub', $html);
        $this->assertStringContainsString('Job #5', $html);
    }

    public function test_layout_extends_correctly_with_a_throwaway_page(): void
    {
        $blade = <<<'BLADE'
@extends('admin.layout')
@section('title', 'Test Page')
@section('page-title', 'Test Page')
@section('content')
<p>hello from test page</p>
@endsection
BLADE;

        \Illuminate\Support\Facades\File::put(resource_path('views/admin/_test_throwaway.blade.php'), $blade);

        $html = view('admin._test_throwaway')->render();

        \Illuminate\Support\Facades\File::delete(resource_path('views/admin/_test_throwaway.blade.php'));

        $this->assertStringContainsString('Test Page', $html);
        $this->assertStringContainsString('hello from test page', $html);
        $this->assertStringContainsString('bootstrap', strtolower($html));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminLayoutTest`
Expected: FAIL (`View [admin._partials._breadcrumb] not found`, and the throwaway-page test fails the same way for `admin.layout`)

- [ ] **Step 3: Write the breadcrumb partial**

Create `resources/views/admin/_partials/_breadcrumb.blade.php`:

```blade
<nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
        @foreach($items as $item)
            @if(!$loop->last && isset($item['url']))
                <li class="breadcrumb-item"><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
            @else
                <li class="breadcrumb-item active" aria-current="page">{{ $item['label'] }}</li>
            @endif
        @endforeach
    </ol>
</nav>
```

- [ ] **Step 4: Write the layout**

Create `resources/views/admin/layout.blade.php`:

```blade
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — CMB Core Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('admin.dashboard') }}">CMB Core Tool — Admin</a>
            <div class="d-flex">
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="mb-3">
            @yield('breadcrumb')
        </div>

        <h3 class="mb-4">@yield('page-title', 'Admin')</h3>

        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AdminLayoutTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add resources/views/admin/layout.blade.php resources/views/admin/_partials/_breadcrumb.blade.php tests/Feature/Admin/AdminLayoutTest.php
git commit -m "Add admin.layout Bootstrap shell and breadcrumb partial for future admin pages"
```

---

### Task 6: `VideoDubManagementController` + Blade views + web routes

**Files:**
- Create: `app/Http/Controllers/Admin/VideoDubManagementController.php`
- Create: `resources/views/admin/videodub/index.blade.php`
- Create: `resources/views/admin/videodub/detail.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/VideoDubManagementControllerTest.php`

**Interfaces:**
- Consumes: `VideoDubJob` (Task 1), `admin.layout` + `admin._partials._breadcrumb` (Task 5), the existing `IsAdmin` middleware (`app/Http/Middleware/IsAdmin.php`, aliased `admin` in `routes/web.php`'s `Route::middleware('admin')` group, Phase 1) that gates the rest of the `admin.` route group — it checks the default `web` guard's `auth()->user()->is_admin` boolean column, redirecting guests to `admin.login` and aborting 403 for authenticated non-admins.
- Produces: `GET /admin/videodub` (named `admin.videodub.index`), `GET /admin/videodub/{id}` (named `admin.videodub.show`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/VideoDubManagementControllerTest.php`, following the exact authentication pattern already established in `tests/Feature/Admin/AdminLoginTest.php` (Phase 1) — default `web` guard via `actingAs($admin)`, `is_admin => true` on the `User` factory, no separate `admin` guard exists:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDubManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_jobs_with_stats(): void
    {
        $user = User::factory()->create();
        VideoDubJob::factory()->count(2)->create(['user_id' => $user->id, 'status' => 'completed', 'credits_deducted' => 10, 'characters_used' => 100]);
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'failed']);

        $response = $this->actingAsAdmin()->get('/admin/videodub');

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return $stats['total'] === 3 && $stats['completed'] === 2 && $stats['failed'] === 1;
        });
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'failed']);

        $response = $this->actingAsAdmin()->get('/admin/videodub?status=failed');

        $response->assertOk();
        $jobs = $response->viewData('jobs');
        $this->assertCount(1, $jobs);
        $this->assertEquals('failed', $jobs->first()->status);
    }

    public function test_show_displays_job_detail_with_linked_tts_stats(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'characters_used' => 50, 'credits_deducted_user' => 5]);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $response = $this->actingAsAdmin()->get("/admin/videodub/{$job->id}");

        $response->assertOk();
        $response->assertViewHas('ttsStats', function ($stats) {
            return $stats['total'] === 1 && $stats['completed'] === 1 && $stats['total_characters'] === 50 && $stats['total_credits'] === 5;
        });
    }

    public function test_show_404s_for_unknown_job(): void
    {
        $this->actingAsAdmin()->get('/admin/videodub/999999')->assertStatus(404);
    }

    public function test_index_and_show_reject_unauthenticated_requests(): void
    {
        $this->get('/admin/videodub')->assertRedirect();

        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create(['user_id' => $user->id]);
        $this->get("/admin/videodub/{$job->id}")->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoDubManagementControllerTest`
Expected: FAIL (`Class "App\Http\Controllers\Admin\VideoDubManagementController" not found` / route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/VideoDubManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoDubJob;
use Illuminate\Http\Request;

class VideoDubManagementController extends Controller
{
    /**
     * GET /admin/videodub
     * List all video dub jobs with stats, filters, sorts
     */
    public function index(Request $request)
    {
        $total = VideoDubJob::count();
        $completed = VideoDubJob::where('status', 'completed')->count();
        $failed = VideoDubJob::where('status', 'failed')->count();
        $processing = VideoDubJob::whereNotIn('status', ['completed', 'failed'])->count();
        $totalCredits = VideoDubJob::sum('credits_deducted');
        $totalCharacters = VideoDubJob::sum('characters_used');

        $stats = [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'processing' => $processing,
            'total_credits' => $totalCredits,
            'total_characters' => $totalCharacters,
        ];

        $query = VideoDubJob::with('user')->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'processing') {
                $query->whereNotIn('status', ['completed', 'failed']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('user_search')) {
            $search = $request->user_search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('language')) {
            $query->where('target_language', $request->language);
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['created_at', 'credits_deducted', 'characters_used', 'duration_seconds'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $jobs = $query->paginate(20)->appends($request->query());

        $languages = VideoDubJob::distinct()->pluck('target_language')->filter()->sort()->values();

        return view('admin.videodub.index', compact('jobs', 'stats', 'languages'));
    }

    /**
     * GET /admin/videodub/{id}
     * Show detail of a single video dub job
     */
    public function show(int $id)
    {
        $job = VideoDubJob::with('user')->findOrFail($id);

        $ttsHistories = $job->getTtsHistories();

        $ttsStats = [
            'total' => $ttsHistories->count(),
            'completed' => $ttsHistories->where('status', 'completed')->count(),
            'failed' => $ttsHistories->where('status', 'failed')->count(),
            'pending' => $ttsHistories->whereNotIn('status', ['completed', 'failed'])->count(),
            'total_characters' => $ttsHistories->sum('characters_used'),
            'total_credits' => $ttsHistories->sum('credits_deducted_user'),
        ];

        return view('admin.videodub.detail', compact('job', 'ttsHistories', 'ttsStats'));
    }
}
```

- [ ] **Step 4: Write the index view**

Create `resources/views/admin/videodub/index.blade.php` — same structure and data usage as the source project's version (stats cards, filter form, paginated table), rebuilt on this project's `admin.layout`/breadcrumb partial instead of the source's, and with the "User" column showing a plain badge with no link (this project has no `admin.analytics.user` route yet):

```blade
@extends('admin.layout')

@section('title', 'Video Dub Jobs')
@section('page-title', 'Video Dub Jobs')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Video Dub Jobs']]])
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Tổng Jobs</div>
                <div class="h4 mb-0 fw-bold text-primary">{{ number_format($stats['total']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Đang xử lý</div>
                <div class="h4 mb-0 fw-bold text-warning">{{ number_format($stats['processing']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Hoàn thành</div>
                <div class="h4 mb-0 fw-bold text-success">{{ number_format($stats['completed']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Thất bại</div>
                <div class="h4 mb-0 fw-bold text-danger">{{ number_format($stats['failed']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Credits</div>
                <div class="h4 mb-0 fw-bold text-info">{{ number_format($stats['total_credits']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Characters</div>
                <div class="h4 mb-0 fw-bold" style="color:#6c5ce7">{{ number_format($stats['total_characters']) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Trạng thái</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all" {{ request('status','all')==='all'?'selected':'' }}>Tất cả</option>
                    <option value="processing" {{ request('status')==='processing'?'selected':'' }}>Đang xử lý</option>
                    <option value="completed" {{ request('status')==='completed'?'selected':'' }}>Hoàn thành</option>
                    <option value="failed" {{ request('status')==='failed'?'selected':'' }}>Thất bại</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Tìm user</label>
                <input type="text" name="user_search" class="form-control form-control-sm" placeholder="Tên hoặc email..." value="{{ request('user_search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Ngôn ngữ đích</label>
                <select name="language" class="form-select form-select-sm">
                    <option value="">Tất cả</option>
                    @foreach($languages as $lang)
                    <option value="{{ $lang }}" {{ request('language')===$lang?'selected':'' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Sắp xếp</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="created_at" {{ request('sort','created_at')==='created_at'?'selected':'' }}>Ngày tạo</option>
                    <option value="credits_deducted" {{ request('sort')==='credits_deducted'?'selected':'' }}>Credits</option>
                    <option value="characters_used" {{ request('sort')==='characters_used'?'selected':'' }}>Characters</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-search"></i> Lọc</button>
                <a href="{{ route('admin.videodub.index') }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-redo"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-language"></i> Video Dub Jobs ({{ $jobs->total() }})</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Languages</th>
                        <th>Voice</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Characters</th>
                        <th>Credits</th>
                        <th>Duration</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                    <tr>
                        <td><code>{{ $job->id }}</code></td>
                        <td>
                            @if($job->user)
                            <span class="badge bg-info">{{ $job->user->name }}</span>
                            @else
                            <span class="badge bg-secondary">Deleted</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-secondary">{{ $job->source_language ?? '?' }}</span>
                            <i class="fas fa-arrow-right text-muted mx-1" style="font-size:10px"></i>
                            <span class="badge bg-primary">{{ $job->target_language }}</span>
                        </td>
                        <td>
                            <small class="text-muted">{{ Str::limit($job->voice_id, 20) }}</small>
                            <br><span class="badge bg-light text-dark" style="font-size:10px">{{ $job->provider }}</span>
                        </td>
                        <td>
                            @php
                            $statusColors = ['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'tts_pending' => 'info'];
                            $color = $statusColors[$job->status] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $color }}">{{ $job->status }}</span>
                        </td>
                        <td><small>{{ $job->stage }}</small></td>
                        <td>{{ number_format($job->characters_used) }}</td>
                        <td>
                            @if($job->credits_deducted > 0)
                            <span class="badge bg-warning text-dark">{{ $job->credits_deducted }}</span>
                            @else
                            <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td>
                            @if($job->duration_seconds)
                            {{ gmdate('H:i:s', $job->duration_seconds) }}
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $job->created_at->format('d/m/Y H:i') }}</small></td>
                        <td>
                            <a href="{{ route('admin.videodub.show', $job->id) }}" class="btn btn-sm btn-outline-primary" title="Chi tiết">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="text-center py-5">
                            <i class="fas fa-language text-muted" style="font-size:3rem"></i>
                            <p class="text-muted mt-2">Chưa có video dub job nào</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($jobs->hasPages())
    <div class="card-footer">
        {{ $jobs->links() }}
    </div>
    @endif
</div>
@endsection
```

- [ ] **Step 5: Write the detail view**

Create `resources/views/admin/videodub/detail.blade.php` — same as the source's version, minus the `admin.analytics.user` link (shown as plain text with a name/email instead):

```blade
@extends('admin.layout')

@section('title', 'Video Dub Job #' . $job->id)
@section('page-title', 'Chi tiết Job #' . $job->id)

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [
['label' => 'Video Dub Jobs', 'url' => route('admin.videodub.index')],
['label' => 'Job #' . $job->id]
]])
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-language"></i> Job Information</h5>
                @php
                $statusColors = ['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'tts_pending' => 'info'];
                $color = $statusColors[$job->status] ?? 'secondary';
                @endphp
                <span class="badge bg-{{ $color }} fs-6">{{ strtoupper($job->status) }}</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" width="40%">User</th>
                                <td>
                                    @if($job->user)
                                    {{-- No link: admin.analytics.user isn't built in this project yet --}}
                                    <strong>{{ $job->user->name }}</strong>
                                    <br><small class="text-muted">{{ $job->user->email }}</small>
                                    @else
                                    <span class="text-muted">Deleted user</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Source Language</th>
                                <td><span class="badge bg-secondary">{{ $job->source_language ?? 'Auto-detect' }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Target Language</th>
                                <td><span class="badge bg-primary">{{ $job->target_language }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Stage</th>
                                <td><span class="badge bg-info">{{ $job->stage }}</span></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" width="40%">Voice</th>
                                <td><code>{{ $job->voice_id }}</code></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Provider</th>
                                <td><span class="badge bg-dark">{{ $job->provider }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Model</th>
                                <td>{{ $job->model_id ?? 'Default' }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Created</th>
                                <td>{{ $job->created_at->format('d/m/Y H:i:s') }}</td>
                            </tr>
                            @if($job->updated_at && $job->updated_at != $job->created_at)
                            <tr>
                                <th class="text-muted">Updated</th>
                                <td>{{ $job->updated_at->format('d/m/Y H:i:s') }}</td>
                            </tr>
                            @endif
                        </table>
                    </div>
                </div>

                @if($job->error)
                <div class="alert alert-danger mt-3 mb-0">
                    <strong><i class="fas fa-exclamation-triangle"></i> Lỗi:</strong>
                    <pre class="mb-0 mt-1" style="white-space:pre-wrap;font-size:12px">{{ $job->error }}</pre>
                </div>
                @endif

                @if($job->voice_settings)
                <div class="mt-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-sliders-h"></i> Voice Settings</h6>
                    <pre class="bg-light p-2 rounded" style="font-size:11px;max-height:100px;overflow:auto">{{ json_encode($job->voice_settings, JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-coins"></i> Credits Summary</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Characters Used</span>
                    <strong>{{ number_format($job->characters_used) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Credits Deducted</span>
                    <strong class="text-warning">{{ number_format($job->credits_deducted) }}</strong>
                </div>
                @if($job->duration_seconds)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Duration</span>
                    <strong>{{ gmdate('H:i:s', $job->duration_seconds) }}</strong>
                </div>
                @endif
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">TTS Tasks</span>
                    <strong>{{ $ttsStats['total'] }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Completed</span>
                    <span class="badge bg-success">{{ $ttsStats['completed'] }}</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Failed</span>
                    <span class="badge bg-danger">{{ $ttsStats['failed'] }}</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Pending</span>
                    <span class="badge bg-warning">{{ $ttsStats['pending'] }}</span>
                </div>
            </div>
        </div>

        @if($job->audio_url)
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-volume-up"></i> Final Audio</h6>
            </div>
            <div class="card-body">
                <audio controls class="w-100" preload="metadata">
                    <source src="{{ $job->audio_url }}">
                </audio>
                <a href="{{ $job->audio_url }}" target="_blank" class="btn btn-sm btn-outline-primary mt-2 w-100">
                    <i class="fas fa-external-link-alt"></i> Open URL
                </a>
            </div>
        </div>
        @endif
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-file-alt"></i> SRT Original</h6>
            </div>
            <div class="card-body p-0">
                @if($job->srt_original)
                <pre class="p-3 mb-0" style="max-height:400px;overflow:auto;font-size:11px;background:#f8f9fa">{{ $job->srt_original }}</pre>
                @else
                <div class="text-center text-muted py-4">Không có SRT gốc</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-language"></i> SRT Translated</h6>
            </div>
            <div class="card-body p-0">
                @if($job->srt_translated)
                <pre class="p-3 mb-0" style="max-height:400px;overflow:auto;font-size:11px;background:#f8f9fa">{{ $job->srt_translated }}</pre>
                @else
                <div class="text-center text-muted py-4">Chưa có bản dịch</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($ttsHistories->isNotEmpty())
<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-tasks"></i> Linked TTS Tasks ({{ $ttsHistories->count() }})</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ID</th>
                        <th>Text</th>
                        <th>Characters</th>
                        <th>Credits</th>
                        <th>Status</th>
                        <th>Audio</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ttsHistories as $idx => $tts)
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td><code>{{ $tts->id }}</code></td>
                        <td>
                            <small title="{{ $tts->text ?? '' }}">{{ Str::limit($tts->text ?? '', 60) }}</small>
                        </td>
                        <td>{{ number_format($tts->characters_used ?? 0) }}</td>
                        <td>
                            @if(($tts->credits_deducted_user ?? 0) > 0)
                            <span class="badge bg-warning text-dark">{{ $tts->credits_deducted_user }}</span>
                            @else
                            <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td>
                            @php
                            $ttsColor = match($tts->status ?? 'unknown') {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'processing' => 'warning',
                                default => 'secondary',
                            };
                            @endphp
                            <span class="badge bg-{{ $ttsColor }}">{{ $tts->status ?? 'N/A' }}</span>
                        </td>
                        <td>
                            @if($tts->audio_url)
                            <a href="{{ $tts->audio_url }}" target="_blank" class="btn btn-sm btn-outline-info" title="Play">
                                <i class="fas fa-play"></i>
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $tts->created_at ? $tts->created_at->format('H:i:s') : 'N/A' }}</small></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="mt-3">
    <a href="{{ route('admin.videodub.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Quay lại danh sách
    </a>
</div>
@endsection
```

- [ ] **Step 6: Register the web routes**

In `routes/web.php`, add the import near the top:

```php
use App\Http\Controllers\Admin\VideoDubManagementController;
```

And inside the existing `Route::prefix('admin')->name('admin.')->group(...)`'s `Route::middleware('admin')->group(function () { ... });` block, alongside `dashboard`:

```php
        Route::get('/videodub', [VideoDubManagementController::class, 'index'])->name('videodub.index');
        Route::get('/videodub/{id}', [VideoDubManagementController::class, 'show'])->name('videodub.show');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=VideoDubManagementControllerTest`
Expected: PASS (5 tests)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/VideoDubManagementController.php resources/views/admin/videodub routes/web.php tests/Feature/Admin/VideoDubManagementControllerTest.php
git commit -m "Add VideoDubManagementController with admin index/detail views"
```

---

## What's Next

After Phase 3C, the remaining scope from the original design spec is Phase 3D (Script/Scene generation via AI, Stock media search via Pexels) and, separately, the data-migration Artisan commands (`export:marketing-data` from the source project, plus a matching `import:marketing-data` here) and the new OpenAI-compatible image-generation API endpoint — both still deferred, not yet scheduled as their own phase.
