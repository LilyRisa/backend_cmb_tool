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
