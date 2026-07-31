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
