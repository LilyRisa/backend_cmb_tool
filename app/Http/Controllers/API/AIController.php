<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AiTextService;
use App\Services\GroqService;
use App\Services\SrtChunkTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    protected GroqService $groq;
    protected AiTextService $aiText;

    public function __construct(GroqService $groq, AiTextService $aiText)
    {
        $this->groq = $groq;
        $this->aiText = $aiText;
    }

    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a,mp4',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            $srt = $this->groq->transcribe($request->file('file'));

            return response()->json(['success' => true, 'srt' => $srt]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function translate(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:50000',
            'target_language' => 'required|string|max:10',
            'format' => 'required|string|in:text,srt',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        try {
            if ($request->input('format') === 'srt') {
                $chunkTranslator = app(SrtChunkTranslationService::class);
                $translated = $chunkTranslator->translate(
                    $request->input('text'),
                    $request->input('target_language'),
                    fn(string $chunk, string $lang, string $context = '') => $this->aiText->translate($chunk, $lang, 'srt', $context)
                );
            } else {
                $translated = $this->aiText->translate(
                    $request->input('text'),
                    $request->input('target_language'),
                    $request->input('format')
                );
            }

            return response()->json(['success' => true, 'translated' => $translated]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
