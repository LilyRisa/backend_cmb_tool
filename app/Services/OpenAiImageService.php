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

        return Storage::disk('public')->url($filename);
    }
}
