# Phase 3A: TTS & Voice Cloning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add text-to-speech generation (via the GenMax API — a third-party TTS provider) and voice cloning to the Laravel 10 backend at `D:\cmbcoremkt_backend`, with credit pre-deduction/reconciliation/refund tied into Phase 1/2's credit system.

**Architecture:** `GenMaxService` wraps HTTP calls to `https://api.genmax.io` (an external paid TTS API). Credits are pre-deducted before submitting a job (to prevent a user racing multiple submissions past their balance), then reconciled (refunded or additionally charged) once the provider reports the actual character count consumed. `TtsHistory` rows track job state locally and are polled by the client via `GET /api/tool/tts/status/{id}`. A 5-hour cache short-circuits identical repeat requests. `SrtParserService` (a small, standalone SRT-file parser) is ported here because the SRT-based TTS endpoint needs it — it will be reused unmodified by Phase 3B's SRT generate/translate pipeline.

**Tech Stack:** Laravel 10 (from Phases 1-2), `Illuminate\Support\Facades\Http` with `Http::fake()` for testing external API calls, Laravel's cache facade.

## Global Constraints

- Builds directly on Phases 1-2's `D:\cmbcoremkt_backend` — same DB, same `User`/`CreditTransaction`/`SystemSetting`/`CreditService` models. Do not modify already-tested behavior except where a task explicitly extends an existing file.
- All GenMax API calls in tests MUST use `Http::fake()` — never make a real network call to `api.genmax.io` in a test.
- All work committed to git in small, working increments — one commit per task minimum.
- Continue committing directly to `master` (approved by the human partner for this project).
- Docker is this project's real dev/deploy environment; tests run against in-memory SQLite — no local MySQL needed.
- The GenMax API key is a per-deployment secret configured via `SystemSetting` (admin-settable, encrypted at rest), following the exact same `SystemSetting::getValue()`/`setValue()` pattern already used for `genmax_api_key` conceptually in the source project — do not put it in `.env`/`config/services.php`.

---

### Task 1: `SystemSetting::getGenMaxApiKey()` + `TtsHistory` model + migration

**Files:**
- Modify: `app/Models/SystemSetting.php` (add `getGenMaxApiKey()`/`setGenMaxApiKey()`)
- Create: `database/migrations/2026_07_30_000012_create_tts_histories_table.php`
- Create: `app/Models/TtsHistory.php`
- Create: `database/factories/TtsHistoryFactory.php`
- Test: `tests/Unit/TtsHistoryModelTest.php`

