# Phase 3D: Script/Scene Generation + Image Generation API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add AI-powered video-script generation, script-to-scene splitting, and a new OpenAI-compatible image-generation API endpoint with admin-configurable provider settings.

**Architecture:** Four independent, stateless, synchronous controllers — no models, no migrations, no queued jobs. `ScriptService` and `SceneService` are ported near-verbatim from the source project (both call OpenRouter/Gemini directly). `OpenAiImageService` is new: it calls an admin-configured OpenAI-compatible `/images/generations` endpoint and persists the resulting images to public storage. Credit gating for image generation is entirely client-orchestrated via the existing `ToolFeatureCreditController` 2-phase flow (`deduct-feature`/`confirm-feature`) — this phase adds a `CreditService::FEATURE_PRICING['image_generation']` entry that reads its per-image price dynamically from `SystemSetting` (admin-configurable, no hardcoded constant), plus a new admin settings page to manage the four `image_gen_*` `SystemSetting` keys.

**Tech Stack:** Laravel 10 (from Phases 1-3C), `Illuminate\Support\Facades\Http` (`Http::fake()` in every test, matching every prior phase), `Illuminate\Support\Facades\Storage` (`public` disk, already configured in `config/filesystems.php`), Blade + `admin.layout` (Phase 3C) for the new admin settings page.

## Global Constraints

- Every inline `throttle:` middleware MUST carry an explicit, unique 3rd segment (e.g. `throttle:5,1,generate-script`) — the source project's `/generate-script` and `/generate-scenes` routes use bare `throttle:5,1`/`throttle:3,1` with no 3rd segment; do NOT port that verbatim, add the segment. This bug class has recurred multiple times in this project.
- Vietnamese user-facing error strings, matching every other controller in this project (`'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'`).
- No credit deduction happens inside `ScriptController`, `SceneController`, or `ImageGenController` — these three endpoints are premium-gated only; any credit accounting is the responsibility of the (out-of-scope) desktop client via `/api/tool/credits/deduct-feature` and `/api/tool/credits/confirm-feature`, which already exist (Phase 1/2) and must not be modified except for the one `CreditService::calculateFeatureCredits()` change in Task 4.
- `PexelsController`/`PexelsService`/`StockMediaController`/`StockMediaService` are explicitly OUT of scope for this phase — do not port them.
- The image-generation feature's per-image credit price (`image_gen_credits_per_image`) is admin-configurable via `SystemSetting`, defaulting to `200` if never set — it is NEVER a hardcoded PHP constant.
- `ImageGenController::generate()` has an `isPremium()` gate (this is a deliberate addition beyond the source spec's literal text — since credit deduction for this endpoint is entirely client-orchestrated with no enforcement at the endpoint itself, the premium gate is the one server-side check preventing a free-tier user from calling it directly and skipping the client's credit dance).
- All four `image_gen_*` `SystemSetting` keys follow the exact `SystemSetting::getValue/setValue` pattern already used for `genmax_api_key` (`app/Models/SystemSetting.php`) — read the existing `getGenMaxApiKey()`/`setGenMaxApiKey()` methods there before writing Task 3's methods.

---

### Task 1: `ScriptService` + `GenerateScriptRequest` + `ScriptController`

**Files:**
- Create: `app/Services/ScriptService.php`
- Create: `app/Http/Requests/GenerateScriptRequest.php`
- Create: `app/Http/Controllers/API/ScriptController.php`
- Modify: `routes/api.php` (add inside the existing `tool` prefix group)
- Test: `tests/Unit/ScriptServiceTest.php`, `tests/Feature/Tool/ScriptControllerTest.php`

