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
