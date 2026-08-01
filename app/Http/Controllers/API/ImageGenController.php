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