**Interfaces:**
- Consumes: `config('services.openrouter.api_key')` (Phase 3B, already configured — same key `OpenRouterService` uses), `User::isPremium()` (Phase 1).
- Produces: `ScriptService::generate(string $topic, ?int $wordCount, string $context, string $language, ?int $duration): string` (throws `\RuntimeException` on API failure after retries), `ScriptService::countWords(string $text): int` (public static, Unicode-safe word counting). `POST /api/tool/generate-script`. Not consumed by any other task in this phase.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ScriptServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\ScriptService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScriptServiceTest extends TestCase
{
    public function test_generate_returns_single_segment_for_short_script(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'A short script about cats, generated for a video.']]],
            ], 200),
        ]);

        $service = new ScriptService();
        $result = $service->generate('cats', 20, 'vui vẻ', 'Tiếng Việt', null);

        $this->assertStringContainsString('cats', $result);
        Http::assertSentCount(1);
    }

    public function test_generate_estimates_word_count_from_duration_when_word_count_is_null(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Generated script text.']]]], 200)]);

        $service = new ScriptService();
        // duration=60s at 2.5 words/sec average -> ~150 words, still under the 400-word
        // chunking threshold, so this should be a single API call (no outline/merge).
        $service->generate('dogs', null, 'nghiêm túc', 'Tiếng Việt', 60);

        Http::assertSentCount(1);
    }

    public function test_generate_chunks_long_scripts_with_outline_and_merge(): void
    {
        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $body = json_decode($request->body(), true);
            $prompt = $body['messages'][0]['content'];

            if (str_contains($prompt, 'Create a brief outline')) {
                return Http::response(['choices' => [['message' => ['content' => "1. Hook\n2. Middle\n3. Conclusion"]]]], 200);
            }

            if (str_contains($prompt, 'professional script editor')) {
                return Http::response(['choices' => [['message' => ['content' => 'Final merged script covering all three segments.']]]], 200);
            }

            return Http::response(['choices' => [['message' => ['content' => str_repeat('word ', 150)]]]], 200);
        });

        $service = new ScriptService();
        // 900 target words > 400-word MAX_WORDS_PER_SEGMENT -> chunked path:
        // 1 outline call + 3 segment calls + 1 merge call (3+ segments) = 5 calls.
        $result = $service->generate('space exploration', 900, 'truyền cảm hứng', 'English', null);

        $this->assertEquals('Final merged script covering all three segments.', $result);
        Http::assertSentCount(5);
        $this->assertEquals(5, $callCount);
    }

    public function test_generate_throws_after_exhausting_retries_on_persistent_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'server error']], 500)]);

        $service = new ScriptService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Script generation failed after multiple attempts. Please try again later.');

        $service->generate('topic', 20, 'context', 'language', null);
    }

    public function test_count_words_handles_vietnamese_text(): void
    {
        $this->assertEquals(4, ScriptService::countWords('Xin chào thế giới'));
        $this->assertEquals(0, ScriptService::countWords('   '));
    }
}
```

Create `tests/Feature/Tool/ScriptControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScriptControllerTest extends TestCase
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
        ], $attributes));
    }

    public function test_generate_returns_script_for_premium_user(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'Generated script.']]]], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', [
                'topic' => 'cats',
                'word_count' => 20,
                'context' => 'vui vẻ',
                'language' => 'Tiếng Việt',
            ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.script', 'Generated script.');
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', [
                'topic' => 'cats', 'word_count' => 20, 'context' => 'vui vẻ', 'language' => 'Tiếng Việt',
            ])
            ->assertStatus(403);
    }

    public function test_generate_requires_topic(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['context' => 'vui vẻ', 'language' => 'Tiếng Việt', 'word_count' => 20])
            ->assertStatus(422);
    }

    public function test_generate_requires_word_count_or_duration(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['topic' => 'cats', 'context' => 'vui vẻ', 'language' => 'Tiếng Việt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('word_count');
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-script', ['topic' => 'cats', 'word_count' => 20, 'context' => 'vui vẻ', 'language' => 'Tiếng Việt'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ScriptServiceTest` and `php artisan test --filter=ScriptControllerTest`
Expected: FAIL (`Class "App\Services\ScriptService" not found`, then route not defined once the service exists)

- [ ] **Step 3: Write `ScriptService`**

Create `app/Services/ScriptService.php` — verbatim from the source project (`G:\esp\ESP32_FULL\laravel\app\Services\ScriptService.php`), reading the API key from `config('services.openrouter.api_key')` in the constructor exactly as the source does:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ScriptService — generates short-video scripts via OpenRouter (Gemini model).
 *
 * Architecture:
 *   Controller -> ScriptService -> OpenRouter API (google/gemini-2.0-flash-001)
 *
 * Key features:
 *   - Auto-calculates word count from video duration (2-3 words/second)
 *   - Splits long scripts into segments to stay within token limits
 *   - Generates an outline first so segments stay on-topic
 *   - Merges segments into a single natural paragraph
 *   - Retry mechanism for transient API failures
 */
class ScriptService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    protected string $model   = 'google/gemini-2.0-flash-001';

    private const WORDS_PER_SECOND_LOW  = 2;
    private const WORDS_PER_SECOND_HIGH = 3;
    private const MAX_WORDS_PER_SEGMENT = 400;
    private const MAX_RETRIES  = 3;
    private const RETRY_DELAY  = 2;
    private const PREVIOUS_CONTEXT_WORDS = 50;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function generate(
        string $topic,
        ?int   $wordCount,
        string $context,
        string $language,
        ?int   $duration
    ): string {
        $targetWords = $this->resolveWordCount($wordCount, $duration);

        Log::info('ScriptService: starting generation', [
            'topic' => $topic, 'target_words' => $targetWords, 'context' => $context,
            'language' => $language, 'duration' => $duration,
        ]);

        if ($targetWords <= self::MAX_WORDS_PER_SEGMENT) {
            return $this->generateSegment($topic, $targetWords, $context, $language);
        }

        return $this->generateChunked($topic, $targetWords, $context, $language);
    }

    public static function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private function resolveWordCount(?int $wordCount, ?int $duration): int
    {
        if ($wordCount && $wordCount > 0) {
            return $wordCount;
        }

        $avg = (self::WORDS_PER_SECOND_LOW + self::WORDS_PER_SECOND_HIGH) / 2;
        $estimated = (int) ceil($duration * $avg);

        Log::info("ScriptService: estimated word count from duration", [
            'duration_sec' => $duration, 'estimated_words' => $estimated,
        ]);

        return max($estimated, 10);
    }

    private function generateSegment(
        string $topic,
        int    $wordCount,
        string $context,
        string $language,
        int    $segmentIndex = 0,
        int    $totalSegments = 1,
        string $previousContent = '',
        string $outline = ''
    ): string {
        $prompt = $this->buildPrompt(
            $topic, $wordCount, $context, $language,
            $segmentIndex, $totalSegments, $previousContent, $outline
        );

        return $this->callWithRetry($prompt);
    }

    private function generateOutline(
        string $topic,
        int    $segmentCount,
        int    $targetWords,
        string $context,
        string $language
    ): string {
        $prompt = <<<PROMPT
You are a professional scriptwriter for short-form video content.

TASK:
Create a brief outline for a {$targetWords}-word video script about: "{$topic}"

REQUIREMENTS:
- Language: {$language}
- Tone/mood: {$context}
- The script will be written in {$segmentCount} segments
- For each segment, write ONE short bullet point describing what that segment should cover
- Segment 1 should hook the viewer
- The last segment should conclude memorably
- Middle segments should develop the topic logically

FORMAT:
Return ONLY the outline as a numbered list, one line per segment.
Example:
1. Hook - introduce the surprising fact about X
2. Explain why X matters in daily life
3. Conclude with actionable advice

OUTPUT:
Return ONLY the numbered list - nothing else.
PROMPT;

        $outline = $this->callWithRetry($prompt);

        Log::info('ScriptService: outline generated', ['outline' => $outline]);

        return $outline;
    }

    private function generateChunked(
        string $topic,
        int    $targetWords,
        string $context,
        string $language
    ): string {
        $segmentCount = (int) ceil($targetWords / self::MAX_WORDS_PER_SEGMENT);
        $wordsPerSegment = (int) ceil($targetWords / $segmentCount);

        Log::info("ScriptService: chunked generation", [
            'total_words' => $targetWords, 'segments' => $segmentCount, 'words_per_segment' => $wordsPerSegment,
        ]);

        $outline = $this->generateOutline($topic, $segmentCount, $targetWords, $context, $language);

        $segments = [];
        $previousContent = '';

        for ($i = 0; $i < $segmentCount; $i++) {
            $generatedWords = array_sum(array_map(fn ($s) => self::countWords($s), $segments));
            $remaining = $targetWords - $generatedWords;
            $segWords  = min($wordsPerSegment, $remaining);

            if ($segWords <= 0) {
                break;
            }

            $segment = $this->generateSegment(
                $topic, $segWords, $context, $language,
                $i, $segmentCount, $previousContent, $outline
            );

            $segments[] = $segment;

            $previousContent = implode(' ', array_slice(
                explode(' ', $segment),
                -self::PREVIOUS_CONTEXT_WORDS
            ));

            Log::info("ScriptService: segment " . ($i + 1) . "/{$segmentCount} done", [
                'word_count' => self::countWords($segment),
            ]);
        }

        $merged = $this->mergeSegments($segments, $context, $language);

        Log::info("ScriptService: generation complete", ['total_word_count' => self::countWords($merged)]);

        return $merged;
    }

    private function mergeSegments(array $segments, string $context, string $language): string
    {
        if (count($segments) === 1) {
            return $segments[0];
        }

        if (count($segments) === 2) {
            return implode(' ', $segments);
        }

        $segCount = count($segments);
        $combined = implode("\n\n---SEGMENT BREAK---\n\n", $segments);

        $prompt = <<<PROMPT
You are a professional script editor for short-form video content.

Below are {$segCount} consecutive script segments written in {$language}
with a "{$context}" tone. They form a single script that was generated in parts.

YOUR TASK:
1. Merge all segments into ONE single flowing paragraph.
2. Remove any repetition at segment boundaries.
3. Smooth transitions between segments so the script reads naturally.
4. Maintain the "{$context}" tone throughout.
5. The final script must be suitable for voice-over narration.
6. Keep the total word count approximately the same — do NOT shorten or expand significantly.
7. Return ONLY the merged script text - no headings, no formatting, no explanation.

SEGMENTS:
{$combined}
PROMPT;

        return $this->callWithRetry($prompt);
    }

    private function buildPrompt(
        string $topic,
        int    $wordCount,
        string $context,
        string $language,
        int    $segmentIndex,
        int    $totalSegments,
        string $previousContent,
        string $outline = ''
    ): string {
        $prompt = <<<PROMPT
You are a professional scriptwriter for short-form video content (TikTok, Reels, YouTube Shorts).

TASK:
Write a script segment for a video about: "{$topic}"

REQUIREMENTS:
- Language: {$language}
- Tone/mood: {$context}
- Target length: approximately {$wordCount} words
- The script must sound natural when read aloud (voice-over friendly)
- Write as a SINGLE PARAGRAPH - no bullet points, no headings, no line breaks
- Do NOT include stage directions, speaker labels, or formatting cues
- The content should engage the viewer from the very first sentence
- Use conversational, clear language appropriate for the specified tone

OUTPUT:
Return ONLY the script text - nothing else. No explanations, no titles, no labels.
PROMPT;

        if ($totalSegments > 1) {
            $segmentNumber = $segmentIndex + 1;

            $position = match (true) {
                $segmentIndex === 0                    => 'OPENING (hook the viewer, introduce the topic)',
                $segmentIndex === $totalSegments - 1   => 'CLOSING (conclude, leave a memorable impression)',
                default                                => 'MIDDLE (develop the topic, maintain engagement)',
            };

            $prompt .= "\n\nSEGMENT POSITION: {$position}\nThis is segment {$segmentNumber} of {$totalSegments}.";

            if (!empty($outline)) {
                $prompt .= "\n\nSCRIPT OUTLINE (follow this structure — write ONLY the content for segment {$segmentNumber}):\n{$outline}";
            }

            if (!empty($previousContent)) {
                $prompt .= "\n\nPREVIOUS SEGMENT ENDING (for continuity - do NOT repeat this content):\n\"{$previousContent}\"\n\nContinue naturally from where the previous segment left off.";
            }
        }

        return $prompt;
    }

    private function callWithRetry(string $prompt): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->callOpenRouter($prompt);
            } catch (\RuntimeException $e) {
                $lastException = $e;

                Log::warning("ScriptService: OpenRouter attempt {$attempt}/" . self::MAX_RETRIES . " failed", [
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        Log::error('ScriptService: all retries exhausted', ['error' => $lastException?->getMessage()]);

        throw new \RuntimeException(
            'Script generation failed after multiple attempts. Please try again later.'
        );
    }

    private function callOpenRouter(string $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => config('app.name'),
        ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
            'model'    => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.8,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            Log::error('ScriptService: OpenRouter API error', ['status' => $response->status(), 'error' => $error]);
            throw new \RuntimeException("OpenRouter API error: {$error}");
        }

        $result = $response->json('choices.0.message.content');

        if (empty($result)) {
            throw new \RuntimeException('OpenRouter API returned empty response.');
        }

        $result = trim($result);
        $result = preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $result);

        return trim($result);
    }
}
```

Note the `MAX_RETRIES = 3` sleep-based retry loop makes the retry-exhaustion test slow (`sleep(2)` + `sleep(4)` ≈ 6s) — this matches the source's real behavior, don't shorten it; it's consistent with `SrtChunkTranslationService`'s existing retry test timing in this project.

- [ ] **Step 4: Write `GenerateScriptRequest`**

Create `app/Http/Requests/GenerateScriptRequest.php` — verbatim from source:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'topic'      => 'required|string|max:500',
            'word_count' => 'nullable|integer|min:10|max:5000',
            'context'    => 'required|string|max:100',
            'language'   => 'required|string|max:50',
            'duration'   => 'nullable|integer|min:5|max:600',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->word_count) && empty($this->duration)) {
                $validator->errors()->add(
                    'word_count',
                    'Phải cung cấp word_count hoặc duration (You must provide word_count or duration).'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'topic.required'    => 'Chủ đề là bắt buộc (topic is required).',
            'topic.max'         => 'Chủ đề tối đa 500 ký tự (topic max 500 characters).',
            'word_count.integer' => 'Số từ phải là số nguyên (word_count must be an integer).',
            'word_count.min'    => 'Số từ tối thiểu là 10 (word_count minimum is 10).',
            'word_count.max'    => 'Số từ tối đa là 5000 (word_count maximum is 5000).',
            'context.required'  => 'Ngữ cảnh/giọng điệu là bắt buộc (context/tone is required).',
            'language.required' => 'Ngôn ngữ đầu ra là bắt buộc (language is required).',
            'duration.integer'  => 'Thời lượng phải là số nguyên giây (duration must be integer seconds).',
            'duration.min'      => 'Thời lượng tối thiểu 5 giây (duration minimum 5 seconds).',
            'duration.max'      => 'Thời lượng tối đa 600 giây (duration maximum 600 seconds).',
        ];
    }
}
```

