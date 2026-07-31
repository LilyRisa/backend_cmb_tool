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