**Interfaces:**
- Produces: `SystemSetting::getGenMaxApiKey(): ?string`, `SystemSetting::setGenMaxApiKey(string $apiKey): static`; `TtsHistory` model with the fillable/cast/scopes below.
- Consumed by: `GenMaxService` (Tasks 3-4).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TtsHistoryModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TtsHistoryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_genmax_api_key_roundtrips_encrypted(): void
    {
        SystemSetting::setGenMaxApiKey('sk-test-12345');

        $this->assertEquals('sk-test-12345', SystemSetting::getGenMaxApiKey());
        $this->assertDatabaseMissing('system_settings', ['value' => 'sk-test-12345']);
    }

    public function test_genmax_api_key_returns_null_when_unset(): void
    {
        $this->assertNull(SystemSetting::getGenMaxApiKey());
    }

    public function test_tts_history_belongs_to_user_and_casts_voice_settings(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'voice_settings' => ['stability' => 0.5, 'style' => 0.2],
        ]);

        $this->assertEquals($user->id, $history->user->id);
        $this->assertIsArray($history->voice_settings);
        $this->assertEquals(0.5, $history->voice_settings['stability']);
    }

    public function test_scope_completed_filters_by_status(): void
    {
        TtsHistory::factory()->create(['status' => 'completed']);
        TtsHistory::factory()->create(['status' => 'pending']);

        $this->assertEquals(1, TtsHistory::completed()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TtsHistoryModelTest`
Expected: FAIL — `SystemSetting::getGenMaxApiKey` and `TtsHistory` don't exist.

- [ ] **Step 3: Add the GenMax API key methods to `SystemSetting`**

Add to `app/Models/SystemSetting.php` (append inside the class, after `getPremiumMonthlyCredits()`):

```php
    public static function getGenMaxApiKey(): ?string
    {
        return static::getValue('genmax_api_key');
    }

    public static function setGenMaxApiKey(string $apiKey): static
    {
        return static::setValue(
            'genmax_api_key',
            $apiKey,
            true,
            'GenMax TTS Provider API Key'
        );
    }
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_07_30_000012_create_tts_histories_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('genmax_task_id')->nullable()->index();
            $table->string('provider')->default('elevenlabs');
            $table->string('voice_id');
            $table->string('model_id')->nullable();
            $table->text('text');
            $table->string('language_code')->nullable();
            $table->json('voice_settings')->nullable();
            $table->string('status')->default('pending');
            $table->integer('progress')->default(0);
            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted_provider')->default(0);
            $table->integer('credits_deducted_user')->default(0);
            $table->string('audio_url')->nullable();
            $table->text('error')->nullable();
            $table->boolean('is_credit_deducted')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_histories');
    }
};
```

- [ ] **Step 5: Write the model**

Create `app/Models/TtsHistory.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TtsHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'genmax_task_id', 'provider', 'voice_id', 'model_id',
        'text', 'language_code', 'voice_settings', 'status', 'progress',
        'characters_used', 'credits_deducted_provider', 'credits_deducted_user',
        'audio_url', 'error', 'is_credit_deducted',
    ];

    protected $casts = [
        'voice_settings' => 'array',
        'is_credit_deducted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creditTransactions()
    {
        return $this->morphMany(CreditTransaction::class, 'reference');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
```

- [ ] **Step 6: Write the factory**

Create `database/factories/TtsHistoryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TtsHistoryFactory extends Factory
{
    protected $model = TtsHistory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'genmax_task_id' => 'task_' . $this->faker->uuid(),
            'provider' => 'elevenlabs',
            'voice_id' => 'voice_abc123',
            'text' => 'Hello world, this is a test.',
            'status' => 'pending',
            'credits_deducted_user' => 10,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=TtsHistoryModelTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS (116 tests — 112 from Phase 2 + 4 new).

- [ ] **Step 9: Commit**

```bash
git add app/Models/SystemSetting.php app/Models/TtsHistory.php database/migrations/2026_07_30_000012_create_tts_histories_table.php database/factories/TtsHistoryFactory.php tests/Unit/TtsHistoryModelTest.php
git commit -m "Add TtsHistory model and SystemSetting::getGenMaxApiKey()"
```

---

### Task 2: `SrtParserService`

**Files:**
- Create: `app/Services/SrtParserService.php`
- Test: `tests/Unit/SrtParserServiceTest.php`

**Interfaces:**
- Produces: `SrtParserService::parse(string $content): array{entries: array, total_characters: int}` (throws `\InvalidArgumentException` on invalid/empty SRT), `::sanitizeSrt(string $srtContent): string`.
- Consumed by: `ToolTtsController` (Task 5), and will be reused unmodified by Phase 3B's SRT pipeline.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SrtParserServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SrtParserService;
use Tests\TestCase;

class SrtParserServiceTest extends TestCase
{
    private SrtParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SrtParserService();
    }

    public function test_parse_extracts_entries_with_index_timing_and_text(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nHello world.\n\n2\n00:00:05,000 --> 00:00:07,500\nSecond line.\n";

        $result = $this->parser->parse($srt);

        $this->assertCount(2, $result['entries']);
        $this->assertEquals(1, $result['entries'][0]['index']);
        $this->assertEquals('00:00:01,000', $result['entries'][0]['start']);
        $this->assertEquals('00:00:04,000', $result['entries'][0]['end']);
        $this->assertEquals('Hello world.', $result['entries'][0]['text']);
        $this->assertEquals('Second line.', $result['entries'][1]['text']);
        $this->assertEquals(mb_strlen('Hello world.') + mb_strlen('Second line.'), $result['total_characters']);
    }

    public function test_parse_strips_html_tags_and_multiline_text(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\n<i>Line one</i>\nLine two\n";

        $result = $this->parser->parse($srt);

        $this->assertEquals('Line one Line two', $result['entries'][0]['text']);
    }

    public function test_parse_throws_on_content_with_no_valid_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->parser->parse("this is not an srt file at all");
    }

    public function test_parse_handles_bom_and_crlf_line_endings(): void
    {
        $srt = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,000\r\nHi.\r\n";

        $result = $this->parser->parse($srt);

        $this->assertCount(1, $result['entries']);
        $this->assertEquals('Hi.', $result['entries'][0]['text']);
    }

    public function test_sanitize_srt_removes_punctuation_only_entries_and_renumbers(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nReal text.\n\n2\n00:00:03,000 --> 00:00:04,000\n...\n\n3\n00:00:05,000 --> 00:00:06,000\nMore text.\n";

        $sanitized = $this->parser->sanitizeSrt($srt);
        $reparsed = $this->parser->parse($sanitized);

        $this->assertCount(2, $reparsed['entries']);
        $this->assertEquals(1, $reparsed['entries'][0]['index']);
        $this->assertEquals(2, $reparsed['entries'][1]['index']);
        $this->assertEquals('Real text.', $reparsed['entries'][0]['text']);
        $this->assertEquals('More text.', $reparsed['entries'][1]['text']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SrtParserServiceTest`
Expected: FAIL — `App\Services\SrtParserService` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/SrtParserService.php`:

```php
<?php

namespace App\Services;

class SrtParserService
{
    public function parse(string $content): array
    {
        $content = $this->normalizeLineEndings($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $blocks = preg_split('/\n\s*\n/', trim($content));
        $entries = [];
        $totalCharacters = 0;

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }

            $entry = $this->parseBlock($block);
            if ($entry !== null) {
                $entries[] = $entry;
                $totalCharacters += mb_strlen($entry['text']);
            }
        }

        if (empty($entries)) {
            throw new \InvalidArgumentException('File SRT không hợp lệ hoặc không chứa subtitle nào.');
        }

        return [
            'entries' => $entries,
            'total_characters' => $totalCharacters,
        ];
    }

    protected function parseBlock(string $block): ?array
    {
        $lines = explode("\n", $block);

        if (count($lines) < 3) {
            return null;
        }

        $index = (int) trim($lines[0]);
        if ($index <= 0) {
            return null;
        }

        $timecode = trim($lines[1]);
        if (!preg_match('/^\d{2}:\d{2}:\d{2}[,.]\d{3}\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d{3}/', $timecode)) {
            return null;
        }

        [$start, $end] = array_map('trim', explode('-->', $timecode));

        $textLines = array_slice($lines, 2);
        $text = implode(' ', array_map('trim', $textLines));
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (empty($text)) {
            return null;
        }

        return [
            'index' => $index,
            'start' => trim($start),
            'end' => trim($end),
            'text' => $text,
        ];
    }

    public function sanitizeSrt(string $srtContent): string
    {
        $parsed = $this->parse($srtContent);
        $cleaned = [];
        $removed = [];

        foreach ($parsed['entries'] as $entry) {
            $stripped = preg_replace('/[\p{P}\p{S}\s]+/u', '', $entry['text']);

            if (mb_strlen($stripped) === 0) {
                $removed[] = "#{$entry['index']}: \"{$entry['text']}\"";
                continue;
            }

            $cleaned[] = $entry;
        }

        if (!empty($removed)) {
            \Illuminate\Support\Facades\Log::info('[SrtParser] Sanitized junk subtitles', [
                'removed_count' => count($removed),
                'removed' => $removed,
                'remaining' => count($cleaned),
            ]);
        }

        $blocks = [];
        foreach ($cleaned as $i => $entry) {
            $index = $i + 1;
            $blocks[] = "{$index}\n{$entry['start']} --> {$entry['end']}\n{$entry['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }

    protected function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SrtParserServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (121 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SrtParserService.php tests/Unit/SrtParserServiceTest.php
git commit -m "Add SrtParserService for parsing SRT subtitle files"
```

---

### Task 3: `GenMaxService` — core TTS submit + status polling

**Files:**
- Create: `app/Services/GenMaxService.php`
- Test: `tests/Unit/GenMaxServiceTest.php`

**Interfaces:**
- Produces: `GenMaxService::textToSpeech(User $user, string $voiceId, array $params): array` (returns `['success' => bool, 'status' => int, 'data' => array]`), `::getTaskStatus(User $user, int $historyId): array`.
- Consumes: `SystemSetting::getGenMaxApiKey()` (Task 1), `CreditService::estimate()`/`creditsToMinutes()`/`charactersToCredits()` (Phase 1/2), `User::deductCredits()`/`addCredits()` (Phase 1), `TtsHistory` (Task 1).
- Consumed by: `ToolTtsController` (Task 5), Task 4 (extends this same class).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/GenMaxServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use App\Services\GenMaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenMaxServiceTest extends TestCase
{
    use RefreshDatabase;

    private GenMaxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
        $this->service = new GenMaxService();
    }

    private function premiumUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ], $overrides));
    }

    public function test_text_to_speech_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello']);

        $this->assertFalse($result['success']);
        $this->assertEquals(403, $result['status']);
    }

    public function test_text_to_speech_rejects_insufficient_credits(): void
    {
        $user = $this->premiumUser(['monthly_credits' => 1, 'purchased_credits' => 0, 'credits' => 1]);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => str_repeat('a', 500)]);

        $this->assertEquals(402, $result['status']);
        $this->assertEquals(1, $user->fresh()->credits);
    }

    public function test_text_to_speech_pre_deducts_credits_and_creates_history_on_success(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['id' => 'genmax_task_123'], 200),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['status']);
        $this->assertEquals('pending', $result['data']['status']);
        $this->assertDatabaseHas('tts_histories', [
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_123',
            'voice_id' => 'voice_abc',
            'status' => 'pending',
        ]);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_refunds_credits_when_genmax_fails(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['error' => 'provider down'], 500),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertFalse($result['success']);
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_uses_cache_for_identical_repeat_request(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'genmax_task_1'], 200)
                ->push(['status' => 'completed', 'characters_used' => 11, 'result' => ['audio_url' => 'https://cdn/audio1.mp3']], 200),
        ]);
        $user = $this->premiumUser();

        $first = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);
        $this->service->getTaskStatus($user, $first['data']['id']);

        $creditsBeforeSecond = $user->fresh()->monthly_credits;
        $second = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($second['success']);
        $this->assertEquals(200, $second['status']);
        $this->assertTrue($second['data']['cached'] ?? false);
        $this->assertEquals($creditsBeforeSecond, $user->fresh()->monthly_credits);
    }

    public function test_get_task_status_returns_404_for_missing_history(): void
    {
        $user = $this->premiumUser();

        $result = $this->service->getTaskStatus($user, 999999);

        $this->assertEquals(404, $result['status']);
    }

    public function test_get_task_status_polls_genmax_and_marks_completed_with_refund(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_1',
            'text' => str_repeat('a', 100),
            'credits_deducted_user' => 10,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 10);

        Http::fake([
            'api.genmax.io/*' => Http::response([
                'status' => 'completed',
                'progress' => 100,
                'characters_used' => 50,
                'result' => ['audio_url' => 'https://cdn.genmax.io/audio/1.mp3'],
            ], 200),
        ]);

        $result = $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('completed', $result['data']['status']);
        $this->assertEquals('https://cdn.genmax.io/audio/1.mp3', $result['data']['audio_url']);
        $this->assertTrue($history->fresh()->is_credit_deducted);
        $this->assertGreaterThan(990, $user->fresh()->monthly_credits);
    }

    public function test_get_task_status_refunds_all_credits_on_failure(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_2',
            'credits_deducted_user' => 15,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 15);

        Http::fake([
            'api.genmax.io/*' => Http::response(['status' => 'failed', 'error' => 'synthesis error'], 200),
        ]);

        $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(1000, $user->fresh()->monthly_credits);
        $this->assertEquals('failed', $history->fresh()->status);
        $this->assertEquals(0, $history->fresh()->credits_deducted_user);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenMaxServiceTest`
Expected: FAIL — `App\Services\GenMaxService` does not exist.

- [ ] **Step 3: Write the service (core part — the rest is added in Task 4)**

Create `app/Services/GenMaxService.php`:

```php
<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenMaxService
{
    protected string $baseUrl = 'https://api.genmax.io';
    protected int $charsPerMinute;

    protected const VOICE_SETTINGS_MAP = [
        'minimax' => ['speed', 'pitch', 'vol'],
        'elevenlabs' => ['stability', 'similarity_boost', 'style', 'use_speaker_boost'],
    ];

    private const TTS_CACHE_TTL = 5 * 60 * 60;

    public function __construct()
    {
        $this->charsPerMinute = SystemSetting::getCharsPerMinute();
    }

    protected function getApiKey(): string
    {
        $key = SystemSetting::getGenMaxApiKey();

        if (!$key) {
            throw new \RuntimeException('GenMax API key not configured. Please set it in Admin > Tool Settings.');
        }

        return $key;
    }

    protected function request(string $method, string $endpoint, array $data = [], array $query = [])
    {
        $request = Http::withHeaders([
            'xi-api-key' => $this->getApiKey(),
        ])->timeout(30);

        $url = $this->baseUrl . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $data),
                'DELETE' => $request->delete($url),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
                'headers' => $response->headers(),
            ];
        } catch (\Exception $e) {
            Log::error('GenMax API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'data' => ['error' => 'Lỗi kết nối tới nhà cung cấp dịch vụ: ' . $e->getMessage()],
                'headers' => [],
            ];
        }
    }

    protected function requestMultipart(string $endpoint, array $multipart)
    {
        try {
            $request = Http::withHeaders([
                'xi-api-key' => $this->getApiKey(),
            ])->timeout(60);

            foreach ($multipart as $field) {
                if (isset($field['file'])) {
                    $request = $request->attach($field['name'], $field['file'], $field['filename'] ?? null);
                }
            }

            $formData = [];
            foreach ($multipart as $field) {
                if (!isset($field['file'])) {
                    $formData[$field['name']] = $field['value'];
                }
            }

            $response = $request->post($this->baseUrl . $endpoint, $formData);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('GenMax API multipart request failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return [
                'success' => false,
                'status' => 500,
                'data' => ['error' => 'Lỗi kết nối: ' . $e->getMessage()],
            ];
        }
    }

    public function textToSpeech(User $user, string $voiceId, array $params): array
    {
        $text = $params['text'] ?? '';

        $cacheKey = $this->buildTtsCacheKey($voiceId, $params);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return ['success' => true, 'status' => 200, 'data' => $cached];
        }

        $estimate = CreditService::estimate($text);
        $estimatedCredits = $estimate['credits'];

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        $deducted = $user->deductCredits(
            $estimatedCredits,
            "TTS pre-deduct: " . mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : ''),
            'tts_pre_deduct',
            null
        );

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng',
                    'minutes_required' => CreditService::creditsToMinutes($estimatedCredits, $this->charsPerMinute),
                    'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $this->charsPerMinute),
                    'credits_required' => $estimatedCredits,
                    'credits_available' => $user->credits,
                ],
            ];
        }

        $requestBody = ['text' => $text];
        if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
        if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
        if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

        if (!empty($params['voice_settings'])) {
            $requestBody['voice_settings'] = $this->sanitizeVoiceSettings(
                $params['voice_settings'],
                $params['provider'] ?? 'elevenlabs'
            );
        }

        $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

        if (!$result['success']) {
            $user->addCredits($estimatedCredits, 'refund', 'TTS failed to submit — refund pre-deducted credits', 'tts_pre_deduct', null);
            return $result;
        }

        $taskId = $result['data']['id'] ?? null;

        $history = TtsHistory::create([
            'user_id' => $user->id,
            'genmax_task_id' => $taskId,
            'provider' => $params['provider'] ?? 'elevenlabs',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? null,
            'text' => $text,
            'language_code' => $params['language_code'] ?? null,
            'voice_settings' => $params['voice_settings'] ?? null,
            'status' => 'pending',
            'credits_deducted_user' => $estimatedCredits,
        ]);

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'id' => $history->id,
                'genmax_task_id' => $taskId,
                'status' => 'pending',
                'minutes_deducted' => CreditService::creditsToMinutes($estimatedCredits, $this->charsPerMinute),
                'credits_deducted' => $estimatedCredits,
            ],
        ];
    }

    public function getTaskStatus(User $user, int $historyId): array
    {
        $history = TtsHistory::where('id', $historyId)->where('user_id', $user->id)->first();

        if (!$history) {
            return ['success' => false, 'status' => 404, 'data' => ['error' => 'Không tìm thấy task']];
        }

        if (in_array($history->status, ['completed', 'failed'])) {
            return ['success' => true, 'status' => 200, 'data' => $this->formatHistoryResponse($history)];
        }

        if (!$history->genmax_task_id) {
            return ['success' => false, 'status' => 500, 'data' => ['error' => 'Không có task ID từ nhà cung cấp']];
        }

        $result = $this->request('GET', "/v1/history/{$history->genmax_task_id}");

        if (!$result['success']) {
            return $result;
        }

        $genMaxData = $result['data'];
        $newStatus = $genMaxData['status'] ?? $history->status;

        $updateData = [
            'status' => $newStatus,
            'progress' => $genMaxData['progress'] ?? $history->progress,
        ];

        if ($newStatus === 'completed') {
            $providerCharsUsed = $genMaxData['characters_used'] ?? ($genMaxData['credits_deducted'] ?? 0);
            $actualUserCredits = CreditService::charactersToCredits($providerCharsUsed);

            $updateData['characters_used'] = $genMaxData['characters_used'] ?? 0;
            $updateData['credits_deducted_provider'] = $providerCharsUsed;
            $updateData['audio_url'] = $genMaxData['result']['audio_url']
                ?? $genMaxData['audio_url']
                ?? $genMaxData['output']['audio_url']
                ?? null;

            if (!$history->is_credit_deducted) {
                $preDeducted = $history->credits_deducted_user;
                $diff = $preDeducted - $actualUserCredits;

                if ($diff > 0) {
                    $user->addCredits($diff, 'refund', "TTS credit adjustment (hoàn lại {$diff} credits)", 'tts_history', $history->id);
                    $updateData['credits_deducted_user'] = $actualUserCredits;
                } elseif ($diff < 0) {
                    $chargeSuccess = $user->deductCredits(abs($diff), "TTS credit adjustment (trừ thêm " . abs($diff) . " credits)", 'tts_history', $history->id);

                    if ($chargeSuccess) {
                        $updateData['credits_deducted_user'] = $actualUserCredits;
                    } else {
                        Log::warning('TTS underpayment charge failed — user has insufficient credits', [
                            'user_id' => $user->id,
                            'history_id' => $history->id,
                            'pre_deducted' => $preDeducted,
                            'actual_required' => $actualUserCredits,
                            'shortfall' => abs($diff),
                        ]);
                        $updateData['credits_deducted_user'] = $preDeducted;
                    }
                } else {
                    $updateData['credits_deducted_user'] = $actualUserCredits;
                }

                $updateData['is_credit_deducted'] = true;

                $ttsCacheKey = $this->buildTtsCacheKey($history->voice_id, [
                    'text' => $history->text,
                    'model_id' => $history->model_id,
                    'provider' => $history->provider,
                    'language_code' => $history->language_code,
                    'voice_settings' => $history->voice_settings,
                ]);

                Cache::put($ttsCacheKey, [
                    'id' => $history->id,
                    'status' => 'completed',
                    'audio_url' => $updateData['audio_url'],
                    'cached' => true,
                    'cached_at' => now()->toIso8601String(),
                    'characters_used' => $updateData['characters_used'] ?? 0,
                    'credits_deducted' => 0,
                    'minutes_deducted' => 0,
                ], self::TTS_CACHE_TTL);
            }
        }

        if ($newStatus === 'failed') {
            $updateData['error'] = $genMaxData['error'] ?? 'Unknown error';

            if (!$history->is_credit_deducted && $history->credits_deducted_user > 0) {
                $user->addCredits($history->credits_deducted_user, 'refund', "TTS failed - hoàn credits", 'tts_history', $history->id);
                $updateData['credits_deducted_user'] = 0;
                $updateData['is_credit_deducted'] = true;
            }
        }

        $history->update($updateData);
        $history->refresh();

        return ['success' => true, 'status' => 200, 'data' => $this->formatHistoryResponse($history)];
    }

    protected function formatHistoryResponse(TtsHistory $history): array
    {
        return [
            'id' => $history->id,
            'genmax_task_id' => $history->genmax_task_id,
            'status' => $history->status,
            'progress' => $history->progress,
            'provider' => $history->provider,
            'voice_id' => $history->voice_id,
            'model_id' => $history->model_id,
            'text' => $history->text,
            'language_code' => $history->language_code,
            'voice_settings' => $history->voice_settings,
            'characters_used' => $history->characters_used,
            'minutes_deducted' => CreditService::creditsToMinutes($history->credits_deducted_user ?? 0, $this->charsPerMinute),
            'credits_deducted_user' => $history->credits_deducted_user,
            'audio_url' => $history->audio_url,
            'error' => $history->error,
            'created_at' => $history->created_at?->toIso8601String(),
            'updated_at' => $history->updated_at?->toIso8601String(),
        ];
    }

    protected function sanitizeVoiceSettings(array $settings, string $provider): array
    {
        $allowedKeys = self::VOICE_SETTINGS_MAP[$provider] ?? [];

        if (empty($allowedKeys)) {
            return $settings;
        }

        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $value = $settings[$key];

                if ($key === 'use_speaker_boost') {
                    $filtered[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif ($key === 'pitch') {
                    $filtered[$key] = (int) $value;
                } else {
                    $filtered[$key] = is_numeric($value) ? (float) $value : $value;
                }
            }
        }

        return $filtered;
    }

    protected function buildTtsCacheKey(string $voiceId, array $params): string
    {
        $parts = [
            'text' => $params['text'] ?? '',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? '',
            'provider' => $params['provider'] ?? 'elevenlabs',
            'language_code' => $params['language_code'] ?? '',
            'voice_settings' => $params['voice_settings'] ?? [],
        ];

        if (is_array($parts['voice_settings'])) {
            ksort($parts['voice_settings']);
        }

        return 'tts_audio:' . md5(json_encode($parts, JSON_UNESCAPED_UNICODE));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenMaxServiceTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (130 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/GenMaxService.php tests/Unit/GenMaxServiceTest.php
git commit -m "Add GenMaxService core: TTS submission and status polling"
```

---

### Task 4: `GenMaxService` — SRT TTS, batch TTS, history, models/voices passthrough

**Files:**
- Modify: `app/Services/GenMaxService.php` (append methods to the existing class from Task 3)
- Test: `tests/Unit/GenMaxServiceTest.php` (append test cases to the existing file)

**Interfaces:**
- Produces: `GenMaxService::textToSpeechSrt(User $user, string $voiceId, string $srtContent, array $params): array`, `::textToSpeechBatch(User $user, string $voiceId, array $entries, array $params): array` (deprecated but still the method `ToolTtsController::generateFromSrt()` actually calls — see note below), `::getUserHistory(User $user, int $pageSize = 30, int $page = 1): array`, `::deleteHistory(User $user, int $historyId): array`, `::getModels(?string $provider = null): array`, `::getSystemVoices(array $filters = []): array`, `::getSystemVoicesClone(array $filters = []): array`, `::getClonedVoices(): array`, `::cloneVoice(array $multipart): array`, `::deleteVoice(string $voiceId): array`.
- Consumed by: `ToolTtsController`/`ToolVoiceController` (Tasks 5-6).

**Note on `textToSpeechBatch()` being "deprecated":** the original source project's docblock marks this method `@deprecated Use textToSpeechSrt() instead` (it sends N individual HTTP requests per SRT entry, hitting rate limits on large files), but the controller method that handles the actual `/api/tool/tts/srt/{voice_id}` route (`ToolTtsController::generateFromSrt()`, Task 5) still calls `textToSpeechBatch()`, not `textToSpeechSrt()` — this is a latent inconsistency in the source project (the newer method was written but the call site was never updated). Port `textToSpeechBatch()` faithfully since it's what the shipped endpoint actually uses; `textToSpeechSrt()` is ported too since it's part of the service's public interface, but nothing in this plan wires a route to it. Do not silently "fix" this by changing which method the controller calls — that's a product decision, not this task's job.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/GenMaxServiceTest.php` (append these methods to the existing test class from Task 3):

```php
    public function test_text_to_speech_srt_pre_deducts_based_on_text_only_characters(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_srt_1'], 200)]);
        $user = $this->premiumUser();
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHello.\n";

        $result = $this->service->textToSpeechSrt($user, 'voice_abc', $srt, []);

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['status']);
        $this->assertEquals(6, $result['data']['total_characters']); // "Hello." = 6 chars
    }

    public function test_text_to_speech_srt_rejects_non_premium(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeechSrt($user, 'voice_abc', "1\n00:00:01,000 --> 00:00:02,000\nHi.\n", []);

        $this->assertEquals(403, $result['status']);
    }

    public function test_text_to_speech_batch_creates_one_history_per_entry(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_batch_task'], 200)]);
        $user = $this->premiumUser();
        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'First'],
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => 'Second'],
        ];

        $result = $this->service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['tasks']);
        $this->assertDatabaseCount('tts_histories', 2);
    }

    public function test_text_to_speech_batch_refunds_only_failed_entries(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'ok_task'], 200)
                ->push(['error' => 'provider rejected'], 400),
        ]);
        $user = $this->premiumUser();
        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'Good entry'],
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => 'Bad entry'],
        ];

        $result = $this->service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertEquals('failed', $result['data']['tasks'][1]['status']);
        $this->assertGreaterThan(0, $result['data']['credits_refunded']);
        $this->assertDatabaseCount('tts_histories', 1);
    }

    public function test_get_user_history_returns_recent_paginated_records(): void
    {
        $user = $this->premiumUser();
        TtsHistory::factory()->count(3)->create(['user_id' => $user->id]);

        $result = $this->service->getUserHistory($user, 30, 1);

        $this->assertEquals(200, $result['status']);
        $this->assertCount(3, $result['data']['tasks']);
    }

    public function test_delete_history_removes_record_and_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'genmax_task_id' => 'task_to_delete']);

        $result = $this->service->deleteHistory($user, $history->id);

        $this->assertEquals(200, $result['status']);
        $this->assertDatabaseMissing('tts_histories', ['id' => $history->id]);
    }

    public function test_delete_history_404s_for_missing_or_other_users_record(): void
    {
        $user = $this->premiumUser();

        $result = $this->service->deleteHistory($user, 999999);

        $this->assertEquals(404, $result['status']);
    }

    public function test_get_models_passes_through_provider_query(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['models' => ['a', 'b']], 200)]);

        $result = $this->service->getModels('elevenlabs');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider=elevenlabs'));
    }

    public function test_clone_voice_sends_multipart_request(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_voice'], 200)]);

        $result = $this->service->cloneVoice([
            ['name' => 'voice_name', 'value' => 'My Voice'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('new_voice', $result['data']['voice_id']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenMaxServiceTest`
Expected: FAIL — the new methods don't exist yet.

- [ ] **Step 3: Append the remaining methods to `GenMaxService`**

Add to `app/Services/GenMaxService.php` (append inside the class, after `buildTtsCacheKey()` — this replaces the closing `}` of the class, so add these methods before it):

```php
    public function textToSpeechSrt(User $user, string $voiceId, string $srtContent, array $params): array
    {
        $textOnly = $this->extractTextFromSrt($srtContent);
        $estimatedCredits = CreditService::calculateCredits($textOnly);
        $totalCharacters = mb_strlen($textOnly);

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        if ($user->credits < $estimatedCredits) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng cho toàn bộ file SRT',
                    'credits_required' => $estimatedCredits,
                    'credits_available' => $user->credits,
                    'total_characters' => $totalCharacters,
                ],
            ];
        }

        $deducted = $user->deductCredits($estimatedCredits, "TTS SRT pre-deduct: {$totalCharacters} chars", 'tts_srt', null);

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => ['error' => 'Không đủ credit (race condition)', 'credits_required' => $estimatedCredits, 'credits_available' => $user->credits],
            ];
        }

        $requestBody = ['text' => $srtContent];
        if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
        if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
        if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

        if (!empty($params['voice_settings'])) {
            $requestBody['voice_settings'] = $this->sanitizeVoiceSettings($params['voice_settings'], $params['provider'] ?? 'elevenlabs');
        }

        $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

        if (!$result['success']) {
            $user->addCredits($estimatedCredits, 'refund', 'TTS SRT failed — refund pre-deducted credits: ' . ($result['data']['error'] ?? 'API error'), 'tts_srt', null);
            return $result;
        }

        $taskId = $result['data']['id'] ?? null;

        $history = TtsHistory::create([
            'user_id' => $user->id,
            'genmax_task_id' => $taskId,
            'provider' => $params['provider'] ?? 'elevenlabs',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? null,
            'text' => $srtContent,
            'language_code' => $params['language_code'] ?? null,
            'voice_settings' => $params['voice_settings'] ?? null,
            'status' => 'pending',
            'credits_deducted_user' => $estimatedCredits,
        ]);

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'id' => $history->id,
                'genmax_task_id' => $taskId,
                'status' => 'pending',
                'total_characters' => $totalCharacters,
                'credits_deducted' => $estimatedCredits,
            ],
        ];
    }

    protected function extractTextFromSrt(string $srt): string
    {
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);

        $blocks = preg_split('/\n\s*\n/', trim($srt));
        $texts = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) < 3) continue;

            $textLines = array_slice($lines, 2);
            $text = implode(' ', array_map('trim', $textLines));
            $text = strip_tags($text);
            $text = preg_replace('/\s+/', ' ', trim($text));

            if (!empty($text)) {
                $texts[] = $text;
            }
        }

        return implode(' ', $texts);
    }

    /**
     * @deprecated Use textToSpeechSrt() instead. This method sends N individual
     * requests which hits GenMax rate limits (40 req/min) for large SRT files.
     * Ported as-is because ToolTtsController::generateFromSrt() still calls it.
     */
    public function textToSpeechBatch(User $user, string $voiceId, array $entries, array $params): array
    {
        $totalCharacters = 0;
        $totalEstimatedCredits = 0;
        foreach ($entries as $entry) {
            $totalCharacters += mb_strlen($entry['text']);
            $totalEstimatedCredits += CreditService::calculateCredits($entry['text']);
        }

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        if ($user->credits < $totalEstimatedCredits) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng cho toàn bộ file SRT',
                    'minutes_required' => CreditService::creditsToMinutes($totalEstimatedCredits, $this->charsPerMinute),
                    'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $this->charsPerMinute),
                    'credits_required' => $totalEstimatedCredits,
                    'credits_available' => $user->credits,
                    'total_entries' => count($entries),
                    'total_characters' => $totalCharacters,
                ],
            ];
        }

        $deducted = $user->deductCredits($totalEstimatedCredits, "TTS SRT batch pre-deduct: {$totalCharacters} chars, " . count($entries) . " entries", 'tts_batch', null);

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => ['error' => 'Không đủ credit (race condition)', 'credits_required' => $totalEstimatedCredits, 'credits_available' => $user->credits],
            ];
        }

        $processedEntryIndices = [];
        $tasks = [];
        $creditsRefunded = 0;

        try {
            foreach ($entries as $idx => $entry) {
                $text = $entry['text'];
                $entryCredits = CreditService::calculateCredits($text);

                $requestBody = ['text' => $text];
                if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
                if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
                if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

                if (!empty($params['voice_settings'])) {
                    $requestBody['voice_settings'] = $this->sanitizeVoiceSettings($params['voice_settings'], $params['provider'] ?? 'elevenlabs');
                }

                $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

                $processedEntryIndices[] = $idx;

                if (!$result['success']) {
                    $user->addCredits($entryCredits, 'refund', "TTS SRT entry #{$entry['index']} failed: " . ($result['data']['error'] ?? 'API error'), 'tts_batch', null);
                    $creditsRefunded += $entryCredits;

                    $tasks[] = [
                        'srt_index' => $entry['index'],
                        'srt_start' => $entry['start'],
                        'srt_end' => $entry['end'],
                        'status' => 'failed',
                        'error' => $result['data']['error'] ?? 'Lỗi gửi tới nhà cung cấp',
                        'credits_refunded' => $entryCredits,
                    ];
                    continue;
                }

                $taskId = $result['data']['id'] ?? null;

                $history = TtsHistory::create([
                    'user_id' => $user->id,
                    'genmax_task_id' => $taskId,
                    'provider' => $params['provider'] ?? 'elevenlabs',
                    'voice_id' => $voiceId,
                    'model_id' => $params['model_id'] ?? null,
                    'text' => $text,
                    'language_code' => $params['language_code'] ?? null,
                    'voice_settings' => $params['voice_settings'] ?? null,
                    'status' => 'pending',
                    'credits_deducted_user' => $entryCredits,
                ]);

                $tasks[] = [
                    'id' => $history->id,
                    'genmax_task_id' => $taskId,
                    'srt_index' => $entry['index'],
                    'srt_start' => $entry['start'],
                    'srt_end' => $entry['end'],
                    'status' => 'pending',
                    'credits_deducted' => $entryCredits,
                ];
            }
        } catch (\Throwable $e) {
            $remainingCredits = 0;
            foreach ($entries as $idx => $entry) {
                if (!in_array($idx, $processedEntryIndices)) {
                    $remainingCredits += CreditService::calculateCredits($entry['text']);
                }
            }

            if ($remainingCredits > 0) {
                $user->addCredits($remainingCredits, 'refund', 'TTS SRT batch interrupted — refund unprocessed entries', 'tts_batch', null);
                $creditsRefunded += $remainingCredits;
            }

            Log::error('TTS SRT batch interrupted', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'processed' => count($processedEntryIndices),
                'total' => count($entries),
                'credits_refunded' => $remainingCredits,
            ]);
        }

        $actualDeducted = $totalEstimatedCredits - $creditsRefunded;

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'tasks' => $tasks,
                'total_entries' => count($entries),
                'minutes_deducted' => CreditService::creditsToMinutes($actualDeducted, $this->charsPerMinute),
                'total_credits_deducted' => $actualDeducted,
                'credits_refunded' => $creditsRefunded,
            ],
        ];
    }

    public function getUserHistory(User $user, int $pageSize = 30, int $page = 1): array
    {
        $histories = TtsHistory::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(48))
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'tasks' => $histories->map(fn($h) => $this->formatHistoryResponse($h))->toArray(),
                'has_more' => $histories->hasMorePages(),
                'total' => $histories->total(),
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
            ],
        ];
    }

    public function deleteHistory(User $user, int $historyId): array
    {
        $history = TtsHistory::where('id', $historyId)->where('user_id', $user->id)->first();

        if (!$history) {
            return ['success' => false, 'status' => 404, 'data' => ['error' => 'Không tìm thấy']];
        }

        if ($history->genmax_task_id) {
            $this->request('DELETE', "/v1/history/{$history->genmax_task_id}");
        }

        $history->delete();

        return ['success' => true, 'status' => 200, 'data' => ['message' => 'Đã xóa']];
    }

    public function getModels(?string $provider = null): array
    {
        $query = $provider ? ['provider' => $provider] : [];
        return $this->request('GET', '/v1/models', [], $query);
    }

    public function getSystemVoices(array $filters = []): array
    {
        return $this->request('GET', '/v1/minimax/system-voices', [], $filters);
    }

    public function getSystemVoicesClone(array $filters = []): array
    {
        return $this->request('GET', '/v1/minimax/voices/', [], $filters);
    }

    public function getClonedVoices(): array
    {
        return $this->request('GET', '/v1/minimax/voices');
    }

    public function cloneVoice(array $multipart): array
    {
        return $this->requestMultipart('/v1/minimax/voices/clone', $multipart);
    }

    public function deleteVoice(string $voiceId): array
    {
        return $this->request('DELETE', "/v1/minimax/voices/{$voiceId}");
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GenMaxServiceTest`
Expected: PASS (18 tests — 9 from Task 3 + 9 new).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (139 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/GenMaxService.php tests/Unit/GenMaxServiceTest.php
git commit -m "Add GenMaxService SRT TTS, batch TTS, history, and voice passthrough methods"
```

---

### Task 5: `ToolTtsController` + routes

**Files:**
- Create: `app/Http/Controllers/API/ToolTtsController.php`
- Modify: `routes/api.php` (add routes inside the existing `tool` group from Phase 2)
- Test: `tests/Feature/Tool/ToolTtsControllerTest.php`

**Interfaces:**
- Produces: `POST /api/tool/tts/{voice_id}`, `POST /api/tool/tts/srt/{voice_id}`, `GET /api/tool/tts/status/{id}`, `GET /api/tool/tts/history`, `DELETE /api/tool/tts/history/{id}`.
- Consumes: `GenMaxService`/`SrtParserService` (Tasks 2-4).

**Important route-ordering note:** in `routes/api.php`, the specific route `/tts/srt/{voice_id}` MUST be registered BEFORE the wildcard `/tts/{voice_id}` route, otherwise Laravel's router matches `/tts/srt/abc` against the wildcard pattern first and `srt` gets treated as a `voice_id`. The exact route block below already orders them correctly — do not reorder.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/ToolTtsControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolTtsControllerTest extends TestCase
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

    private function premiumUser(): User
    {
        return User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ]);
    }

    public function test_generate_submits_tts_and_returns_pending_task(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_1'], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/tts/voice_abc', ['text' => 'Hello world']);

        $response->assertStatus(202)->assertJsonPath('status', 'pending');
    }

    public function test_generate_validates_text_length(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/tts/voice_abc', ['text' => str_repeat('a', 10001)])
            ->assertStatus(422);
    }

    public function test_generate_from_srt_parses_file_and_creates_tasks(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_srt'], 200)]);
        $user = $this->premiumUser();
        $srtContent = "1\n00:00:01,000 --> 00:00:02,000\nHello.\n\n2\n00:00:03,000 --> 00:00:04,000\nWorld.\n";
        $file = UploadedFile::fake()->createWithContent('subtitles.srt', $srtContent);

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/tts/srt/voice_abc', ['file' => $file]);

        $response->assertStatus(202);
        $this->assertDatabaseCount('tts_histories', 2);
    }

    public function test_generate_from_srt_rejects_invalid_extension(): void
    {
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/tts/srt/voice_abc', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_status_returns_task_state(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'audio_url' => 'https://cdn/a.mp3']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/tts/status/{$history->id}");

        $response->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_history_returns_users_recent_tasks(): void
    {
        $user = $this->premiumUser();
        TtsHistory::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/tts/history');

        $response->assertOk()->assertJsonCount(2, 'tasks');
    }

    public function test_delete_history_removes_the_record(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id]);

        $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/tool/tts/history/{$history->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tts_histories', ['id' => $history->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolTtsControllerTest`
Expected: FAIL — 404 on `/api/tool/tts/voice_abc`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/ToolTtsController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GenMaxService;
use App\Services\SrtParserService;
use Illuminate\Http\Request;

class ToolTtsController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    public function generate(Request $request, string $voiceId)
    {
        $request->validate([
            'text' => 'required|string|max:10000',
            'model_id' => 'nullable|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'language_code' => 'nullable|string',
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

        $result = $this->genMax->textToSpeech($user, $voiceId, $request->only([
            'text', 'model_id', 'provider', 'language_code', 'voice_settings',
        ]));

        return response()->json($result['data'], $result['status']);
    }

    public function generateFromSrt(Request $request, string $voiceId)
    {
        $request->validate([
            'file' => 'required|file|max:512|mimes:srt,txt',
            'model_id' => 'nullable|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'language_code' => 'nullable|string',
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
        $file = $request->file('file');

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['srt', 'txt'])) {
            return response()->json(['error' => 'File phải có định dạng .srt hoặc .txt'], 422);
        }

        $parser = new SrtParserService();
        try {
            $parsed = $parser->parse($file->get());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $result = $this->genMax->textToSpeechBatch(
            $user,
            $voiceId,
            $parsed['entries'],
            $request->only(['model_id', 'provider', 'language_code', 'voice_settings'])
        );

        return response()->json($result['data'], $result['status']);
    }

    public function status(Request $request, int $id)
    {
        $result = $this->genMax->getTaskStatus($request->user(), $id);

        return response()->json($result['data'], $result['status']);
    }

    public function history(Request $request)
    {
        $pageSize = min((int) $request->get('page_size', 30), 100);
        $page = max((int) $request->get('page', 1), 1);

        $result = $this->genMax->getUserHistory($request->user(), $pageSize, $page);

        return response()->json($result['data'], $result['status']);
    }

    public function deleteHistory(Request $request, int $id)
    {
        $result = $this->genMax->deleteHistory($request->user(), $id);

        return response()->json($result['data'], $result['status']);
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php` (IMPORTANT: `/tts/srt/{voice_id}` and `/tts/status/{id}` and `/tts/history` MUST come before the wildcard `/tts/{voice_id}` route):

```php
use App\Http\Controllers\API\ToolTtsController;

    Route::post('/tts/srt/{voice_id}', [ToolTtsController::class, 'generateFromSrt'])->middleware(['throttle:5,1,tts-srt', 'email.verified']);
    Route::get('/tts/status/{id}', [ToolTtsController::class, 'status']);
    Route::get('/tts/history', [ToolTtsController::class, 'history']);
    Route::delete('/tts/history/{id}', [ToolTtsController::class, 'deleteHistory']);
    Route::post('/tts/{voice_id}', [ToolTtsController::class, 'generate'])->where('voice_id', '^(?!srt$).+')->middleware('email.verified');
```

(Note: the throttle key includes an explicit `tts-srt` third segment — Phase 2's final review found that Laravel's throttle middleware keys unauthenticated-signature-independent requests only by user-ID/IP when no third segment is given, causing unrelated routes to share a rate-limit bucket. Every inline `throttle:` in this project gets an explicit prefix per that finding.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ToolTtsControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (146 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/ToolTtsController.php routes/api.php tests/Feature/Tool/ToolTtsControllerTest.php
git commit -m "Add ToolTtsController for TTS generation endpoints"
```

---

### Task 6: `ToolVoiceController` + routes

**Files:**
- Create: `app/Http/Controllers/API/ToolVoiceController.php`
- Modify: `routes/api.php` (add routes inside the existing `tool` group)
- Test: `tests/Feature/Tool/ToolVoiceControllerTest.php`

**Interfaces:**
- Produces: `GET /api/tool/models`, `GET /api/tool/voice-system-clone`, `GET /api/tool/voices/system`, `GET /api/tool/voices/cloned`, `POST /api/tool/voices/clone`, `DELETE /api/tool/voices/{id}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/ToolVoiceControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolVoiceControllerTest extends TestCase
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

    public function test_models_returns_provider_filtered_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['models' => ['a']], 200)]);
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/models?provider=elevenlabs');

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider=elevenlabs'));
    }

    public function test_system_voices_returns_filtered_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voices' => []], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/tool/voices/system?gender=Female')
            ->assertOk();
    }

    public function test_cloned_voices_returns_list(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voices' => []], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))->getJson('/api/tool/voices/cloned')->assertOk();
    }

    public function test_clone_uploads_audio_file(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_v1'], 200)]);
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', ['file' => $file, 'voice_name' => 'My Voice']);

        $response->assertOk()->assertJsonPath('voice_id', 'new_v1');
    }

    public function test_clone_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->post('/api/tool/voices/clone', [])
            ->assertStatus(422);
    }

    public function test_delete_voice_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->deleteJson('/api/tool/voices/voice_123')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolVoiceControllerTest`
Expected: FAIL — 404 on `/api/tool/models`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/ToolVoiceController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GenMaxService;
use Illuminate\Http\Request;

class ToolVoiceController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    public function models(Request $request)
    {
        $result = $this->genMax->getModels($request->get('provider'));

        return response()->json($result['data'], $result['status']);
    }

    public function system_clone()
    {
        $result = $this->genMax->getSystemVoicesClone();

        return response()->json($result['data'], $result['status']);
    }

    public function systemVoices(Request $request)
    {
        $filters = $request->only(['page', 'page_size', 'search', 'gender', 'language', 'accent', 'age', 'use_cases']);

        $result = $this->genMax->getSystemVoices($filters);

        return response()->json($result['data'], $result['status']);
    }

    public function clonedVoices(Request $request)
    {
        $result = $this->genMax->getClonedVoices();

        return response()->json($result['data'], $result['status']);
    }

    public function clone(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a,ogg,flac,mp4,webm',
            'voice_name' => 'required|string|max:255',
            'language_tag' => 'nullable|string',
            'gender' => 'nullable|string|in:Male,Female',
            'need_noise_reduction' => 'nullable|boolean',
            'preview_text' => 'nullable|string|max:200',
        ]);

        $multipart = [];

        $file = $request->file('file');
        $multipart[] = [
            'name' => 'file',
            'file' => fopen($file->getRealPath(), 'r'),
            'filename' => $file->getClientOriginalName(),
        ];

        $fields = ['voice_name', 'language_tag', 'gender', 'need_noise_reduction', 'preview_text'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $multipart[] = ['name' => $field, 'value' => $request->input($field)];
            }
        }

        $result = $this->genMax->cloneVoice($multipart);

        return response()->json($result['data'], $result['status']);
    }

    public function delete(Request $request, string $id)
    {
        $result = $this->genMax->deleteVoice($id);

        return response()->json($result['data'], $result['status']);
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php`:

```php
use App\Http\Controllers\API\ToolVoiceController;

    Route::get('/models', [ToolVoiceController::class, 'models']);
    Route::get('/voice-system-clone', [ToolVoiceController::class, 'system_clone']);
    Route::get('/voices/system', [ToolVoiceController::class, 'systemVoices']);
    Route::get('/voices/cloned', [ToolVoiceController::class, 'clonedVoices']);
    Route::post('/voices/clone', [ToolVoiceController::class, 'clone']);
    Route::delete('/voices/{id}', [ToolVoiceController::class, 'delete']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ToolVoiceControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (152 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/ToolVoiceController.php routes/api.php tests/Feature/Tool/ToolVoiceControllerTest.php
git commit -m "Add ToolVoiceController for voice model/cloning endpoints"
```

---

## What's next

Phase 3A ships a complete, independently testable TTS + voice cloning feature. Phase 3B (SRT generate/translate pipeline: `AIController` transcribe/translate, `SrtGenerateController`, `SrtTranslateController`, `GroqService`, `SrtChunkTranslationService`, `SrtTimeRedistributionService`, plus the `ProcessSrtGenerate`/`ProcessSrtTranslate` background jobs — reusing `SrtParserService` from this phase) gets its own plan document once this one is executed and verified.