- [ ] **Step 5: Write `ScriptController`**

Create `app/Http/Controllers/API/ScriptController.php` — verbatim from source:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateScriptRequest;
use App\Services\ScriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ScriptController extends Controller
{
    protected ScriptService $scriptService;

    public function __construct(ScriptService $scriptService)
    {
        $this->scriptService = $scriptService;
    }

    public function generate(GenerateScriptRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error'   => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            $script = $this->scriptService->generate(
                topic:     $request->input('topic'),
                wordCount: $request->input('word_count'),
                context:   $request->input('context'),
                language:  $request->input('language'),
                duration:  $request->input('duration'),
            );

            Log::info('ScriptController: script generated successfully', [
                'user_id'    => $user->id,
                'topic'      => $request->input('topic'),
                'word_count' => ScriptService::countWords($script),
            ]);

            return response()->json([
                'success' => true,
                'data'    => ['script' => $script],
            ]);
        } catch (\Throwable $e) {
            Log::error('ScriptController: generation failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, add the import near the other `API\` imports:

```php
use App\Http\Controllers\API\ScriptController;
```

And inside the existing `Route::prefix('tool')->middleware([...])->group(function () { ... });` block, after the `/video-dub` routes:

```php
    Route::post('/generate-script', [ScriptController::class, 'generate'])->middleware(['throttle:5,1,generate-script', 'email.verified']);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=ScriptServiceTest` then `php artisan test --filter=ScriptControllerTest`
Expected: PASS (5 tests, 5 tests)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 9: Commit**

```bash
git add app/Services/ScriptService.php app/Http/Requests/GenerateScriptRequest.php app/Http/Controllers/API/ScriptController.php routes/api.php tests/Unit/ScriptServiceTest.php tests/Feature/Tool/ScriptControllerTest.php
git commit -m "Add ScriptService and ScriptController for AI video script generation"
```

---

### Task 2: `SceneService` + `GenerateSceneRequest` + `SceneController`

**Files:**
- Create: `app/Services/SceneService.php`
- Create: `app/Http/Requests/GenerateSceneRequest.php`
- Create: `app/Http/Controllers/API/SceneController.php`
- Modify: `routes/api.php` (add inside the existing `tool` prefix group)
- Test: `tests/Unit/SceneServiceTest.php`, `tests/Feature/Tool/SceneControllerTest.php`

**Interfaces:**
- Consumes: `config('services.openrouter.api_key')` (same as Task 1), `User::isPremium()` (Phase 1). Fully independent of Task 1 — does not call `ScriptService`.
- Produces: `SceneService::generateScenes(string $script, string $context, int $totalDuration): array` (returns `['total_scenes' => int, 'total_duration' => int, 'scenes' => array]`, throws `\RuntimeException` on failure). `POST /api/tool/generate-scenes`. Not consumed by any other task in this phase.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SceneServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\SceneService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SceneServiceTest extends TestCase
{
    public function test_generate_scenes_returns_scenes_with_proportional_durations(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                ['text' => 'A person walks into a bright office.', 'visual_description' => 'person walking into office', 'duration_weight' => 2],
                ['text' => 'They sit down and open a laptop.', 'visual_description' => 'person sitting with laptop', 'duration_weight' => 3],
                ['text' => 'The screen shows a big success message.', 'visual_description' => 'success message on screen', 'duration_weight' => 5],
            ])]]],
        ], 200)]);

        $service = new SceneService();
        $result = $service->generateScenes(
            'A person walks into a bright office. They sit down and open a laptop. The screen shows a big success message.',
            'lạc quan',
            30
        );

        $this->assertEquals(3, $result['total_scenes']);
        $this->assertEquals(30, $result['total_duration']);
        $this->assertCount(3, $result['scenes']);
        // Proportional: weights 2/3/5 sum to 10 -> 6s/9s/15s (last gets remainder)
        $this->assertEquals(6, $result['scenes'][0]['duration']);
        $this->assertEquals(9, $result['scenes'][1]['duration']);
        $this->assertEquals(15, $result['scenes'][2]['duration']);
        $this->assertEquals(1, $result['scenes'][0]['index']);
        $this->assertArrayHasKey('visual_description', $result['scenes'][0]);
    }

    public function test_generate_scenes_strips_markdown_fences_from_ai_response(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => "```json\n" . json_encode([
                ['text' => 'One scene only.', 'visual_description' => 'a scene', 'duration_weight' => 3],
            ]) . "\n```"]]],
        ], 200)]);

        $service = new SceneService();
        $result = $service->generateScenes('One scene only.', 'nghiêm túc', 15);

        $this->assertEquals(1, $result['total_scenes']);
        $this->assertEquals('One scene only.', $result['scenes'][0]['text']);
    }

    public function test_generate_scenes_throws_when_ai_returns_invalid_json(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'not valid json at all']]]], 200)]);

        $service = new SceneService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI returned invalid scene data. Please try again.');

        $service->generateScenes('Some script text.', 'hài hước', 15);
    }

    public function test_generate_scenes_throws_after_exhausting_retries_on_persistent_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'server error']], 500)]);

        $service = new SceneService();

        $this->expectException(\RuntimeException::class);

        $service->generateScenes('Some script text.', 'hài hước', 15);
    }
}
```

Create `tests/Feature/Tool/SceneControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SceneControllerTest extends TestCase
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
        ], $attributes));
    }

    private function scenesResponse(): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            ['text' => 'A scene.', 'visual_description' => 'a scene', 'duration_weight' => 3],
        ])]]]];
    }

    public function test_generate_returns_scenes_for_premium_user(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->scenesResponse(), 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', [
                'script' => 'A scene.',
                'context' => 'lạc quan',
                'total_duration' => 15,
            ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.total_scenes', 1);
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(403);
    }

    public function test_generate_requires_valid_context_enum(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'not-a-real-context', 'total_duration' => 15])
            ->assertStatus(422);
    }

    public function test_generate_requires_script(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(422);
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-scenes', ['script' => 'A scene.', 'context' => 'lạc quan', 'total_duration' => 15])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SceneServiceTest` and `php artisan test --filter=SceneControllerTest`
Expected: FAIL (`Class "App\Services\SceneService" not found`, then route not defined)

- [ ] **Step 3: Write `SceneService`**

Create `app/Services/SceneService.php` — verbatim from the source project:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SceneService — splits a full script into visual scenes using Gemini AI.
 *
 * Pipeline:
 *   1. Smart chunking (sentence-boundary aware, ~800 words per chunk)
 *   2. Gemini AI extracts scenes from each chunk (grouped visual ideas)
 *   3. Merge scene lists + deduplicate boundary overlaps
 *   4. Post-process: consolidate scenes to match target count
 *   5. Proportional duration calculation to match total_duration
 *
 * Handles scripts up to ~3000 words (~20 min reading time).
 */
class SceneService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    protected string $model   = 'google/gemini-2.0-flash-001';

    private const MAX_WORDS_PER_CHUNK = 800;
    private const OVERLAP_SENTENCES = 2;
    private const TARGET_SCENE_DURATION = 15;
    private const MIN_SCENE_DURATION = 5;
    private const MAX_SCENE_DURATION = 20;
    private const MIN_SCENES = 3;
    private const MAX_SCENES = 30;
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 2;
    private const OVERLAP_SIMILARITY_THRESHOLD = 0.5;
    private const CONSOLIDATION_SIMILARITY_THRESHOLD = 0.3;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function generateScenes(
        string $script,
        string $context,
        int    $totalDuration,
    ): array {
        $targetSceneCount = $this->calculateTargetSceneCount($totalDuration);

        $chunks = $this->splitIntoChunks($script);

        Log::info('SceneService: starting scene generation', [
            'total_words' => $this->countWords($script),
            'chunks' => count($chunks),
            'total_duration' => $totalDuration,
            'target_scene_count' => $targetSceneCount,
            'context' => $context,
        ]);

        $allScenes = [];
        $previousTailSentences = '';
        $totalWords = $this->countWords($script);

        foreach ($chunks as $i => $chunk) {
            $isFirst = ($i === 0);
            $isLast  = ($i === count($chunks) - 1);

            $chunkWords = $this->countWords($chunk);
            $chunkTargetScenes = max(2, (int) round(
                $targetSceneCount * ($chunkWords / max(1, $totalWords))
            ));

            $scenes = $this->extractScenesFromChunk(
                chunk:            $chunk,
                context:           $context,
                chunkIndex:        $i,
                totalChunks:       count($chunks),
                isFirst:           $isFirst,
                isLast:            $isLast,
                overlapContext:    $previousTailSentences,
                targetSceneCount:  $chunkTargetScenes,
                totalDuration:     $totalDuration,
            );

            Log::info("SceneService: chunk " . ($i + 1) . "/" . count($chunks) . " extracted", [
                'scenes_count' => count($scenes),
                'chunk_target' => $chunkTargetScenes,
            ]);

            $allScenes[] = $scenes;

            $sentences = $this->splitSentences($chunk);
            $previousTailSentences = implode(' ', array_slice($sentences, -self::OVERLAP_SENTENCES));
        }

        $mergedScenes = $this->mergeSceneLists($allScenes);

        Log::info('SceneService: scenes merged', ['total_scenes' => count($mergedScenes)]);

        $consolidatedScenes = $this->consolidateScenes($mergedScenes, $targetSceneCount);

        Log::info('SceneService: scenes consolidated', [
            'before' => count($mergedScenes),
            'after'  => count($consolidatedScenes),
            'target' => $targetSceneCount,
        ]);

        $finalScenes = $this->calculateDurations($consolidatedScenes, $totalDuration);

        return [
            'total_scenes'   => count($finalScenes),
            'total_duration' => $totalDuration,
            'scenes'         => $finalScenes,
        ];
    }

    private function calculateTargetSceneCount(int $totalDuration): int
    {
        $target = (int) round($totalDuration / self::TARGET_SCENE_DURATION);

        return max(self::MIN_SCENES, min(self::MAX_SCENES, $target));
    }

    private function splitIntoChunks(string $script): array
    {
        $sentences = $this->splitSentences($script);

        if (empty($sentences)) {
            return [$script];
        }

        $chunks = [];
        $currentChunk = [];
        $currentWordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = $this->countWords($sentence);

            if ($currentWordCount + $sentenceWords > self::MAX_WORDS_PER_CHUNK && !empty($currentChunk)) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
                $currentWordCount = 0;
            }

            $currentChunk[] = $sentence;
            $currentWordCount += $sentenceWords;
        }

        if (!empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }

    private function splitSentences(string $text): array
    {
        $sentences = preg_split(
            '/(?<=[.!?…。！？])\s+/u',
            trim($text),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        return array_map('trim', array_filter($sentences, fn ($s) => trim($s) !== ''));
    }

    private function extractScenesFromChunk(
        string $chunk,
        string $context,
        int    $chunkIndex,
        int    $totalChunks,
        bool   $isFirst,
        bool   $isLast,
        string $overlapContext = '',
        int    $targetSceneCount = 10,
        int    $totalDuration = 180,
    ): array {
        $prompt = $this->buildExtractionPrompt(
            $chunk, $context, $chunkIndex, $totalChunks,
            $isFirst, $isLast, $overlapContext,
            $targetSceneCount, $totalDuration,
        );

        $raw = $this->callWithRetry($prompt);

        return $this->parseSceneJson($raw);
    }

    private function buildExtractionPrompt(
        string $chunk,
        string $context,
        int    $chunkIndex,
        int    $totalChunks,
        bool   $isFirst,
        bool   $isLast,
        string $overlapContext,
        int    $targetSceneCount,
        int    $totalDuration,
    ): string {
        $contextDescriptions = [
            'thân mật'          => 'friendly, warm, intimate',
            'hài hước'          => 'humorous, funny, lighthearted',
            'nghiêm túc'        => 'serious, professional, dramatic',
            'truyền cảm hứng'   => 'inspirational, motivational, uplifting',
            'lạc quan'          => 'optimistic, bright, positive',
            'bi quan'           => 'pessimistic, dark, melancholic',
            'nhiệt tình'        => 'energetic, enthusiastic, dynamic',
        ];

        $contextEn = $contextDescriptions[$context] ?? 'neutral';
        $avgSceneDuration = (int) round($totalDuration / max(1, $targetSceneCount));

        $prompt = <<<PROMPT
You are a professional video editor who splits scripts into visual scenes for short-form video production.

TASK:
Analyze the following script text and split it into VISUAL SCENES.
Each scene should represent a coherent visual segment — group consecutive sentences about the SAME topic, location, or visual idea into ONE scene.

VIDEO DURATION: {$totalDuration} seconds total
TARGET: approximately {$targetSceneCount} scenes (each scene ≈ {$avgSceneDuration} seconds of video)
CONTENT TONE: {$context} ({$contextEn})

RULES:
1. Each scene must contain the EXACT original text from the script (do not rewrite or translate)
2. Group consecutive sentences that share the same visual theme into ONE scene — do NOT create a separate scene for every sentence
3. Only create a new scene when there is a CLEAR change in: subject, location, action, or emotion
4. For each scene, write a "visual_description" in English describing what the viewer should SEE on screen (for stock media search)
5. Assign a "duration_weight" (integer 1–5) based on the importance/length of the scene:
   - 1 = very short transition (2–3 seconds worth)
   - 2 = short scene (~5 seconds)
   - 3 = normal scene (~10-15 seconds)
   - 4 = important scene with more screen time (~15-20 seconds)
   - 5 = key moment, needs the most screen time (~20+ seconds)
6. Keep the original order of the text
7. Only split a sentence into multiple scenes if it contains CLEARLY DIFFERENT visual ideas (e.g. different locations)
8. IMPORTANT: Aim for approximately {$targetSceneCount} scenes. Creating significantly more than this is NOT desired.

OUTPUT FORMAT:
Return ONLY a valid JSON array. No markdown, no explanation, no code blocks.
Example:
[
  {"text": "original script text for scene 1...", "visual_description": "person walking in park at sunset", "duration_weight": 3},
  {"text": "original script text for scene 2...", "visual_description": "close-up of hands typing on laptop", "duration_weight": 4}
]
PROMPT;

        if ($totalChunks > 1) {
            $position = match (true) {
                $isFirst => 'BEGINNING of the script',
                $isLast  => 'END of the script',
                default  => 'MIDDLE of the script (part ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ')',
            };

            $prompt .= "\n\nPOSITION: This text is from the {$position}.";

            if (!empty($overlapContext)) {
                $prompt .= "\n\nPREVIOUS CONTEXT (for reference only — do NOT include this text in your scenes):\n\"{$overlapContext}\"";
            }
        }

        $prompt .= "\n\nSCRIPT TEXT TO ANALYZE:\n{$chunk}";

        return $prompt;
    }

    private function parseSceneJson(string $raw): array
    {
        $raw = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw);
        $raw = preg_replace('/\n?```\s*$/i', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $fixed = preg_replace('/,\s*([}\]])/s', '$1', $raw);
            $decoded = json_decode($fixed, true);
        }

        if (!is_array($decoded)) {
            Log::warning('SceneService: failed to parse AI scene JSON', [
                'raw' => substr($raw, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('AI returned invalid scene data. Please try again.');
        }

        $scenes = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['text'])) {
                continue;
            }

            $scenes[] = [
                'text'               => trim($item['text']),
                'visual_description' => trim($item['visual_description'] ?? ''),
                'duration_weight'    => max(1, min(5, (int) ($item['duration_weight'] ?? 3))),
            ];
        }

        if (empty($scenes)) {
            throw new \RuntimeException('AI returned no valid scenes from chunk.');
        }

        return $scenes;
    }

    private function mergeSceneLists(array $sceneLists): array
    {
        if (count($sceneLists) === 1) {
            return $sceneLists[0];
        }

        $merged = $sceneLists[0];

        for ($i = 1; $i < count($sceneLists); $i++) {
            $currentList = $sceneLists[$i];

            if (empty($currentList)) {
                continue;
            }

            $lastScene  = end($merged);
            $firstScene = $currentList[0];

            $similarity = $this->textSimilarity($lastScene['text'], $firstScene['text']);

            if ($similarity >= self::OVERLAP_SIMILARITY_THRESHOLD) {
                Log::info('SceneService: merging overlapping boundary scenes', [
                    'similarity' => round($similarity, 2),
                    'scene_a'    => substr($lastScene['text'], 0, 60),
                    'scene_b'    => substr($firstScene['text'], 0, 60),
                ]);

                $mergedScene = [
                    'text'               => strlen($lastScene['text']) >= strlen($firstScene['text'])
                                            ? $lastScene['text']
                                            : $firstScene['text'],
                    'visual_description' => $lastScene['visual_description'] ?: $firstScene['visual_description'],
                    'duration_weight'    => max($lastScene['duration_weight'], $firstScene['duration_weight']),
                ];

                array_pop($merged);
                $merged[] = $mergedScene;

                $merged = array_merge($merged, array_slice($currentList, 1));
            } else {
                $merged = array_merge($merged, $currentList);
            }
        }

        return $merged;
    }

    private function textSimilarity(string $a, string $b): float
    {
        $wordsA = array_unique(preg_split('/\s+/u', mb_strtolower(trim($a)), -1, PREG_SPLIT_NO_EMPTY));
        $wordsB = array_unique(preg_split('/\s+/u', mb_strtolower(trim($b)), -1, PREG_SPLIT_NO_EMPTY));

        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union        = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function consolidateScenes(array $scenes, int $targetCount): array
    {
        $maxAllowed = (int) ceil($targetCount * 1.3);

        if (count($scenes) <= $maxAllowed) {
            return $scenes;
        }

        Log::info('SceneService: consolidating scenes', [
            'current' => count($scenes), 'target' => $targetCount, 'max_allowed' => $maxAllowed,
        ]);

        while (count($scenes) > $targetCount && count($scenes) > self::MIN_SCENES) {
            $bestPairIndex = null;
            $bestScore = -1;

            for ($i = 0; $i < count($scenes) - 1; $i++) {
                $similarity = $this->textSimilarity(
                    $scenes[$i]['text'],
                    $scenes[$i + 1]['text']
                );

                $weightBonus = (6 - min($scenes[$i]['duration_weight'], $scenes[$i + 1]['duration_weight'])) * 0.1;

                $score = $similarity + $weightBonus;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPairIndex = $i;
                }
            }

            if ($bestPairIndex === null || $bestScore < self::CONSOLIDATION_SIMILARITY_THRESHOLD) {
                if (count($scenes) > $maxAllowed) {
                    $bestPairIndex = $this->findLowestWeightPair($scenes);
                } else {
                    break;
                }
            }

            $sceneA = $scenes[$bestPairIndex];
            $sceneB = $scenes[$bestPairIndex + 1];

            $mergedScene = [
                'text'               => $sceneA['text'] . ' ' . $sceneB['text'],
                'visual_description' => strlen($sceneA['visual_description']) >= strlen($sceneB['visual_description'])
                                        ? $sceneA['visual_description']
                                        : $sceneB['visual_description'],
                'duration_weight'    => min(5, $sceneA['duration_weight'] + $sceneB['duration_weight']),
            ];

            array_splice($scenes, $bestPairIndex, 2, [$mergedScene]);
        }

        return array_values($scenes);
    }

    private function findLowestWeightPair(array $scenes): int
    {
        $bestIndex = 0;
        $lowestWeight = PHP_INT_MAX;

        for ($i = 0; $i < count($scenes) - 1; $i++) {
            $combined = $scenes[$i]['duration_weight'] + $scenes[$i + 1]['duration_weight'];
            if ($combined < $lowestWeight) {
                $lowestWeight = $combined;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    private function calculateDurations(array $scenes, int $totalDuration): array
    {
        if (empty($scenes)) {
            return [];
        }

        $totalWeight = array_sum(array_column($scenes, 'duration_weight'));

        if ($totalWeight <= 0) {
            $totalWeight = count($scenes);
        }

        $result = [];
        $assignedDuration = 0;

        foreach ($scenes as $i => $scene) {
            $isLast = ($i === count($scenes) - 1);

            if ($isLast) {
                $duration = $totalDuration - $assignedDuration;
            } else {
                $rawDuration = ($scene['duration_weight'] / $totalWeight) * $totalDuration;
                $duration = (int) round($rawDuration);
            }

            $duration = max(self::MIN_SCENE_DURATION, min(self::MAX_SCENE_DURATION, $duration));

            $assignedDuration += $duration;

            $result[] = [
                'index'              => $i + 1,
                'text'               => $scene['text'],
                'visual_description' => $scene['visual_description'],
                'duration'           => $duration,
            ];
        }

        return $result;
    }

    private function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private function callWithRetry(string $prompt): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->callOpenRouter($prompt);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                Log::warning("SceneService: OpenRouter attempt {$attempt}/" . self::MAX_RETRIES . " failed", [
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            'Scene extraction failed after multiple attempts. ' . $lastException?->getMessage()
        );
    }

    private function callOpenRouter(string $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => config('app.name'),
        ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
            'model'    => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            throw new \RuntimeException("OpenRouter API error: {$error}");
        }

        $result = $response->json('choices.0.message.content');

        if (empty($result)) {
            throw new \RuntimeException('OpenRouter API returned empty response.');
        }

        return trim($result);
    }
}
```

- [ ] **Step 4: Write `GenerateSceneRequest`**

Create `app/Http/Requests/GenerateSceneRequest.php` — verbatim from source:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateSceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'script'         => 'required|string|max:20000',
            'context'        => 'required|string|in:thân mật,hài hước,nghiêm túc,truyền cảm hứng,lạc quan,bi quan,nhiệt tình',
            'total_duration' => 'required|integer|min:10|max:1200',
        ];
    }

    public function messages(): array
    {
        return [
            'script.required'         => 'Kịch bản là bắt buộc (script is required).',
            'script.max'              => 'Kịch bản tối đa 20.000 ký tự (script max 20,000 characters).',
            'context.required'        => 'Ngữ cảnh/giọng điệu là bắt buộc (context/tone is required).',
            'context.in'              => 'Ngữ cảnh không hợp lệ (invalid context value).',
            'total_duration.required' => 'Tổng thời lượng video là bắt buộc (total_duration is required).',
            'total_duration.integer'  => 'Thời lượng phải là số nguyên giây (total_duration must be integer seconds).',
            'total_duration.min'      => 'Thời lượng tối thiểu 10 giây (total_duration minimum 10 seconds).',
            'total_duration.max'      => 'Thời lượng tối đa 1200 giây / 20 phút (total_duration maximum 1200 seconds).',
        ];
    }
}
```

- [ ] **Step 5: Write `SceneController`**

Create `app/Http/Controllers/API/SceneController.php` — verbatim from source:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateSceneRequest;
use App\Services\SceneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SceneController extends Controller
{
    protected SceneService $sceneService;

    public function __construct(SceneService $sceneService)
    {
        $this->sceneService = $sceneService;
    }

    public function generate(GenerateSceneRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error'   => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            $result = $this->sceneService->generateScenes(
                script:        $request->input('script'),
                context:       $request->input('context'),
                totalDuration: (int) $request->input('total_duration'),
            );

            Log::info('SceneController: scenes generated successfully', [
                'user_id'      => $user->id,
                'total_scenes' => $result['total_scenes'],
                'total_duration' => $result['total_duration'],
            ]);

            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('SceneController: generation failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\API\SceneController;
```

And inside the `tool` group, after `/generate-script`:

```php
    Route::post('/generate-scenes', [SceneController::class, 'generate'])->middleware(['throttle:3,1,generate-scenes', 'email.verified']);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SceneServiceTest` then `php artisan test --filter=SceneControllerTest`
Expected: PASS (4 tests, 5 tests)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 9: Commit**

```bash
git add app/Services/SceneService.php app/Http/Requests/GenerateSceneRequest.php app/Http/Controllers/API/SceneController.php routes/api.php tests/Unit/SceneServiceTest.php tests/Feature/Tool/SceneControllerTest.php
git commit -m "Add SceneService and SceneController for AI script-to-scene splitting"
```

---

### Task 3: `SystemSetting` image-gen settings + `OpenAiImageService`

**Files:**
- Modify: `app/Models/SystemSetting.php`
- Create: `app/Services/OpenAiImageService.php`
- Test: `tests/Unit/SystemSettingImageGenTest.php`, `tests/Unit/OpenAiImageServiceTest.php`

**Interfaces:**
- Consumes: `SystemSetting::getValue/setValue` (existing, Phase 1).
- Produces: `SystemSetting::getImageGenBaseUrl(): string` (default `'https://api.openai.com/v1'`), `SystemSetting::setImageGenBaseUrl(string $url): static`, `SystemSetting::getImageGenApiKey(): ?string`, `SystemSetting::setImageGenApiKey(string $apiKey): static` (encrypted), `SystemSetting::getImageGenModel(): string` (default `'gpt-image-1'`), `SystemSetting::setImageGenModel(string $model): static`, `SystemSetting::getImageGenCreditsPerImage(): int` (default `200`), `SystemSetting::setImageGenCreditsPerImage(int $credits): static`. `OpenAiImageService::generate(string $prompt, string $size = '1024x1024', int $n = 1): array` (returns an array of public URL strings, throws `\RuntimeException` on missing API key or provider failure). Consumed by `ImageGenController` (Task 4) and `Admin\ToolSettingsController` (Task 5).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SystemSettingImageGenTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSettingImageGenTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_url_defaults_to_openai_when_never_set(): void
    {
        $this->assertEquals('https://api.openai.com/v1', SystemSetting::getImageGenBaseUrl());
    }

    public function test_base_url_can_be_overridden(): void
    {
        SystemSetting::setImageGenBaseUrl('https://my-provider.test/v1');

        $this->assertEquals('https://my-provider.test/v1', SystemSetting::getImageGenBaseUrl());
    }

    public function test_api_key_defaults_to_null_and_is_encrypted_at_rest(): void
    {
        $this->assertNull(SystemSetting::getImageGenApiKey());

        SystemSetting::setImageGenApiKey('sk-secret-123');

        $this->assertEquals('sk-secret-123', SystemSetting::getImageGenApiKey());
        $raw = SystemSetting::where('key', 'image_gen_api_key')->first();
        $this->assertNotEquals('sk-secret-123', $raw->value);
        $this->assertTrue($raw->is_encrypted);
    }

    public function test_model_defaults_to_gpt_image_1(): void
    {
        $this->assertEquals('gpt-image-1', SystemSetting::getImageGenModel());

        SystemSetting::setImageGenModel('dall-e-3');

        $this->assertEquals('dall-e-3', SystemSetting::getImageGenModel());
    }

    public function test_credits_per_image_defaults_to_200_and_is_admin_configurable(): void
    {
        $this->assertEquals(200, SystemSetting::getImageGenCreditsPerImage());

        SystemSetting::setImageGenCreditsPerImage(350);

        $this->assertEquals(350, SystemSetting::getImageGenCreditsPerImage());
    }
}
```

Create `tests/Unit/OpenAiImageServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Services\OpenAiImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiImageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        SystemSetting::setImageGenApiKey('sk-test-key');
    }

    public function test_generate_saves_base64_image_and_returns_public_url(): void
    {
        $fakeImageBytes = base64_encode('fake-png-bytes');
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [['b64_json' => $fakeImageBytes]],
        ], 200)]);

        $service = new OpenAiImageService();
        $urls = $service->generate('a cat wearing a hat');

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/storage/generated-images/', $urls[0]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/images/generations'
                && $request['model'] === 'gpt-image-1'
                && $request['prompt'] === 'a cat wearing a hat'
                && $request['size'] === '1024x1024'
                && $request['n'] === 1
                && $request->hasHeader('Authorization', 'Bearer sk-test-key');
        });
    }

    public function test_generate_downloads_url_image_when_no_base64_present(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => 'https://provider.example/img.png']]], 200),
            'provider.example/*' => Http::response('raw-downloaded-bytes', 200),
        ]);

        $service = new OpenAiImageService();
        $urls = $service->generate('a dog on the beach');

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/storage/generated-images/', $urls[0]);
    }

    public function test_generate_handles_multiple_images(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [
                ['b64_json' => base64_encode('image-one')],
                ['b64_json' => base64_encode('image-two')],
            ],
        ], 200)]);

        $service = new OpenAiImageService();
        $urls = $service->generate('two cats', '512x512', 2);

        $this->assertCount(2, $urls);
        $this->assertNotEquals($urls[0], $urls[1]);
    }

    public function test_generate_respects_configured_base_url_and_model(): void
    {
        SystemSetting::setImageGenBaseUrl('https://custom-provider.test/v1');
        SystemSetting::setImageGenModel('custom-model');
        Http::fake(['custom-provider.test/*' => Http::response(['data' => [['b64_json' => base64_encode('x')]]], 200)]);

        $service = new OpenAiImageService();
        $service->generate('a prompt');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://custom-provider.test/v1/images/generations'
                && $request['model'] === 'custom-model';
        });
    }

    public function test_generate_throws_when_api_key_not_configured(): void
    {
        SystemSetting::setImageGenApiKey('');
        // setImageGenApiKey('') still creates a row with an empty encrypted value;
        // getImageGenApiKey() must treat that the same as "not configured".

        $service = new OpenAiImageService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image generation API key not configured. Please set it in Admin > Tool Settings.');

        $service->generate('a prompt');
    }

    public function test_generate_throws_on_provider_error(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'invalid_request']], 400)]);

        $service = new OpenAiImageService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_request/');

        $service->generate('a prompt');
    }

    public function test_generate_throws_when_response_has_no_images(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

        $service = new OpenAiImageService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image generation API returned no images.');

        $service->generate('a prompt');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SystemSettingImageGenTest` and `php artisan test --filter=OpenAiImageServiceTest`
Expected: FAIL (`Call to undefined method App\Models\SystemSetting::getImageGenBaseUrl()`, then `Class "App\Services\OpenAiImageService" not found`)

- [ ] **Step 3: Add the `SystemSetting` methods**

In `app/Models/SystemSetting.php`, add these methods (after the existing `getGenMaxApiKey`/`setGenMaxApiKey` pair, following the exact same style):

```php
    public static function getImageGenBaseUrl(): string
    {
        return static::getValue('image_gen_base_url', 'https://api.openai.com/v1');
    }

    public static function setImageGenBaseUrl(string $url): static
    {
        return static::setValue('image_gen_base_url', $url, false, 'Image Generation API Base URL');
    }

    public static function getImageGenApiKey(): ?string
    {
        $key = static::getValue('image_gen_api_key');

        return $key === '' ? null : $key;
    }

    public static function setImageGenApiKey(string $apiKey): static
    {
        return static::setValue('image_gen_api_key', $apiKey, true, 'Image Generation API Key');
    }

    public static function getImageGenModel(): string
    {
        return static::getValue('image_gen_model', 'gpt-image-1');
    }

    public static function setImageGenModel(string $model): static
    {
        return static::setValue('image_gen_model', $model, false, 'Image Generation Model');
    }

    public static function getImageGenCreditsPerImage(): int
    {
        return (int) static::getValue('image_gen_credits_per_image', 200);
    }

    public static function setImageGenCreditsPerImage(int $credits): static
    {
        return static::setValue('image_gen_credits_per_image', (string) $credits, false, 'Image Generation Credits Per Image');
    }
```

- [ ] **Step 4: Write `OpenAiImageService`**

Create `app/Services/OpenAiImageService.php`:

```php
<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * OpenAiImageService — generates images via any OpenAI-compatible Images API
 * (base_url/api_key/model configured by admin via SystemSetting, not hardcoded).
 */
class OpenAiImageService
{
    /**
     * @return string[] Public URLs of the saved images.
     *
     * @throws \RuntimeException on missing API key or provider failure.
     */
    public function generate(string $prompt, string $size = '1024x1024', int $n = 1): array
    {
        $apiKey = SystemSetting::getImageGenApiKey();

        if (!$apiKey) {
            throw new \RuntimeException('Image generation API key not configured. Please set it in Admin > Tool Settings.');
        }

        $baseUrl = SystemSetting::getImageGenBaseUrl();
        $model = SystemSetting::getImageGenModel();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->timeout(120)->post("{$baseUrl}/images/generations", [
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => $size,
            'n'      => $n,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            Log::error('OpenAiImageService: provider error', ['status' => $response->status(), 'error' => $error]);
            throw new \RuntimeException("Image generation API error: {$error}");
        }

        $items = $response->json('data', []);

        if (empty($items)) {
            throw new \RuntimeException('Image generation API returned no images.');
        }

        $urls = [];
        foreach ($items as $item) {
            $urls[] = $this->saveImage($item);
        }

        return $urls;
    }

    /**
     * Persist one provider image item (b64_json or url) to public storage
     * and return its public URL.
     *
     * @throws \RuntimeException
     */
    protected function saveImage(array $item): string
    {
        $filename = 'generated-images/' . Str::uuid() . '.png';

        if (!empty($item['b64_json'])) {
            $contents = base64_decode($item['b64_json']);
            Storage::disk('public')->put($filename, $contents);
        } elseif (!empty($item['url'])) {
            $downloaded = Http::timeout(30)->get($item['url']);

            if ($downloaded->failed()) {
                throw new \RuntimeException('Failed to download generated image from provider URL.');
            }

            Storage::disk('public')->put($filename, $downloaded->body());
        } else {
            throw new \RuntimeException('Image generation API returned an item with neither b64_json nor url.');
        }

        return Storage::url($filename);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SystemSettingImageGenTest` then `php artisan test --filter=OpenAiImageServiceTest`
Expected: PASS (5 tests, 7 tests)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add app/Models/SystemSetting.php app/Services/OpenAiImageService.php tests/Unit/SystemSettingImageGenTest.php tests/Unit/OpenAiImageServiceTest.php
git commit -m "Add image-gen SystemSetting keys and OpenAiImageService"
```

---

### Task 4: `GenerateImageRequest` + `ImageGenController` + `CreditService` pricing

**Files:**
- Create: `app/Http/Requests/GenerateImageRequest.php`
- Create: `app/Http/Controllers/API/ImageGenController.php`
- Modify: `app/Services/CreditService.php`
- Modify: `routes/api.php` (add inside the existing `tool` prefix group)
- Test: `tests/Feature/Tool/ImageGenControllerTest.php`, `tests/Unit/CreditServiceTest.php` (extend, don't replace)

**Interfaces:**
- Consumes: `OpenAiImageService::generate(string $prompt, string $size = '1024x1024', int $n = 1): array` (Task 3), `SystemSetting::getImageGenCreditsPerImage(): int` (Task 3), `User::isPremium()` (Phase 1).
- Produces: `POST /api/tool/generate-image`. `CreditService::calculateFeatureCredits('image_generation', int $n): array` (count-based pricing, distinct from `create_video_script`'s per-minute formula — the existing `int $durationSeconds` parameter name is retained for this shared method's signature, but for this feature the value represents the image count `n`, not seconds; see the exact branch below).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Tool/ImageGenControllerTest.php`:

```php
<?php

namespace Tests\Feature\Tool;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageGenControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        SystemSetting::setImageGenApiKey('sk-test-key');
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
        ], $attributes));
    }

    public function test_generate_returns_image_urls_for_premium_user(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-bytes')]]], 200)]);
        $user = $this->premiumUser();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'data.images');
    }

    public function test_generate_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertStatus(403);
    }

    public function test_generate_requires_prompt(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', [])
            ->assertStatus(422);
    }

    public function test_generate_rejects_invalid_size(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat', 'size' => '999x999'])
            ->assertStatus(422);
    }

    public function test_generate_rejects_n_above_four(): void
    {
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat', 'n' => 5])
            ->assertStatus(422);
    }

    public function test_generate_returns_500_on_provider_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        $user = $this->premiumUser();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_generate_does_not_touch_user_credits(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('fake-bytes')]]], 200)]);
        $user = $this->premiumUser(['monthly_credits' => 1000, 'purchased_credits' => 0]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/generate-image', ['prompt' => 'a cat wearing a hat'])
            ->assertOk();

        // Credit deduction for this endpoint is entirely client-orchestrated via
        // /credits/deduct-feature + /credits/confirm-feature — the endpoint itself
        // must never touch the balance.
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }
}
```

Add to `tests/Unit/CreditServiceTest.php` (do not modify the existing test methods):

```php
    public function test_calculate_feature_credits_for_image_generation_uses_configured_price_per_image(): void
    {
        \App\Models\SystemSetting::setImageGenCreditsPerImage(150);

        $result = CreditService::calculateFeatureCredits('image_generation', 3);

        $this->assertEquals([
            'feature' => 'image_generation',
            'duration_seconds' => 3,
            'credits' => 450,
        ], $result);
    }

    public function test_calculate_feature_credits_for_image_generation_uses_default_price_when_unset(): void
    {
        $result = CreditService::calculateFeatureCredits('image_generation', 1);

        $this->assertEquals(200, $result['credits']);
    }

    public function test_calculate_feature_credits_throws_when_image_count_exceeds_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreditService::calculateFeatureCredits('image_generation', 5);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ImageGenControllerTest` (route not defined) and `php artisan test --filter=CreditServiceTest` (the 3 new methods fail — `image_generation` unknown feature)
Expected: FAIL

- [ ] **Step 3: Extend `CreditService::FEATURE_PRICING` and `calculateFeatureCredits()`**

In `app/Services/CreditService.php`, add the `use App\Models\SystemSetting;` import (already present — verify), add `'image_generation'` to `FEATURE_PRICING`, and add the count-based branch to `calculateFeatureCredits()`:

```php
    const FEATURE_PRICING = [
        'create_video_script' => [
            'credits_per_minute' => 140,
            'max_duration_seconds' => 1200,
        ],
        'image_generation' => [
            'max_count' => 4,
        ],
    ];

    public static function calculateFeatureCredits(string $feature, int $durationSeconds): ?array
    {
        if (!isset(self::FEATURE_PRICING[$feature])) {
            return null;
        }

        $pricing = self::FEATURE_PRICING[$feature];

        // image_generation is priced per-image (count-based), not per-minute — the
        // incoming $durationSeconds value represents the image count (n) for this
        // feature specifically, and the credit-per-image rate is admin-configurable
        // via SystemSetting rather than a hardcoded constant.
        if ($feature === 'image_generation') {
            $maxCount = $pricing['max_count'];
            $count = $durationSeconds;

            if ($count < 1 || $count > $maxCount) {
                throw new \InvalidArgumentException(
                    "Image count must be between 1 and {$maxCount} for feature '{$feature}'."
                );
            }

            $creditsPerImage = SystemSetting::getImageGenCreditsPerImage();

            return [
                'feature' => $feature,
                'duration_seconds' => $count,
                'credits' => $count * $creditsPerImage,
            ];
        }

        $maxDuration = $pricing['max_duration_seconds'];

        if ($durationSeconds < 1 || $durationSeconds > $maxDuration) {
            throw new \InvalidArgumentException(
                "Duration must be between 1 and {$maxDuration} seconds for feature '{$feature}'."
            );
        }

        $minutes = ceil($durationSeconds / 60);
        $credits = (int) ($minutes * $pricing['credits_per_minute']);

        return [
            'feature' => $feature,
            'duration_seconds' => $durationSeconds,
            'credits' => $credits,
        ];
    }
```

Leave `getFeaturePricing()` unchanged — it already returns the whole `FEATURE_PRICING` array, which now includes `image_generation` automatically.

- [ ] **Step 4: Write `GenerateImageRequest`**

Create `app/Http/Requests/GenerateImageRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:2000',
            'size'   => 'nullable|string|in:256x256,512x512,1024x1024',
            'n'      => 'nullable|integer|min:1|max:4',
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.required' => 'Mô tả ảnh là bắt buộc (prompt is required).',
            'prompt.max'      => 'Mô tả ảnh tối đa 2000 ký tự (prompt max 2000 characters).',
            'size.in'         => 'Kích thước không hợp lệ (invalid size — must be 256x256, 512x512, or 1024x1024).',
            'n.integer'       => 'Số lượng ảnh phải là số nguyên (n must be an integer).',
            'n.min'           => 'Số lượng ảnh tối thiểu là 1 (n minimum is 1).',
            'n.max'           => 'Số lượng ảnh tối đa là 4 (n maximum is 4).',
        ];
    }
}
```

- [ ] **Step 5: Write `ImageGenController`**

Create `app/Http/Controllers/API/ImageGenController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateImageRequest;
use App\Services\OpenAiImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ImageGenController extends Controller
{
    protected OpenAiImageService $imageService;

    public function __construct(OpenAiImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function generate(GenerateImageRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error'   => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            $images = $this->imageService->generate(
                prompt: $request->input('prompt'),
                size:   $request->input('size', '1024x1024'),
                n:      (int) $request->input('n', 1),
            );

            Log::info('ImageGenController: images generated successfully', [
                'user_id' => $user->id,
                'count'   => count($images),
            ]);

            return response()->json([
                'success' => true,
                'data'    => ['images' => $images],
            ]);
        } catch (\Throwable $e) {
            Log::error('ImageGenController: generation failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\API\ImageGenController;
```

And inside the `tool` group, after `/generate-scenes`:

```php
    Route::post('/generate-image', [ImageGenController::class, 'generate'])->middleware(['throttle:5,1,generate-image', 'email.verified']);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=ImageGenControllerTest` then `php artisan test --filter=CreditServiceTest`
Expected: PASS (7 tests, 8 tests total — 5 existing + 3 new)

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/GenerateImageRequest.php app/Http/Controllers/API/ImageGenController.php app/Services/CreditService.php routes/api.php tests/Feature/Tool/ImageGenControllerTest.php tests/Unit/CreditServiceTest.php
git commit -m "Add ImageGenController and count-based image_generation feature pricing"
```

---

### Task 5: `Admin\ToolSettingsController` + Blade view

**Files:**
- Create: `app/Http/Controllers/Admin/ToolSettingsController.php`
- Create: `resources/views/admin/tool-settings/index.blade.php`
- Modify: `routes/web.php` (add inside the existing `admin` middleware group)
- Test: `tests/Feature/Admin/ToolSettingsControllerTest.php`

**Interfaces:**
- Consumes: `SystemSetting::getImageGenBaseUrl/getImageGenModel/getImageGenCreditsPerImage/getImageGenApiKey/setImageGenBaseUrl/setImageGenModel/setImageGenCreditsPerImage/setImageGenApiKey` (Task 3), `admin.layout` + `admin._partials._breadcrumb` (Phase 3C), the existing `IsAdmin` middleware (aliased `admin`, Phase 1 — default `web` guard, `is_admin` boolean, per `tests/Feature/Admin/AdminLoginTest.php`'s established pattern).
- Produces: `GET /admin/tool-settings` (named `admin.tool-settings.index`), `POST /admin/tool-settings` (named `admin.tool-settings.update`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/ToolSettingsControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_shows_current_settings_without_leaking_the_raw_api_key(): void
    {
        SystemSetting::setImageGenApiKey('sk-super-secret');
        SystemSetting::setImageGenModel('dall-e-3');

        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_model'] === 'dall-e-3' && $settings['image_gen_api_key_set'] === true;
        });
        $response->assertDontSee('sk-super-secret');
    }

    public function test_index_shows_defaults_when_nothing_configured(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/tool-settings');

        $response->assertOk();
        $response->assertViewHas('settings', function ($settings) {
            return $settings['image_gen_base_url'] === 'https://api.openai.com/v1'
                && $settings['image_gen_credits_per_image'] === 200
                && $settings['image_gen_api_key_set'] === false;
        });
    }

    public function test_update_saves_new_settings(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://my-provider.test/v1',
            'image_gen_model' => 'custom-model',
            'image_gen_credits_per_image' => 300,
        ]);

        $response->assertRedirect(route('admin.tool-settings.index'));
        $this->assertEquals('https://my-provider.test/v1', SystemSetting::getImageGenBaseUrl());
        $this->assertEquals('custom-model', SystemSetting::getImageGenModel());
        $this->assertEquals(300, SystemSetting::getImageGenCreditsPerImage());
    }

    public function test_update_only_changes_api_key_when_a_new_one_is_provided(): void
    {
        SystemSetting::setImageGenApiKey('sk-original');

        $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://api.openai.com/v1',
            'image_gen_model' => 'gpt-image-1',
            'image_gen_credits_per_image' => 200,
            'image_gen_api_key' => '',
        ]);

        $this->assertEquals('sk-original', SystemSetting::getImageGenApiKey());

        $this->actingAsAdmin()->post('/admin/tool-settings', [
            'image_gen_base_url' => 'https://api.openai.com/v1',
            'image_gen_model' => 'gpt-image-1',
            'image_gen_credits_per_image' => 200,
            'image_gen_api_key' => 'sk-new-key',
        ]);

        $this->assertEquals('sk-new-key', SystemSetting::getImageGenApiKey());
    }

    public function test_update_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/tool-settings', ['image_gen_base_url' => 'not-a-url'])
            ->assertSessionHasErrors(['image_gen_base_url', 'image_gen_model', 'image_gen_credits_per_image']);
    }

    public function test_index_and_update_reject_unauthenticated_requests(): void
    {
        $this->get('/admin/tool-settings')->assertRedirect();
        $this->post('/admin/tool-settings', [])->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolSettingsControllerTest`
Expected: FAIL (`Class "App\Http\Controllers\Admin\ToolSettingsController" not found` / route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/ToolSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class ToolSettingsController extends Controller
{
    public function index()
    {
        $settings = [
            'image_gen_base_url' => SystemSetting::getImageGenBaseUrl(),
            'image_gen_model' => SystemSetting::getImageGenModel(),
            'image_gen_credits_per_image' => SystemSetting::getImageGenCreditsPerImage(),
            'image_gen_api_key_set' => SystemSetting::getImageGenApiKey() !== null,
        ];

        return view('admin.tool-settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'image_gen_base_url' => 'required|url',
            'image_gen_model' => 'required|string|max:100',
            'image_gen_credits_per_image' => 'required|integer|min:1',
            'image_gen_api_key' => 'nullable|string|max:500',
        ]);

        SystemSetting::setImageGenBaseUrl($request->input('image_gen_base_url'));
        SystemSetting::setImageGenModel($request->input('image_gen_model'));
        SystemSetting::setImageGenCreditsPerImage((int) $request->input('image_gen_credits_per_image'));

        if ($request->filled('image_gen_api_key')) {
            SystemSetting::setImageGenApiKey($request->input('image_gen_api_key'));
        }

        return redirect()->route('admin.tool-settings.index')->with('success', 'Đã lưu cấu hình tạo hình ảnh.');
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/admin/tool-settings/index.blade.php`:

```blade
@extends('admin.layout')

@section('title', 'Tool Settings')
@section('page-title', 'Tool Settings')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tool Settings']]])
@endsection

@section('content')
@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-image"></i> Image Generation</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.tool-settings.update') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label">Base URL</label>
                <input type="text" name="image_gen_base_url" class="form-control @error('image_gen_base_url') is-invalid @enderror" value="{{ old('image_gen_base_url', $settings['image_gen_base_url']) }}">
                @error('image_gen_base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Model</label>
                <input type="text" name="image_gen_model" class="form-control @error('image_gen_model') is-invalid @enderror" value="{{ old('image_gen_model', $settings['image_gen_model']) }}">
                @error('image_gen_model')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Credits per image</label>
                <input type="number" min="1" name="image_gen_credits_per_image" class="form-control @error('image_gen_credits_per_image') is-invalid @enderror" value="{{ old('image_gen_credits_per_image', $settings['image_gen_credits_per_image']) }}">
                @error('image_gen_credits_per_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">API Key</label>
                <input type="password" name="image_gen_api_key" class="form-control" placeholder="{{ $settings['image_gen_api_key_set'] ? '••••••••  (đã cấu hình — để trống nếu không đổi)' : 'Chưa cấu hình' }}">
                <small class="text-muted">Để trống nếu không muốn thay đổi API key hiện tại.</small>
            </div>

            <button type="submit" class="btn btn-primary">Lưu cấu hình</button>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\Admin\ToolSettingsController;
```

And inside the existing `Route::middleware('admin')->group(function () { ... });` block, alongside `dashboard`/`videodub.*`:

```php
        Route::get('/tool-settings', [ToolSettingsController::class, 'index'])->name('tool-settings.index');
        Route::post('/tool-settings', [ToolSettingsController::class, 'update'])->name('tool-settings.update');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ToolSettingsControllerTest`
Expected: PASS (6 tests)

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/ToolSettingsController.php resources/views/admin/tool-settings routes/web.php tests/Feature/Admin/ToolSettingsControllerTest.php
git commit -m "Add Admin ToolSettingsController for image-gen configuration"
```

---

## What's Next

After Phase 3D, the remaining scope from the original design spec is the data-migration Artisan commands (`export:marketing-data` in the ESP32 source project, and a matching `import:marketing-data` here) — the last deferred piece, not yet scheduled as its own phase.
