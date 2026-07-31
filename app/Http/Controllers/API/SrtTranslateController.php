<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSrtTranslate;
use App\Models\SrtTranslateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SrtTranslateController extends Controller
{
    public function translate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:mp3,wav,m4a',
            'target_language' => 'required|string|max:10',
        ]);

        $user = $request->user();

        if (!$user->isPremium()) {
            return response()->json([
                'success' => false,
                'error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.',
            ], 403);
        }

        if ($user->monthly_credits + $user->purchased_credits <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'Không đủ credit để thực hiện thao tác này.',
                'credits_available' => $user->monthly_credits + $user->purchased_credits,
            ], 402);
        }

        $file = $request->file('file');
        $tempPath = $file->store('srt-translate-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        $job = SrtTranslateJob::create([
            'user_id' => $user->id,
            'target_language' => $request->input('target_language'),
            'status' => 'queued',
            'stage' => 'queued',
        ]);

        ProcessSrtTranslate::dispatch(
            $job,
            $fullTempPath,
            $file->getClientOriginalName(),
            ['target_language' => $request->input('target_language')]
        );

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/translate-srt/status/' . $job->id . ' for progress.',
        ], 202);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = SrtTranslateJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        return response()->json($this->formatJobResponse($job));
    }

    protected function formatJobResponse(SrtTranslateJob $job): array
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
            'srt_original' => $isCompleted ? $job->srt_original : null,
            'srt_translated' => $isCompleted ? $job->srt_translated : null,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
