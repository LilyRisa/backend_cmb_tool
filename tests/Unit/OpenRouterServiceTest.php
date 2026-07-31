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
