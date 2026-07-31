# Phase 3B: SRT Generate/Translate Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add speech-to-text (Groq Whisper), AI translation (Gemini/OpenRouter), and the full audio→SRT and audio→translated-SRT background-job pipelines, on top of Phase 3A's `SrtParserService` and Phases 1-2's credit system.

**Architecture:** Two synchronous endpoints (`/api/transcribe`, `/api/translate`) call Groq/Gemini directly for quick, single-shot conversions. Two asynchronous pipelines (`/api/tool/generate-srt`, `/api/tool/translate-srt`) upload an audio file, create a DB-tracked job row, and dispatch a queued job that runs Whisper STT → (translate, chunked to avoid LLM output truncation) → (retime translated subtitles to fit dubbing timing) → sanitize junk entries → deduct credits based on actual character count — with the client polling a `status/{id}` endpoint for progress. All external AI providers (Groq, Gemini, OpenRouter) are called via `Illuminate\Support\Facades\Http`, mocked with `Http::fake()` in every test — no test ever makes a real network call.

**Tech Stack:** Laravel 10 (from Phases 1-3A), `Illuminate\Support\Facades\Http`, Laravel's queue system (`ShouldQueue`, tested by calling `handle()` directly with fake HTTP and a real in-memory-SQLite DB — not via `Queue::fake()`, since we want to verify the job's actual business logic, not just that it was dispatched).

## Global Constraints

- Builds directly on Phases 1-3A's `D:\cmbcoremkt_backend` — same DB, same `User`/`CreditTransaction`/`CreditService` models, same `SrtParserService` (Phase 3A Task 2) reused unmodified. Do not modify already-tested behavior except where a task explicitly extends an existing file.
- `Groq`, `Gemini`, and `OpenRouter` API keys are plain `.env`-configured values read via `config('services.*.api_key')` — unlike GenMax's `SystemSetting`-based admin-configurable key, this matches the original source project's own inconsistency exactly; do not "fix" this asymmetry, just port it faithfully.
- ALL calls to `generativelanguage.googleapis.com` (Gemini), `api.groq.com` (Groq), and `openrouter.ai` (OpenRouter) in tests MUST use `Http::fake()` — never a real network call.
- All work committed to git in small, working increments — one commit per task minimum.
- Continue committing directly to `master` (approved by the human partner for this project).
- Docker is this project's real dev/deploy environment; tests run against in-memory SQLite — no local MySQL needed.

---

### Task 1: Provider config + `GeminiService`

**Files:**
- Modify: `config/services.php` (add `gemini`, `groq`, `openrouter` entries)
- Modify: `.env.example` (add the three new API key placeholders)
- Create: `app/Services/GeminiService.php`
- Test: `tests/Unit/GeminiServiceTest.php`

