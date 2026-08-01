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
            // ConnectionException (cURL timeout / DNS / connection reset) is NOT a
            // RuntimeException subclass — without it here, a timed-out call escapes
            // the retry loop on the first attempt and surfaces raw "cURL error 28"
            // text to the user instead of retrying. Matches OpenRouterService.
            } catch (\RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
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
