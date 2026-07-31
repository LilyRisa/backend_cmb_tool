<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\GenMaxService;
use App\Services\SrtParserService;
use Illuminate\Http\Request;

class ToolTtsController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    public function generate(Request $request, string $voiceId)
    {
        $request->validate([
            'text' => 'required|string|max:10000',
            'model_id' => 'nullable|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'language_code' => 'nullable|string',
            'voice_settings' => 'nullable|array',
            'voice_settings.stability' => 'nullable|numeric|between:0,1',
            'voice_settings.similarity_boost' => 'nullable|numeric|between:0,1',
            'voice_settings.style' => 'nullable|numeric|between:0,1',
            'voice_settings.use_speaker_boost' => 'nullable|boolean',
            'voice_settings.speed' => 'nullable|numeric|between:0.5,2.0',
            'voice_settings.pitch' => 'nullable|integer|between:-12,12',
            'voice_settings.vol' => 'nullable|numeric|between:0.01,10',
        ]);

        $user = $request->user();

        $result = $this->genMax->textToSpeech($user, $voiceId, $request->only([
            'text', 'model_id', 'provider', 'language_code', 'voice_settings',
        ]));

        return response()->json($result['data'], $result['status']);
    }

    public function generateFromSrt(Request $request, string $voiceId)
    {
        $request->validate([
            'file' => 'required|file|max:512|mimes:srt,txt',
            'model_id' => 'nullable|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'language_code' => 'nullable|string',
            'voice_settings' => 'nullable|array',
            'voice_settings.stability' => 'nullable|numeric|between:0,1',
            'voice_settings.similarity_boost' => 'nullable|numeric|between:0,1',
            'voice_settings.style' => 'nullable|numeric|between:0,1',
            'voice_settings.use_speaker_boost' => 'nullable|boolean',
            'voice_settings.speed' => 'nullable|numeric|between:0.5,2.0',
            'voice_settings.pitch' => 'nullable|integer|between:-12,12',
            'voice_settings.vol' => 'nullable|numeric|between:0.01,10',
        ]);

        $user = $request->user();
        $file = $request->file('file');

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['srt', 'txt'])) {
            return response()->json(['error' => 'File phải có định dạng .srt hoặc .txt'], 422);
        }

        $parser = new SrtParserService();
        try {
            $parsed = $parser->parse($file->get());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $result = $this->genMax->textToSpeechBatch(
            $user,
            $voiceId,
            $parsed['entries'],
            $request->only(['model_id', 'provider', 'language_code', 'voice_settings'])
        );

        return response()->json($result['data'], $result['status']);
    }

    public function status(Request $request, int $id)
    {
        $result = $this->genMax->getTaskStatus($request->user(), $id);

        return response()->json($result['data'], $result['status']);
    }

    public function history(Request $request)
    {
        $pageSize = min((int) $request->get('page_size', 30), 100);
        $page = max((int) $request->get('page', 1), 1);

        $result = $this->genMax->getUserHistory($request->user(), $pageSize, $page);

        return response()->json($result['data'], $result['status']);
    }

    public function deleteHistory(Request $request, int $id)
    {
        $result = $this->genMax->deleteHistory($request->user(), $id);

        return response()->json($result['data'], $result['status']);
    }
}