**Interfaces:**
- Produces: `GeminiService::translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string` (throws `\RuntimeException` on API/connection failure).
- Consumed by: `AIController` (Task 5).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/GeminiServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-gemini-key']);
    }

    public function test_translate_returns_trimmed_text_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "  Xin chào thế giới  "]]]]],
            ], 200),
        ]);

        $result = (new GeminiService())->translate('Hello world', 'vi', 'text');

        $this->assertEquals('Xin chào thế giới', $result);
    }

    public function test_translate_strips_markdown_code_fence(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "```srt\n1\n00:00:01,000 --> 00:00:02,000\nHi\n```"]]]]],
            ], 200),
        ]);

        $result = (new GeminiService())->translate('1\n00:00:01,000 --> 00:00:02,000\nHi', 'vi', 'srt');

        $this->assertEquals("1\n00:00:01,000 --> 00:00:02,000\nHi", $result);
    }

    public function test_translate_throws_on_api_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('quota exceeded');

        (new GeminiService())->translate('Hello', 'vi', 'text');
    }

    public function test_translate_throws_on_empty_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => []], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        (new GeminiService())->translate('Hello', 'vi', 'text');
    }

    public function test_translate_srt_format_includes_context_when_provided(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'translated']]]]],
            ], 200),
        ]);

        (new GeminiService())->translate('some srt', 'vi', 'srt', 'previous context here');

        Http::assertSent(function ($request) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            return str_contains($prompt, 'CONTINUITY CONTEXT') && str_contains($prompt, 'previous context here');
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GeminiServiceTest`
Expected: FAIL — `App\Services\GeminiService` does not exist.

- [ ] **Step 3: Add provider config**

Add to `config/services.php` (inside the returned array, alongside `mailgun`/`postmark`/`ses`):

```php
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],
```

- [ ] **Step 4: Add `.env.example` placeholders**

Append to `.env.example`:

```env
GEMINI_API_KEY=
GROQ_API_KEY=
OPENROUTER_API_KEY=
```

- [ ] **Step 5: Write `GeminiService`**

Create `app/Services/GeminiService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    protected string $model = 'gemini-1.5-flash';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string
    {
        $prompt = $this->buildPrompt($text, $targetLanguage, $format, $context);

        try {
            $response = Http::timeout(120)
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.3],
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('Gemini API error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("Gemini API error: {$error}");
            }

            $result = $response->json('candidates.0.content.parts.0.text');

            if (empty($result)) {
                throw new \RuntimeException('Gemini API returned empty response.');
            }

            $result = trim($result);
            $result = preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $result);

            return trim($result);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Gemini connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to Gemini API. Please try again later.');
        }
    }

    protected function buildPrompt(string $text, string $targetLanguage, string $format, string $context = ''): string
    {
        if ($format === 'srt') {
            $prompt = <<<PROMPT
            You are a professional subtitle translator specialized in AI video dubbing.
            Translate the following SRT subtitle file into {$targetLanguage}.
            The translation will be used for AI voice dubbing, so it must sound natural and fit the subtitle timing.
            ------------------------------------------------
            STRICT SRT FORMAT RULES
            You MUST keep the SRT structure exactly the same.
            DO NOT change:
            - Subtitle numbers
            - Timestamps
            - Subtitle order
            - Blank lines between blocks
            ONLY translate the subtitle text.
            If a subtitle contains multiple lines, keep the same number of lines.
            Do NOT merge or split subtitle blocks.
            Return ONLY the translated SRT.
            ------------------------------------------------
            DUBBING TIMING RULES
            The translated text should fit the same speaking duration.
            Estimate natural speech speed:
            ≈ 12–15 characters per second.
            Approximate maximum characters:
            duration × 14
            If your translation is slightly longer:
            prefer shorter wording or simpler phrasing.
            However:
            NEVER remove important meaning just to shorten text.
            Meaning accuracy is more important than strict length limits.
            ------------------------------------------------
            TRANSLATION STYLE
            Use natural spoken language suitable for voice dubbing.
            Prefer:
            - conversational tone
            - shorter sentences
            - natural phrasing
            Avoid:
            - overly literal translation
            - overly formal written language
            - unnecessary repetition
            ------------------------------------------------
            HONORIFICS & SPEECH REGISTER
            Maintain consistent speech style and relationships.
            Vietnamese:
            - Use correct pronouns (tôi/bạn, anh/em, cô/cháu, etc.)
            - Keep natural spoken tone
            Japanese:
            - Preserve appropriate keigo levels
            Korean:
            - Maintain correct speech level (해요체 / 합쇼체 / 해체)
            Chinese:
            - Use correct 你 / 您 forms
            Thai:
            - Preserve polite particles (ครับ / ค่ะ)
            ------------------------------------------------
            SENSITIVE CONTENT HANDLING
            If the original subtitle contains sensitive, explicit, or potentially
            offensive words/phrases (e.g. references to child abuse, suicide,
            drugs, extreme violence, slurs, hate speech), you MUST:
            - Replace them with softer, commonly understood euphemisms or
              equivalent expressions in the target language.
            - The replacement must preserve the ORIGINAL MEANING so the
              listener can still understand the context.
            - Do NOT censor with symbols (***) or remove the content entirely.
            Examples (Vietnamese):
              "tự tử" → "tự kết liễu" or "quyên sinh"
              "ấu dâm" → "xâm hại trẻ em"
              "ma túy" → "chất cấm" or "chất gây nghiện"
              "hiếp dâm" → "xâm hại tình dục"
            Apply equivalent natural euphemisms for ALL target languages.
            ------------------------------------------------
            OUTPUT FORMAT EXAMPLE
            1
            00:00:01,000 --> 00:00:02,500
            Translated subtitle text

            2
            00:00:02,600 --> 00:00:04,000
            Next subtitle line
            ------------------------------------------------
            Now translate the following SRT:
            {$text}
            PROMPT;

            if (!empty($context)) {
                $contextSection = <<<CONTEXT
            ------------------------------------------------
            CONTINUITY CONTEXT (reference only — do NOT include these in your output)
            The following subtitles were already translated in the previous batch.
            Use them to maintain consistent:
            - pronouns and honorifics (e.g. anh/em, tôi/bạn)
            - terminology and proper nouns
            - tone and speech style

            {$context}
            ------------------------------------------------
            CONTEXT;
                $prompt = $contextSection . "\n" . $prompt;
            }

            return $prompt;
        }

        return <<<PROMPT
            Translate the following text into {$targetLanguage}.
            Return ONLY the translated text.
            Do NOT add explanations.
            {$text}
            PROMPT;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=GeminiServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (179 tests — Phase 3A's 174 + 5 new).

- [ ] **Step 8: Commit**

```bash
git add config/services.php .env.example app/Services/GeminiService.php tests/Unit/GeminiServiceTest.php
git commit -m "Add Gemini, Groq, OpenRouter provider config and GeminiService"
```

---

### Task 2: `GroqService`

**Files:**
- Create: `app/Services/GroqService.php`
- Test: `tests/Unit/GroqServiceTest.php`

**Interfaces:**
- Produces: `GroqService::transcribe(\Illuminate\Http\UploadedFile $file, ?string $language = null): string` (SRT content, throws `\RuntimeException` on failure), `::transcribeRaw(string $filePath, string $fileName, ?string $language = null): string`.
- Consumed by: `AIController` (Task 5), `ProcessSrtGenerate`/`ProcessSrtTranslate` jobs (Tasks 7-8).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/GroqServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\GroqService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'test-groq-key']);
    }

    public function test_transcribe_converts_segments_to_srt(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [
                    ['start' => 0.0, 'end' => 2.5, 'text' => ' Hello there '],
                    ['start' => 2.5, 'end' => 4.0, 'text' => ' Second line '],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $srt = (new GroqService())->transcribe($file);

        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:02,500\nHello there", $srt);
        $this->assertStringContainsString("2\n00:00:02,500 --> 00:00:04,000\nSecond line", $srt);
    }

    public function test_transcribe_falls_back_to_plain_text_when_no_segments(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['text' => 'Just some text'], 200),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $srt = (new GroqService())->transcribe($file);

        $this->assertStringContainsString('Just some text', $srt);
        $this->assertStringStartsWith("1\n00:00:00,000 --> 00:00:01,000\n", $srt);
    }

    public function test_transcribe_throws_on_api_error(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'invalid file']], 400),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid file');

        (new GroqService())->transcribe($file);
    }

    public function test_transcribe_passes_language_hint_when_provided(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => []], 200)]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        (new GroqService())->transcribe($file, 'vi');

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'vi');
        });
    }

    public function test_transcribe_raw_wraps_a_file_path_into_an_uploaded_file(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hi']]], 200)]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'groqtest');
        file_put_contents($tmpPath, 'fake audio bytes');

        $srt = (new GroqService())->transcribeRaw($tmpPath, 'original.mp3');

        $this->assertStringContainsString('Hi', $srt);
        @unlink($tmpPath);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GroqServiceTest`
Expected: FAIL — `App\Services\GroqService` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/GroqService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.groq.com/openai/v1';

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
    }

    public function transcribeRaw(string $filePath, string $fileName, ?string $language = null): string
    {
        $fakeFile = new \Illuminate\Http\UploadedFile($filePath, $fileName, null, null, true);

        return $this->transcribe($fakeFile, $language);
    }

    public function transcribe(UploadedFile $file, ?string $language = null): string
    {
        try {
            $postData = [
                'model' => 'whisper-large-v3-turbo',
                'response_format' => 'verbose_json',
            ];

            if ($language) {
                $postData['language'] = $language;
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post("{$this->baseUrl}/audio/transcriptions", $postData);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('Groq Whisper API error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("Groq API error: {$error}");
            }

            $data = $response->json();

            if (!isset($data['segments']) || empty($data['segments'])) {
                return "1\n00:00:00,000 --> 00:00:01,000\n" . ($data['text'] ?? '');
            }

            return $this->jsonToSrt($data['segments']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Groq Whisper connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to Groq API. Please try again later.');
        }
    }

    protected function jsonToSrt(array $segments): string
    {
        $srt = '';
        foreach ($segments as $index => $segment) {
            $seq = $index + 1;
            $startTime = $this->formatTimestamp($segment['start']);
            $endTime = $this->formatTimestamp($segment['end']);
            $text = trim($segment['text']);

            $srt .= "{$seq}\n{$startTime} --> {$endTime}\n{$text}\n\n";
        }

        return trim($srt);
    }

    protected function formatTimestamp(float $seconds): string
    {
        $milliSeconds = (int) round(($seconds - floor($seconds)) * 1000);
        $secondsStamp = floor($seconds);

        $hours = floor($secondsStamp / 3600);
        $minutes = floor(($secondsStamp % 3600) / 60);
        $secs = $secondsStamp % 60;

        return sprintf("%02d:%02d:%02d,%03d", $hours, $minutes, $secs, $milliSeconds);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GroqServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (184 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/GroqService.php tests/Unit/GroqServiceTest.php
git commit -m "Add GroqService for Whisper speech-to-text transcription"
```

---

### Task 3: `OpenRouterService`

**Files:**
- Create: `app/Services/OpenRouterService.php`
- Test: `tests/Unit/OpenRouterServiceTest.php`

**Interfaces:**
- Produces: `OpenRouterService::translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string` (throws `\RuntimeException`).
- Consumed by: `ProcessSrtTranslate` job (Task 8).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/OpenRouterServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => 'test-openrouter-key']);
    }

    public function test_translate_returns_trimmed_text_response(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "  Xin chào  "]]],
            ], 200),
        ]);

        $result = (new OpenRouterService())->translate('Hello', 'vi', 'text');

        $this->assertEquals('Xin chào', $result);
    }

    public function test_translate_strips_markdown_code_fence(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "```srt\nsome srt\n```"]]],
            ], 200),
        ]);

        $result = (new OpenRouterService())->translate('some srt', 'vi', 'srt');

        $this->assertEquals('some srt', $result);
    }

    public function test_translate_throws_on_api_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limited');

        (new OpenRouterService())->translate('Hello', 'vi', 'text');
    }

    public function test_translate_sends_bearer_auth_header(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        (new OpenRouterService())->translate('Hello', 'vi', 'text');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-openrouter-key'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OpenRouterServiceTest`
Expected: FAIL — `App\Services\OpenRouterService` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/OpenRouterService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    protected string $model = 'google/gemini-2.0-flash-001';

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string
    {
        $prompt = $this->buildPrompt($text, $targetLanguage, $format, $context);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.3,
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('OpenRouter API error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("OpenRouter API error: {$error}");
            }

            $result = $response->json('choices.0.message.content');

            if (empty($result)) {
                throw new \RuntimeException('OpenRouter API returned empty response.');
            }

            $result = trim($result);
            $result = preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $result);

            return trim($result);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('OpenRouter connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to OpenRouter API. Please try again later.');
        }
    }

    protected function buildPrompt(string $text, string $targetLanguage, string $format, string $context = ''): string
    {
        if ($format === 'srt') {
            $prompt = <<<PROMPT
You are a professional subtitle translator specialized in AI video dubbing.
Translate the following SRT subtitle file into {$targetLanguage}.
The translation will be used for AI voice dubbing, so it must sound natural and fit the subtitle timing.
------------------------------------------------
STRICT SRT FORMAT RULES
You MUST keep the SRT structure exactly the same.
DO NOT change:
- Subtitle numbers
- Timestamps
- Subtitle order
- Blank lines between blocks
ONLY translate the subtitle text.
If a subtitle contains multiple lines, keep the same number of lines.
Do NOT merge or split subtitle blocks.
Return ONLY the translated SRT.
------------------------------------------------
DUBBING TIMING RULES
The translated text should fit the same speaking duration.
Estimate natural speech speed:
≈ 12–15 characters per second.
Approximate maximum characters:
duration × 14
If your translation is slightly longer:
prefer shorter wording or simpler phrasing.
However:
NEVER remove important meaning just to shorten text.
Meaning accuracy is more important than strict length limits.
------------------------------------------------
TRANSLATION STYLE
Use natural spoken language suitable for voice dubbing.
Prefer:
- conversational tone
- shorter sentences
- natural phrasing
Avoid:
- overly literal translation
- overly formal written language
- unnecessary repetition
------------------------------------------------
HONORIFICS & SPEECH REGISTER
Maintain consistent speech style and relationships.
Vietnamese:
- Use correct pronouns (tôi/bạn, anh/em, cô/cháu, etc.)
- Keep natural spoken tone
Japanese:
- Preserve appropriate keigo levels
Korean:
- Maintain correct speech level (해요체 / 합쇼체 / 해체)
Chinese:
- Use correct 你 / 您 forms
Thai:
- Preserve polite particles (ครับ / ค่ะ)
------------------------------------------------
SENSITIVE CONTENT HANDLING
If the original subtitle contains sensitive, explicit, or potentially
offensive words/phrases (e.g. references to child abuse, suicide,
drugs, extreme violence, slurs, hate speech), you MUST:
- Replace them with softer, commonly understood euphemisms or
  equivalent expressions in the target language.
- The replacement must preserve the ORIGINAL MEANING so the
  listener can still understand the context.
- Do NOT censor with symbols (***) or remove the content entirely.
Examples (Vietnamese):
  "tự tử" → "tự kết liễu" or "quyên sinh"
  "ấu dâm" → "xâm hại trẻ em"
  "ma túy" → "chất cấm" or "chất gây nghiện"
  "hiếp dâm" → "xâm hại tình dục"
Apply equivalent natural euphemisms for ALL target languages.
------------------------------------------------
OUTPUT FORMAT EXAMPLE
1
00:00:01,000 --> 00:00:02,500
Translated subtitle text

2
00:00:02,600 --> 00:00:04,000
Next subtitle line
------------------------------------------------
Now translate the following SRT:
{$text}
PROMPT;

            if (!empty($context)) {
                $contextSection = <<<CONTEXT
------------------------------------------------
CONTINUITY CONTEXT (reference only — do NOT include these in your output)
The following subtitles were already translated in the previous batch.
Use them to maintain consistent:
- pronouns and honorifics (e.g. anh/em, tôi/bạn)
- terminology and proper nouns
- tone and speech style

{$context}
------------------------------------------------
CONTEXT;
                $prompt = $contextSection . "\n" . $prompt;
            }

            return $prompt;
        }

        return <<<PROMPT
Translate the following text into {$targetLanguage}.
Return ONLY the translated text.
Do NOT add explanations.
{$text}
PROMPT;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OpenRouterServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (188 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/OpenRouterService.php tests/Unit/OpenRouterServiceTest.php
git commit -m "Add OpenRouterService for AI-based translation"
```

---

### Task 4: `SrtChunkTranslationService`

**Files:**
- Create: `app/Services/SrtChunkTranslationService.php`
- Test: `tests/Unit/SrtChunkTranslationServiceTest.php`

**Interfaces:**
- Produces: `SrtChunkTranslationService::translate(string $srtContent, string $targetLanguage, callable $translator): string` — `$translator` signature: `fn(string $srtChunk, string $targetLang, string $context = ''): string`. Throws `\RuntimeException` if validation fails after retries.
- Consumes: `SrtParserService::parse()` (Phase 3A Task 2).
- Consumed by: `AIController` (Task 5), `ProcessSrtTranslate` job (Task 8).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SrtChunkTranslationServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SrtChunkTranslationService;
use Tests\TestCase;

class SrtChunkTranslationServiceTest extends TestCase
{
    private function buildSrt(int $count): string
    {
        $blocks = [];
        for ($i = 1; $i <= $count; $i++) {
            $start = sprintf('00:00:%02d,000', $i);
            $end = sprintf('00:00:%02d,000', $i + 1);
            $blocks[] = "{$i}\n{$start} --> {$end}\nLine {$i}";
        }
        return implode("\n\n", $blocks) . "\n";
    }

    public function test_translate_single_chunk_preserves_timestamps_and_translates_text(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70);
        $srt = $this->buildSrt(2);

        $translator = fn(string $chunk, string $lang, string $context = '') =>
            "1\n00:00:01,000 --> 00:00:02,000\nDòng 1\n\n2\n00:00:02,000 --> 00:00:03,000\nDòng 2\n";

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertStringContainsString('00:00:01,000 --> 00:00:02,000', $result);
        $this->assertStringContainsString('Dòng 1', $result);
        $this->assertStringContainsString('Dòng 2', $result);
    }

    public function test_translate_splits_into_multiple_chunks_when_exceeding_chunk_size(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 2);
        $srt = $this->buildSrt(5);
        $callCount = 0;

        $translator = function (string $chunk, string $lang, string $context = '') use (&$callCount) {
            $callCount++;
            // Echo back the chunk renumbered 1..N with translated marker, same count.
            $lines = substr_count(trim($chunk), "\n\n") + 1;
            $blocks = [];
            for ($i = 1; $i <= $lines; $i++) {
                $blocks[] = "{$i}\n00:00:0{$i},000 --> 00:00:0{$i},500\nTranslated {$i}";
            }
            return implode("\n\n", $blocks);
        };

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertEquals(3, $callCount); // ceil(5/2) = 3 chunks
        $this->assertEquals(5, substr_count($result, '-->'));
    }

    public function test_translate_retries_on_count_mismatch_then_succeeds(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 2);
        $srt = $this->buildSrt(2);
        $attempt = 0;

        $translator = function (string $chunk, string $lang, string $context = '') use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                // Wrong count on first attempt
                return "1\n00:00:01,000 --> 00:00:02,000\nOnly one";
            }
            return "1\n00:00:01,000 --> 00:00:02,000\nDòng 1\n\n2\n00:00:02,000 --> 00:00:03,000\nDòng 2\n";
        };

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertEquals(2, $attempt);
        $this->assertStringContainsString('Dòng 1', $result);
    }

    public function test_translate_throws_after_exhausting_retries(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 2);
        $srt = $this->buildSrt(2);

        $translator = fn(string $chunk, string $lang, string $context = '') => "1\n00:00:01,000 --> 00:00:02,000\nOnly one";

        $this->expectException(\RuntimeException::class);

        $service->translate($srt, 'vi', $translator);
    }

    public function test_translate_throws_if_timestamps_are_modified(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 1);
        $srt = $this->buildSrt(1);

        $translator = fn(string $chunk, string $lang, string $context = '') => "1\n00:00:05,000 --> 00:00:06,000\nWrong timing";

        $this->expectException(\RuntimeException::class);

        $service->translate($srt, 'vi', $translator);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SrtChunkTranslationServiceTest`
Expected: FAIL — `App\Services\SrtChunkTranslationService` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/SrtChunkTranslationService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SrtChunkTranslationService
{
    protected SrtParserService $parser;
    protected int $chunkSize;
    protected int $maxRetries;
    protected int $overlapSize;

    public function __construct(
        ?SrtParserService $parser = null,
        int $chunkSize = 70,
        int $maxRetries = 3,
        int $overlapSize = 5
    ) {
        $this->parser = $parser ?? app(SrtParserService::class);
        $this->chunkSize = $chunkSize;
        $this->maxRetries = $maxRetries;
        $this->overlapSize = $overlapSize;
    }

    public function translate(string $srtContent, string $targetLanguage, callable $translator): string
    {
        $parsed = $this->parser->parse($srtContent);
        $entries = $parsed['entries'];
        $totalCount = count($entries);

        Log::info('[SrtChunkTranslation] Starting', [
            'total_subtitles' => $totalCount,
            'chunk_size' => $this->chunkSize,
            'chunks_needed' => (int) ceil($totalCount / $this->chunkSize),
        ]);

        $chunks = array_chunk($entries, $this->chunkSize);

        $translatedEntries = [];
        $previousContext = '';

        foreach ($chunks as $chunkIndex => $chunkEntries) {
            $chunkNumber = $chunkIndex + 1;
            $chunkTotal = count($chunks);
            $expectedCount = count($chunkEntries);

            $chunkSrt = $this->entriesToSrt($chunkEntries, renumber: true);

            $translatedChunkEntries = $this->translateChunkWithRetry(
                $chunkSrt,
                $targetLanguage,
                $translator,
                $expectedCount,
                $chunkNumber,
                $chunkTotal,
                $previousContext
            );

            foreach ($chunkEntries as $i => $originalEntry) {
                $translatedEntries[] = [
                    'index' => $originalEntry['index'],
                    'start' => $originalEntry['start'],
                    'end' => $originalEntry['end'],
                    'text' => $translatedChunkEntries[$i]['text'] ?? $originalEntry['text'],
                ];
            }

            if ($chunkIndex < count($chunks) - 1) {
                $overlapEntries = array_slice($translatedChunkEntries, -$this->overlapSize);
                $overlapOriginal = array_slice($chunkEntries, -$this->overlapSize);
                $contextEntries = [];
                foreach ($overlapEntries as $j => $tEntry) {
                    $contextEntries[] = [
                        'index' => $overlapOriginal[$j]['index'] ?? ($j + 1),
                        'start' => $overlapOriginal[$j]['start'],
                        'end' => $overlapOriginal[$j]['end'],
                        'text' => $tEntry['text'],
                    ];
                }
                $previousContext = $this->entriesToSrt($contextEntries, renumber: false);
            }
        }

        $this->validateOutput($entries, $translatedEntries);

        $result = $this->entriesToSrt($translatedEntries, renumber: false);

        Log::info('[SrtChunkTranslation] Completed', [
            'input_count' => $totalCount,
            'output_count' => count($translatedEntries),
        ]);

        return $result;
    }

    protected function translateChunkWithRetry(
        string $chunkSrt,
        string $targetLanguage,
        callable $translator,
        int $expectedCount,
        int $chunkNumber,
        int $chunkTotal,
        string $contextSrt = ''
    ): array {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $translatedSrt = $translator($chunkSrt, $targetLanguage, $contextSrt);
                $translatedSrt = $this->stripMarkdownWrapper($translatedSrt);

                $parsed = $this->parser->parse($translatedSrt);
                $translatedEntries = $parsed['entries'];

                if (count($translatedEntries) === $expectedCount) {
                    if ($attempt > 1) {
                        Log::info("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} succeeded on attempt {$attempt}");
                    }
                    return $translatedEntries;
                }

                $actualCount = count($translatedEntries);
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} count mismatch", [
                    'attempt' => $attempt,
                    'expected' => $expectedCount,
                    'actual' => $actualCount,
                ]);

                $lastException = new \RuntimeException(
                    "Chunk {$chunkNumber}/{$chunkTotal}: expected {$expectedCount} subtitles, got {$actualCount}"
                );
            } catch (\InvalidArgumentException $e) {
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} parse failed", ['attempt' => $attempt, 'error' => $e->getMessage()]);
                $lastException = $e;
            } catch (\RuntimeException $e) {
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} API error", ['attempt' => $attempt, 'error' => $e->getMessage()]);
                $lastException = $e;
            }

            if ($attempt < $this->maxRetries) {
                usleep($attempt * 500_000);
            }
        }

        throw new \RuntimeException(
            "Chunk {$chunkNumber}/{$chunkTotal} failed after {$this->maxRetries} attempts: "
                . ($lastException?->getMessage() ?? 'Unknown error')
        );
    }

    protected function validateOutput(array $inputEntries, array $outputEntries): void
    {
        $inputCount = count($inputEntries);
        $outputCount = count($outputEntries);

        if ($outputCount !== $inputCount) {
            throw new \RuntimeException(
                "SRT translation validation failed: input has {$inputCount} subtitles, output has {$outputCount}"
            );
        }

        foreach ($outputEntries as $i => $entry) {
            $expectedIndex = $inputEntries[$i]['index'];
            if ($entry['index'] !== $expectedIndex) {
                throw new \RuntimeException(
                    "SRT translation validation failed: subtitle #{$entry['index']} at position " . ($i + 1)
                        . " should be #{$expectedIndex}"
                );
            }
        }

        foreach ($outputEntries as $i => $entry) {
            if ($entry['start'] !== $inputEntries[$i]['start'] || $entry['end'] !== $inputEntries[$i]['end']) {
                $idx = $entry['index'];
                throw new \RuntimeException("SRT translation validation failed: timestamps modified for subtitle #{$idx}");
            }
        }

        Log::info('[SrtChunkTranslation] Validation passed', [
            'subtitle_count' => $outputCount,
            'first_index' => $outputEntries[0]['index'] ?? '?',
            'last_index' => end($outputEntries)['index'] ?? '?',
        ]);
    }

    protected function entriesToSrt(array $entries, bool $renumber = false): string
    {
        $blocks = [];

        foreach ($entries as $i => $entry) {
            $index = $renumber ? ($i + 1) : $entry['index'];
            $blocks[] = "{$index}\n{$entry['start']} --> {$entry['end']}\n{$entry['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }

    protected function stripMarkdownWrapper(string $text): string
    {
        $text = trim($text);
        return preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $text);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SrtChunkTranslationServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (193 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SrtChunkTranslationService.php tests/Unit/SrtChunkTranslationServiceTest.php
git commit -m "Add SrtChunkTranslationService for chunked SRT translation with retry"
```

---

### Task 5: `AIController` — transcribe + translate

**Files:**
- Create: `app/Http/Controllers/API/AIController.php`
- Modify: `routes/api.php` (add routes at the top level, matching source project's `/api/transcribe`, `/api/translate` — NOT inside the `tool` group)
- Test: `tests/Feature/AIControllerTest.php`

**Interfaces:**
- Produces: `POST /api/transcribe`, `POST /api/translate` — both `auth:sanctum` + `token.version`, `transcribe` additionally requires `email.verified` (matching the existing Phase 1 convention for AI-costing endpoints).
- Consumes: `GroqService` (Task 2), `GeminiService` (Task 1), `SrtChunkTranslationService` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AIControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function premiumUser(): User
    {
        return User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
    }

    public function test_transcribe_returns_srt_for_premium_user(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hi']]], 200)]);
        $user = $this->premiumUser();
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $response = $this->withHeaders($this->authHeader($user))->post('/api/transcribe', ['file' => $file]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('Hi', $response->json('srt'));
    }

    public function test_transcribe_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/transcribe', ['file' => $file])
            ->assertStatus(403);
    }

    public function test_transcribe_requires_email_verification(): void
    {
        $user = User::factory()->unverified()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->withHeaders($this->authHeader($user))
            ->post('/api/transcribe', ['file' => $file])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_translate_text_format(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Xin chào']]]]],
            ], 200),
        ]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ]);

        $response->assertOk()->assertJsonPath('translated', 'Xin chào');
    }

    public function test_translate_srt_format_uses_chunk_translator(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "1\n00:00:01,000 --> 00:00:02,000\nDòng 1"]]]]],
            ], 200),
        ]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => "1\n00:00:01,000 --> 00:00:02,000\nLine 1",
            'target_language' => 'vi',
            'format' => 'srt',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Dòng 1', $response->json('translated'));
    }

    public function test_translate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ])->assertStatus(403);
    }

    public function test_translate_returns_500_on_provider_failure(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))->postJson('/api/translate', [
            'text' => 'Hello',
            'target_language' => 'vi',
            'format' => 'text',
        ])->assertStatus(500)->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AIControllerTest`
Expected: FAIL — 404 on `/api/transcribe`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/AIController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GeminiService;
use App\Services\GroqService;
use App\Services\SrtChunkTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    protected GroqService $groq;
    protected GeminiService $gemini;

    public function __construct(GroqService $groq, GeminiService $gemini)
    {
        $this->groq = $groq;
        $this->gemini = $gemini;
    }

    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a,mp4',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            $srt = $this->groq->transcribe($request->file('file'));

            return response()->json(['success' => true, 'srt' => $srt]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function translate(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:50000',
            'target_language' => 'required|string|max:10',
            'format' => 'required|string|in:text,srt',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            if ($request->input('format') === 'srt') {
                $chunkTranslator = app(SrtChunkTranslationService::class);
                $translated = $chunkTranslator->translate(
                    $request->input('text'),
                    $request->input('target_language'),
                    fn(string $chunk, string $lang, string $context = '') => $this->gemini->translate($chunk, $lang, 'srt', $context)
                );
            } else {
                $translated = $this->gemini->translate(
                    $request->input('text'),
                    $request->input('target_language'),
                    $request->input('format')
                );
            }

            return response()->json(['success' => true, 'translated' => $translated]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
```

- [ ] **Step 4: Wire the routes**

Add to `routes/api.php` (NOT inside the `tool` group — these are top-level, matching the source project's convention):

```php
use App\Http\Controllers\API\AIController;

Route::middleware(['auth:sanctum', 'token.version'])->group(function () {
    Route::post('/transcribe', [AIController::class, 'transcribe'])->middleware('email.verified');
    Route::post('/translate', [AIController::class, 'translate']);
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AIControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (200 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/AIController.php routes/api.php tests/Feature/AIControllerTest.php
git commit -m "Add AIController for direct transcribe/translate endpoints"
```

---

### Task 6: `SrtTimeRedistributionService`

**Files:**
- Create: `app/Services/SrtTimeRedistributionService.php`
- Test: `tests/Unit/SrtTimeRedistributionServiceTest.php`

**Interfaces:**
- Produces: `SrtTimeRedistributionService::redistribute(string $srtContent): string`, `::parseTimestamp(string $ts): float`, `::formatTimestamp(float $seconds): string`.
- Consumes: `SrtParserService::parse()` (Phase 3A Task 2).
- Consumed by: `ProcessSrtTranslate` job (Task 8).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SrtTimeRedistributionServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SrtTimeRedistributionService;
use Tests\TestCase;

class SrtTimeRedistributionServiceTest extends TestCase
{
    public function test_parse_and_format_timestamp_round_trip(): void
    {
        $service = new SrtTimeRedistributionService();

        $seconds = $service->parseTimestamp('00:01:05,250');

        $this->assertEquals(65.25, $seconds);
        $this->assertEquals('00:01:05,250', $service->formatTimestamp($seconds));
    }

    public function test_redistribute_returns_original_when_no_entries(): void
    {
        $service = new SrtTimeRedistributionService();

        // A string that fails to parse into any entries should return unchanged
        // (redistribute catches the empty-segments case internally via SrtParserService,
        // which throws — so use a service call wrapped by the job's own try/catch instead).
        // Here we test the documented empty-array short-circuit path directly is not
        // reachable via parse() (which throws on empty) — so test with a minimal valid SRT instead.
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nHello world this is a test\n";

        $result = $service->redistribute($srt);

        $this->assertStringContainsString('-->', $result);
    }

    public function test_redistribute_shrinks_segment_with_large_surplus(): void
    {
        $service = new SrtTimeRedistributionService();
        // "Hi" (2 chars) needs ~0.14s at 14 chars/sec, but is given 10 seconds — should shrink.
        $srt = "1\n00:00:00,000 --> 00:00:10,000\nHi\n\n2\n00:00:15,000 --> 00:00:16,000\nBye\n";

        $result = $service->redistribute($srt);
        $firstEnd = $service->parseTimestamp(explode(' --> ', explode("\n", $result)[1])[1]);

        $this->assertLessThan(10.0, $firstEnd);
    }

    public function test_redistribute_borrows_from_gap_for_short_segment(): void
    {
        $service = new SrtTimeRedistributionService();
        // Second segment has a long text needing more time than its 1-second slot,
        // with a generous gap before it (segment 1 ends at 2s, segment 2 starts at 10s).
        $longText = str_repeat('word ', 20); // ~100 chars, needs several seconds
        $srt = "1\n00:00:00,000 --> 00:00:02,000\nShort\n\n2\n00:00:10,000 --> 00:00:11,000\n{$longText}\n";

        $result = $service->redistribute($srt);

        // The second segment's start should have been pulled earlier than 10s to borrow time.
        $lines = array_values(array_filter(explode("\n", $result)));
        $secondTimingLine = $lines[3]; // index 0
        [$start] = explode(' --> ', $secondTimingLine);
        $startSeconds = $service->parseTimestamp($start);

        $this->assertLessThan(10.0, $startSeconds);
    }

    public function test_redistribute_splits_subtitle_exceeding_max_cps(): void
    {
        $service = new SrtTimeRedistributionService();
        // 100 characters in a 1-second window is far above maxCps (17) — should split into 2+ entries.
        $text = str_repeat('a', 100);
        $srt = "1\n00:00:00,000 --> 00:00:01,000\n{$text}\n";

        $result = $service->redistribute($srt);

        $entryCount = substr_count($result, '-->');
        $this->assertGreaterThan(1, $entryCount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SrtTimeRedistributionServiceTest`
Expected: FAIL — `App\Services\SrtTimeRedistributionService` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/SrtTimeRedistributionService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SrtTimeRedistributionService
{
    protected float $charsPerSec;
    protected float $wordsPerSec;
    protected float $minGap;
    protected float $shrinkPadding;
    protected float $maxExtendRatio;
    protected float $maxCps;

    public function __construct(
        float $charsPerSec = 14.0,
        float $wordsPerSec = 2.2,
        float $minGap = 0.12,
        float $shrinkPadding = 0.15,
        float $maxExtendRatio = 1.5,
        float $maxCps = 17.0
    ) {
        $this->charsPerSec = $charsPerSec;
        $this->wordsPerSec = $wordsPerSec;
        $this->minGap = $minGap;
        $this->shrinkPadding = $shrinkPadding;
        $this->maxExtendRatio = $maxExtendRatio;
        $this->maxCps = $maxCps;
    }

    public function redistribute(string $srtContent): string
    {
        $parser = app(SrtParserService::class);
        $parsed = $parser->parse($srtContent);

        $segments = $parsed['entries'];

        if (count($segments) === 0) {
            return $srtContent;
        }

        $segs = array_map(fn($s) => [
            'index' => $s['index'],
            'start' => $this->parseTimestamp($s['start']),
            'end' => $this->parseTimestamp($s['end']),
            'text' => trim($s['text']),
        ], $segments);

        $segs = $this->splitOverflowSubtitles($segs);
        $segs = $this->mergeShortSubtitles($segs);

        $n = count($segs);

        $needed = [];
        for ($i = 0; $i < $n; $i++) {
            $needed[$i] = $this->estimateDuration($segs[$i]['text']);
        }

        $totalShrunk = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $currentDuration = $segs[$i]['end'] - $segs[$i]['start'];
            $surplus = $currentDuration - $needed[$i] - $this->shrinkPadding;

            if ($surplus > 0.05) {
                $segs[$i]['end'] -= $surplus;
                $totalShrunk += $surplus;
            }
        }

        $totalBorrowed = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $currentDuration = $segs[$i]['end'] - $segs[$i]['start'];
            $deficit = $needed[$i] - $currentDuration;

            if ($deficit <= 0.01) {
                continue;
            }

            $originalDuration = $currentDuration;
            $maxExtend = $originalDuration * ($this->maxExtendRatio - 1.0);
            $deficit = min($deficit, $maxExtend);

            if ($deficit <= 0.01) {
                continue;
            }

            if ($i > 0) {
                $gapBefore = $segs[$i]['start'] - $segs[$i - 1]['end'];
                $canBorrowBack = max(0, $gapBefore - $this->minGap);
                $borrowBack = min($deficit, $canBorrowBack);

                if ($borrowBack > 0.01) {
                    $segs[$i]['start'] -= $borrowBack;
                    $deficit -= $borrowBack;
                    $totalBorrowed += $borrowBack;
                }
            }

            if ($deficit > 0.01 && $i < $n - 1) {
                $gapAfter = $segs[$i + 1]['start'] - $segs[$i]['end'];
                $canBorrowFwd = max(0, $gapAfter - $this->minGap);
                $borrowFwd = min($deficit, $canBorrowFwd);

                if ($borrowFwd > 0.01) {
                    $segs[$i]['end'] += $borrowFwd;
                    $deficit -= $borrowFwd;
                    $totalBorrowed += $borrowFwd;
                }
            }

            if ($deficit > 0.01 && $i === $n - 1) {
                $segs[$i]['end'] += $deficit;
                $totalBorrowed += $deficit;
            }
        }

        if ($totalShrunk > 0.01 || $totalBorrowed > 0.01) {
            Log::info('[SrtRetiming] Redistributed', [
                'segments' => $n,
                'shrunk_seconds' => round($totalShrunk, 2),
                'borrowed_seconds' => round($totalBorrowed, 2),
            ]);
        }

        return $this->buildSrt($segs);
    }

    protected function splitOverflowSubtitles(array $segments): array
    {
        $result = [];

        foreach ($segments as $seg) {
            $duration = $seg['end'] - $seg['start'];

            if ($duration > 0 && mb_strlen($seg['text']) / $duration > $this->maxCps) {
                $split = $this->splitSubtitle($seg);
                foreach ($split as $s) {
                    $result[] = $s;
                }
            } else {
                $result[] = $seg;
            }
        }

        return $result;
    }

    protected function splitSubtitle(array $seg): array
    {
        $text = $seg['text'];

        $parts = preg_split('/([,.;!?]\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($parts) <= 2) {
            $words = preg_split('/\s+/u', $text);
            if (count($words) <= 1) {
                return [$seg];
            }
            $half = (int) ceil(count($words) / 2);
            $text1 = implode(' ', array_slice($words, 0, $half));
            $text2 = implode(' ', array_slice($words, $half));
        } else {
            $half = (int) ceil(count($parts) / 2);
            $text1 = trim(implode('', array_slice($parts, 0, $half)));
            $text2 = trim(implode('', array_slice($parts, $half)));
        }

        if ($text1 === '' || $text2 === '') {
            return [$seg];
        }

        $totalLen = mb_strlen($text1) + mb_strlen($text2);
        $ratio = mb_strlen($text1) / $totalLen;
        $totalDur = $seg['end'] - $seg['start'];
        $mid = $seg['start'] + ($totalDur * $ratio);

        return [
            ['start' => $seg['start'], 'end' => $mid, 'text' => $text1],
            ['start' => $mid, 'end' => $seg['end'], 'text' => $text2],
        ];
    }

    protected function mergeShortSubtitles(array $segments): array
    {
        $result = [];
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            $seg = $segments[$i];

            if ($i < $count - 1 && $this->shouldMerge($seg, $segments[$i + 1])) {
                $result[] = [
                    'start' => $seg['start'],
                    'end' => $segments[$i + 1]['end'],
                    'text' => trim($seg['text'] . ' ' . $segments[$i + 1]['text']),
                ];
                $i++;
            } else {
                $result[] = $seg;
            }
        }

        return $result;
    }

    protected function shouldMerge(array $seg, array $next): bool
    {
        $duration = $seg['end'] - $seg['start'];
        $textLen = mb_strlen($seg['text']);

        if ($duration >= 0.6 && $textLen >= 8) {
            return false;
        }

        $gap = $next['start'] - $seg['end'];
        if ($gap > 2.0) {
            return false;
        }

        return true;
    }

    protected function estimateDuration(string $text): float
    {
        $chars = mb_strlen($text);
        $words = str_word_count($text);

        $charDuration = $chars / $this->charsPerSec;
        $wordDuration = $words > 0 ? $words / $this->wordsPerSec : 0;

        $punctuationPause = substr_count($text, ',') * 0.12
            + substr_count($text, '.') * 0.18
            + substr_count($text, '?') * 0.18
            + substr_count($text, '!') * 0.18;

        return max($charDuration, $wordDuration) + $punctuationPause;
    }

    public function parseTimestamp(string $ts): float
    {
        $ts = str_replace(',', '.', trim($ts));

        if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{3})$/', $ts, $m)) {
            return 0.0;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
    }

    public function formatTimestamp(float $seconds): string
    {
        $seconds = max(0, $seconds);

        $h = floor($seconds / 3600);
        $seconds -= $h * 3600;

        $m = floor($seconds / 60);
        $seconds -= $m * 60;

        $s = floor($seconds);
        $ms = round(($seconds - $s) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    protected function buildSrt(array $segs): string
    {
        $blocks = [];

        foreach ($segs as $i => $seg) {
            $idx = $i + 1;
            $start = $this->formatTimestamp($seg['start']);
            $end = $this->formatTimestamp($seg['end']);

            $blocks[] = "{$idx}\n{$start} --> {$end}\n{$seg['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SrtTimeRedistributionServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS (205 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SrtTimeRedistributionService.php tests/Unit/SrtTimeRedistributionServiceTest.php
git commit -m "Add SrtTimeRedistributionService for dubbing-timing retiming"
```

---

### Task 7: `SrtGenerateJob` model + migration + `ProcessSrtGenerate` job

**Files:**
- Create: `database/migrations/2026_07_31_000003_create_srt_generate_jobs_table.php`
- Create: `app/Models/SrtGenerateJob.php`
- Create: `database/factories/SrtGenerateJobFactory.php`
- Create: `app/Jobs/ProcessSrtGenerate.php`
- Test: `tests/Feature/Jobs/ProcessSrtGenerateTest.php`

**Interfaces:**
- Produces: `SrtGenerateJob` model (fillable: `user_id, original_filename, language, srt_content, duration_seconds, status, stage, error, characters_used, credits_deducted`), `ProcessSrtGenerate::dispatch(SrtGenerateJob $job, string $audioFilePath, string $audioFileName, ?string $language)`.
- Consumes: `GroqService::transcribeRaw()` (Task 2), `SrtParserService::sanitizeSrt()`/`parse()` (Phase 3A Task 2), `CreditService::calculateSrtTranslateCredits()` (Phase 1), `User::deductCredits()` (Phase 1).
- Consumed by: `SrtGenerateController` (Task 9).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessSrtGenerateTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessSrtGenerate;
use App\Models\SrtGenerateJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessSrtGenerateTest`
Expected: FAIL — `App\Jobs\ProcessSrtGenerate` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_31_000003_create_srt_generate_jobs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srt_generate_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('original_filename');
            $table->string('language', 10)->nullable();

            $table->longText('srt_content')->nullable();
            $table->integer('duration_seconds')->nullable();

            $table->string('status')->default('queued');
            $table->string('stage')->default('queued');
            $table->text('error')->nullable();

            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srt_generate_jobs');
    }
};
```

- [ ] **Step 4: Write the model and factory**

Create `app/Models/SrtGenerateJob.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SrtGenerateJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'original_filename', 'language', 'srt_content',
        'duration_seconds', 'status', 'stage', 'error',
        'characters_used', 'credits_deducted',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'characters_used' => 'integer',
        'credits_deducted' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

Create `database/factories/SrtGenerateJobFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SrtGenerateJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SrtGenerateJobFactory extends Factory
{
    protected $model = SrtGenerateJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'audio.mp3',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
```

- [ ] **Step 5: Write the job**

Create `app/Jobs/ProcessSrtGenerate.php`:

```php
<?php

namespace App\Jobs;

use App\Models\SrtGenerateJob;
use App\Services\CreditService;
use App\Services\GroqService;
use App\Services\SrtParserService;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSrtGenerate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    protected SrtGenerateJob $generateJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected ?string $language;

    public function __construct(
        SrtGenerateJob $generateJob,
        string $audioFilePath,
        string $audioFileName,
        ?string $language = null
    ) {
        $this->generateJob = $generateJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->language = $language;
    }

    public function handle(GroqService $groq): void
    {
        $job = $this->generateJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            return;
        }

        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $srtContent = $groq->transcribeRaw($this->audioFilePath, $this->audioFileName, $this->language);
        } catch (\Throwable $e) {
            Log::error('SrtGenerate STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update(['stage' => 'merging']);

        try {
            $srtParser = app(SrtParserService::class);
            $srtContent = $srtParser->sanitizeSrt($srtContent);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $parsed = $srtParser->parse($srtContent);
            $charactersUsed = $parsed['total_characters'];
            $creditsNeeded = CreditService::calculateSrtTranslateCredits($charactersUsed);

            if ($creditsNeeded > 0) {
                $deducted = $user->deductCredits($creditsNeeded, "SRT Generation ({$charactersUsed} chars)", 'srt_generate', $job->id);

                if (!$deducted) {
                    $job->update([
                        'srt_content' => $srtContent,
                        'characters_used' => $charactersUsed,
                        'status' => 'failed',
                        'stage' => 'done',
                        'error' => 'Không đủ credit. Cần ' . $creditsNeeded . ' credits.',
                    ]);
                    $this->cleanup();
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::error('SrtGenerate credit deduction failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'done', 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_content' => $srtContent,
            'characters_used' => $charactersUsed ?? 0,
            'credits_deducted' => $creditsNeeded ?? 0,
            'status' => 'completed',
            'stage' => 'done',
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessSrtGenerate job failed permanently', ['job_id' => $this->generateJob->id, 'error' => $exception?->getMessage()]);

        $this->generateJob->update([
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

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=ProcessSrtGenerateTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (209 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_31_000003_create_srt_generate_jobs_table.php app/Models/SrtGenerateJob.php database/factories/SrtGenerateJobFactory.php app/Jobs/ProcessSrtGenerate.php tests/Feature/Jobs/ProcessSrtGenerateTest.php
git commit -m "Add SrtGenerateJob model and ProcessSrtGenerate background job"
```

---

### Task 8: `SrtTranslateJob` model + migration + `ProcessSrtTranslate` job

**Files:**
- Create: `database/migrations/2026_07_31_000004_create_srt_translate_jobs_table.php`
- Create: `app/Models/SrtTranslateJob.php`
- Create: `database/factories/SrtTranslateJobFactory.php`
- Create: `app/Jobs/ProcessSrtTranslate.php`
- Test: `tests/Feature/Jobs/ProcessSrtTranslateTest.php`

**Interfaces:**
- Produces: `SrtTranslateJob` model (fillable: `user_id, target_language, source_language, srt_original, srt_translated, status, stage, error, characters_used, credits_deducted`), `ProcessSrtTranslate::dispatch(SrtTranslateJob $job, string $audioFilePath, string $audioFileName, array $params)`.
- Consumes: `GroqService::transcribe()` (Task 2), `OpenRouterService::translate()` (Task 3), `SrtChunkTranslationService::translate()` (Task 4), `SrtTimeRedistributionService::redistribute()` (Task 6), `SrtParserService::sanitizeSrt()`/`parse()` (Phase 3A), `CreditService::calculateSrtTranslateCredits()`, `User::deductCredits()`.
- Consumed by: `SrtTranslateController` (Task 10).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessSrtTranslateTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessSrtTranslate;
use App\Models\SrtTranslateJob;
use App\Models\User;
use App\Services\GroqService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->handle(app(GroqService::class), app(OpenRouterService::class));

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
            ->handle(app(GroqService::class), app(OpenRouterService::class));

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
            ->handle(app(GroqService::class), app(OpenRouterService::class));

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
            ->handle(app(GroqService::class), app(OpenRouterService::class));

        $fresh = $job->fresh();
        $this->assertEquals('failed', $fresh->status);
        $this->assertStringContainsString('Không đủ credit', $fresh->error);
        $this->assertEquals(1, $user->fresh()->monthly_credits);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessSrtTranslateTest`
Expected: FAIL — `App\Jobs\ProcessSrtTranslate` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_31_000004_create_srt_translate_jobs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srt_translate_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('target_language');

            $table->string('source_language')->nullable();
            $table->longText('srt_original')->nullable();
            $table->longText('srt_translated')->nullable();

            $table->string('status')->default('queued');
            $table->string('stage')->default('queued');
            $table->text('error')->nullable();

            $table->integer('characters_used')->default(0);
            $table->integer('credits_deducted')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srt_translate_jobs');
    }
};
```

- [ ] **Step 4: Write the model and factory**

Create `app/Models/SrtTranslateJob.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SrtTranslateJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'target_language', 'source_language',
        'srt_original', 'srt_translated', 'status', 'stage', 'error',
        'characters_used', 'credits_deducted',
    ];

    protected $casts = [
        'characters_used' => 'integer',
        'credits_deducted' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

Create `database/factories/SrtTranslateJobFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SrtTranslateJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SrtTranslateJobFactory extends Factory
{
    protected $model = SrtTranslateJob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'target_language' => 'vi',
            'status' => 'queued',
            'stage' => 'queued',
        ];
    }
}
```

- [ ] **Step 5: Write the job**

Create `app/Jobs/ProcessSrtTranslate.php`:

```php
<?php

namespace App\Jobs;

use App\Models\SrtTranslateJob;
use App\Services\CreditService;
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

class ProcessSrtTranslate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    protected SrtTranslateJob $translateJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected array $params;

    public function __construct(
        SrtTranslateJob $translateJob,
        string $audioFilePath,
        string $audioFileName,
        array $params
    ) {
        $this->translateJob = $translateJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->params = $params;
    }

    public function handle(GroqService $groq, OpenRouterService $openRouter): void
    {
        $job = $this->translateJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            return;
        }

        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $fakeFile = new \Illuminate\Http\UploadedFile($this->audioFilePath, $this->audioFileName, null, null, true);
            $srtOriginal = $groq->transcribe($fakeFile);
        } catch (\Throwable $e) {
            Log::error('SrtTranslate STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update(['srt_original' => $srtOriginal, 'stage' => 'translating']);

        try {
            $chunkTranslator = app(SrtChunkTranslationService::class);
            $srtTranslated = $chunkTranslator->translate(
                $srtOriginal,
                $this->params['target_language'],
                fn(string $chunk, string $lang, string $context = '') => $openRouter->translate($chunk, $lang, 'srt', $context)
            );
        } catch (\Throwable $e) {
            Log::error('SrtTranslate translate failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'translating', 'error' => 'Translation failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        try {
            $redistributor = app(SrtTimeRedistributionService::class);
            $srtTranslated = $redistributor->redistribute($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT redistribution skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $srtTranslated = $srtParser->sanitizeSrt($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $parsed = $srtParser->parse($srtTranslated);
            $charactersUsed = $parsed['total_characters'];
            $creditsNeeded = CreditService::calculateSrtTranslateCredits($charactersUsed);

            if ($creditsNeeded > 0) {
                $deducted = $user->deductCredits(
                    $creditsNeeded,
                    "SRT Translation ({$charactersUsed} chars → {$this->params['target_language']})",
                    'srt_translate',
                    $job->id
                );

                if (!$deducted) {
                    $job->update([
                        'srt_translated' => $srtTranslated,
                        'characters_used' => $charactersUsed,
                        'status' => 'failed',
                        'stage' => 'done',
                        'error' => 'Không đủ credit. Cần ' . $creditsNeeded . ' credits.',
                    ]);
                    $this->cleanup();
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::error('SrtTranslate credit deduction failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'done', 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_translated' => $srtTranslated,
            'characters_used' => $charactersUsed ?? 0,
            'credits_deducted' => $creditsNeeded ?? 0,
            'status' => 'completed',
            'stage' => 'done',
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessSrtTranslate job failed permanently', ['job_id' => $this->translateJob->id, 'error' => $exception?->getMessage()]);

        $this->translateJob->update([
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

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan migrate:fresh`
Run: `php artisan test --filter=ProcessSrtTranslateTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (214 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_31_000004_create_srt_translate_jobs_table.php app/Models/SrtTranslateJob.php database/factories/SrtTranslateJobFactory.php app/Jobs/ProcessSrtTranslate.php tests/Feature/Jobs/ProcessSrtTranslateTest.php
git commit -m "Add SrtTranslateJob model and ProcessSrtTranslate background job"
```

---

### Task 9: `SrtGenerateController` + routes

**Files:**
- Create: `app/Http/Controllers/API/SrtGenerateController.php`
- Modify: `routes/api.php` (add routes inside the existing `tool` group from Phase 2)
- Test: `tests/Feature/Tool/SrtGenerateControllerTest.php`

**Interfaces:**
- Produces: `POST /api/tool/generate-srt`, `GET /api/tool/generate-srt/status/{id}`.
- Consumes: `SrtGenerateJob`, `ProcessSrtGenerate` (Task 7).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/SrtGenerateControllerTest.php`:

```php
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

    private function premiumUser(): User
    {
        return User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SrtGenerateControllerTest`
Expected: FAIL — 404 on `/api/tool/generate-srt`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/SrtGenerateController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSrtGenerate;
use App\Models\SrtGenerateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SrtGenerateController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:61440|mimes:mp3,wav,m4a',
            'language' => 'nullable|string|max:10',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        $file = $request->file('file');
        $tempPath = $file->store('srt-generate-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        $job = SrtGenerateJob::create([
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'language' => $request->input('language'),
            'status' => 'queued',
            'stage' => 'queued',
        ]);

        ProcessSrtGenerate::dispatch($job, $fullTempPath, $file->getClientOriginalName(), $request->input('language'));

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/generate-srt/status/' . $job->id . ' for progress.',
        ], 202);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = SrtGenerateJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        return response()->json($this->formatJobResponse($job));
    }

    protected function formatJobResponse(SrtGenerateJob $job): array
    {
        return [
            'success' => $job->status !== 'failed',
            'job_id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'is_final' => in_array($job->status, ['completed', 'failed']),
            'original_filename' => $job->original_filename,
            'language' => $job->language,
            'srt_content' => $job->srt_content,
            'characters_used' => $job->characters_used,
            'credits_deducted' => $job->credits_deducted,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php`:

```php
use App\Http\Controllers\API\SrtGenerateController;

    Route::post('/generate-srt', [SrtGenerateController::class, 'generate'])->middleware(['throttle:3,1,generate-srt', 'email.verified']);
    Route::get('/generate-srt/status/{id}', [SrtGenerateController::class, 'status'])->where('id', '[0-9]+');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SrtGenerateControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (219 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/SrtGenerateController.php routes/api.php tests/Feature/Tool/SrtGenerateControllerTest.php
git commit -m "Add SrtGenerateController for audio-to-SRT background pipeline"
```

---

### Task 10: `SrtTranslateController` + routes

**Files:**
- Create: `app/Http/Controllers/API/SrtTranslateController.php`
- Modify: `routes/api.php` (add routes inside the existing `tool` group)
- Test: `tests/Feature/Tool/SrtTranslateControllerTest.php`

**Interfaces:**
- Produces: `POST /api/tool/translate-srt`, `GET /api/tool/translate-srt/status/{id}`.
- Consumes: `SrtTranslateJob`, `ProcessSrtTranslate` (Task 8).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tool/SrtTranslateControllerTest.php`:

```php
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

    private function premiumUser(): User
    {
        return User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(10)]);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SrtTranslateControllerTest`
Expected: FAIL — 404 on `/api/tool/translate-srt`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/SrtTranslateController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSrtTranslate;
use App\Models\SrtTranslateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SrtTranslateController extends Controller
{
    public function translate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a',
            'target_language' => 'required|string|max:10',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        $file = $request->file('file');
        $tempPath = $file->store('srt-translate-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        $job = SrtTranslateJob::create([
            'user_id' => $user->id,
            'target_language' => $request->input('target_language'),
            'status' => 'queued',
            'stage' => 'queued',
        ]);

        ProcessSrtTranslate::dispatch(
            $job,
            $fullTempPath,
            $file->getClientOriginalName(),
            ['target_language' => $request->input('target_language')]
        );

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/translate-srt/status/' . $job->id . ' for progress.',
        ], 202);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = SrtTranslateJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        return response()->json($this->formatJobResponse($job));
    }

    protected function formatJobResponse(SrtTranslateJob $job): array
    {
        return [
            'success' => $job->status !== 'failed',
            'job_id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'is_final' => in_array($job->status, ['completed', 'failed']),
            'target_language' => $job->target_language,
            'characters_used' => $job->characters_used,
            'credits_deducted' => $job->credits_deducted,
            'srt_original' => $job->srt_original,
            'srt_translated' => $job->srt_translated,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Wire the routes**

Add inside the existing `tool` group in `routes/api.php`:

```php
use App\Http\Controllers\API\SrtTranslateController;

    Route::post('/translate-srt', [SrtTranslateController::class, 'translate'])->middleware(['throttle:3,1,translate-srt', 'email.verified']);
    Route::get('/translate-srt/status/{id}', [SrtTranslateController::class, 'status'])->where('id', '[0-9]+');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SrtTranslateControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (224 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/SrtTranslateController.php routes/api.php tests/Feature/Tool/SrtTranslateControllerTest.php
git commit -m "Add SrtTranslateController for audio-to-translated-SRT background pipeline"
```

---

## What's next

Phase 3B ships a complete, independently testable STT/translation pipeline on top of Phase 3A's TTS foundation — reusing `SrtParserService` unmodified and following the exact same credit-deduction conventions established there. Phase 3C (Video Dub — the full Audio → STT → Translate → TTS pipeline combining everything from 3A and 3B, plus `VideoDubJob`/`ProcessVideoDub`) gets its own plan document once this one is executed and verified.
