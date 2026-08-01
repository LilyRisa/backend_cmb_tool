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
        // Give the faked public disk its own `url` (as config/filesystems.php does via
        // APP_URL) so the assertions below can actually distinguish a URL minted from
        // the public disk from one minted off the DEFAULT disk — without it both fall
        // back to the identical host-relative "/storage/..." form and the regression
        // this file guards against would be invisible.
        Storage::fake('public', ['url' => 'https://public-disk.test/storage']);
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
        // The URL must be minted from the same disk the file was written to —
        // Storage::url() would resolve against the DEFAULT disk instead.
        $this->assertStringStartsWith(Storage::disk('public')->url('generated-images/'), $urls[0]);

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
        $this->assertStringStartsWith(Storage::disk('public')->url('generated-images/'), $urls[0]);
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
