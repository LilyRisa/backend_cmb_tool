<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVideoDub;
use App\Models\VideoDubJob;
use App\Services\GenMaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoDubController extends Controller
{
    protected GenMaxService $genMax;

    public function __construct(GenMaxService $genMax)
    {
        $this->genMax = $genMax;
    }

    /**
     * POST /api/tool/video-dub
     *
     * Validate input, create job record, dispatch background worker.
     * Returns job_id immediately for client polling via status().
     */
    public function dub(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a',
            'target_language' => 'required|string|max:10',
            'voice_id' => 'required|string',
            'provider' => 'nullable|string|in:elevenlabs,minimax',
            'model_id' => 'nullable|string',
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

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        // Pre-check for zero credits before spending any provider budget on
        // STT/translation (Phase 3B's paywall-bypass fix pattern) — the exact
        // TTS cost isn't known until GenMaxService::textToSpeechSrt() runs, so
        // this only blocks users who have nothing to spend at all.
        if ($user->monthly_credits + $user->purchased_credits <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'Không đủ credit để thực hiện thao tác này.',
                'credits_available' => $user->monthly_credits + $user->purchased_credits,
            ], 402);
        }

        $file = $request->file('file');
        $tempPath = $file->store('video-dub-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        try {
            $job = VideoDubJob::create([
                'user_id' => $user->id,
                'target_language' => $request->input('target_language'),
                'voice_id' => $request->input('voice_id'),
                'provider' => $request->input('provider', 'elevenlabs'),
                'model_id' => $request->input('model_id'),
                'voice_settings' => $request->input('voice_settings'),
                'status' => 'queued',
                'stage' => 'queued',
            ]);

            $params = [
                'target_language' => $request->input('target_language'),
                'voice_id' => $request->input('voice_id'),
                'provider' => $request->input('provider', 'elevenlabs'),
                'model_id' => $request->input('model_id'),
                'voice_settings' => $request->input('voice_settings'),
            ];

            ProcessVideoDub::dispatch($job, $fullTempPath, $file->getClientOriginalName(), $params);
        } catch (\Throwable $e) {
            if (file_exists($fullTempPath)) {
                @unlink($fullTempPath);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/video-dub/status/' . $job->id . ' for progress.',
        ], 202);
    }

    /**
     * GET /api/tool/video-dub/status/{id}
     *
     * Poll the status of a video dubbing job. When the linked TTS task
     * completes, finalizes the job with the audio URL.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = VideoDubJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        if (in_array($job->status, ['queued', 'processing', 'completed', 'failed'])) {
            return response()->json($this->formatJobResponse($job));
        }

        // Status is 'tts_pending' — poll the single linked TTS task.
        $ttsTaskIds = $job->tts_task_ids ?? [];

        if (empty($ttsTaskIds)) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'TTS produced no task — submission may have failed',
            ]);
            return response()->json($this->formatJobResponse($job));
        }

        $historyId = $ttsTaskIds[0];
        $taskResult = $this->genMax->getTaskStatus($user, $historyId);

        $taskStatus = 'pending';
        $taskData = [];

        if ($taskResult['success'] ?? false) {
            $taskData = $taskResult['data'];
            $taskStatus = $taskData['status'] ?? 'pending';
        }

        // Shared with dub:cleanup-stale's finalizer — see VideoDubJob::applyTtsResult().
        if (in_array($taskStatus, ['completed', 'failed'])) {
            $job->applyTtsResult($taskData);
        }
        // else: still pending — return current state.

        $response = $this->formatJobResponse($job->fresh());

        $response['tts_progress'] = [
            'completed' => in_array($taskStatus, ['completed', 'failed']) ? 1 : 0,
            'total' => 1,
        ];

        return response()->json($response);
    }

    protected function formatJobResponse(VideoDubJob $job): array
    {
        $isCompleted = $job->status === 'completed';

        return [
            'success' => $job->status !== 'failed',
            'job_id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'is_final' => in_array($job->status, ['completed', 'failed']),
            'target_language' => $job->target_language,
            'characters_used' => $job->characters_used,
            'credits_deducted' => $job->credits_deducted,
            'audio_url' => $isCompleted ? $job->audio_url : null,
            'audio_urls' => $isCompleted ? $job->audio_urls : null,
            'srt_original' => $isCompleted ? $job->srt_original : null,
            'srt_translated' => $isCompleted ? $job->srt_translated : null,
            'duration_seconds' => $job->duration_seconds,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
