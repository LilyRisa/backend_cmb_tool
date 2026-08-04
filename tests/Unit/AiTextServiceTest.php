<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Services\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setAiTextApiKey('test-ai-text-key');
    }

    public function test_complete_returns_trimmed_text_response(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "  Xin chào  "]]],
            ], 200),
        ]);

        $result = (new AiTextService())->complete('Hello');

        $this->assertEquals('Xin chào', $result);
    }

    public function test_complete_strips_markdown_code_fence(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "```srt\nsome srt\n```"]]],
            ], 200),
        ]);

        $result = (new AiTextService())->complete('some srt');

        $this->assertEquals('some srt', $result);
    }

    public function test_complete_throws_on_provider_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limited');

        (new AiTextService())->complete('Hello');
    }

    public function test_complete_throws_on_empty_response(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => '']]]], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        (new AiTextService())->complete('Hello');
    }

    public function test_complete_sends_bearer_auth_header_and_configured_model(): void
    {
        SystemSetting::setAiTextModel('some/custom-model');
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        (new AiTextService())->complete('Hello');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-ai-text-key')
            && $request->data()['model'] === 'some/custom-model');
    }

    public function test_complete_uses_admin_configured_base_url(): void
    {
        SystemSetting::setAiTextBaseUrl('https://my-provider.test/v1');
        Http::fake(['my-provider.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        (new AiTextService())->complete('Hello');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://my-provider.test/v1'));
    }

    public function test_complete_throws_when_api_key_not_configured(): void
    {
        // Clear both the DB value and the env fallback the constructor reads.
        SystemSetting::setAiTextApiKey('');
        config(['services.openrouter.api_key' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI text API key not configured');

        (new AiTextService())->complete('Hello');
    }

    public function test_translate_returns_trimmed_text_response(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "  Xin chào  "]]],
            ], 200),
        ]);

        $result = (new AiTextService())->translate('Hello', 'vi', 'text');

        $this->assertEquals('Xin chào', $result);
    }

    public function test_translate_srt_format_includes_context_when_provided(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'translated']]],
            ], 200),
        ]);

        (new AiTextService())->translate('some srt', 'vi', 'srt', 'previous context here');

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][0]['content'];
            return str_contains($prompt, 'CONTINUITY CONTEXT') && str_contains($prompt, 'previous context here');
        });
    }
}
