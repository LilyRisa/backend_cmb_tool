<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSrtGenerate;
use App\Models\SrtGenerateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SrtGenerateController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:61440|mimes:mp3,wav,m4a',
            'language' => 'nullable|string|max:10',
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
        $tempPath = $file->store('srt-generate-temp', 'local');
        $fullTempPath = storage_path('app/' . $tempPath);

        try {
            $job = SrtGenerateJob::create([
                'user_id' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'language' => $request->input('language'),
                'status' => 'queued',
                'stage' => 'queued',
            ]);

            ProcessSrtGenerate::dispatch($job, $fullTempPath, $file->getClientOriginalName(), $request->input('language'));
        } catch (\Throwable $e) {
            // Nothing owns the temp file until the job is queued: if create() or
            // dispatch() throws, no job will ever run to call its own cleanup().
            if (file_exists($fullTempPath)) {
                @unlink($fullTempPath);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => 'queued',
            'message' => 'Pipeline started. Poll GET /api/tool/generate-srt/status/' . $job->id . ' for progress.',
        ], 202);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = SrtGenerateJob::where('id', $id)->where('user_id', $user->id)->first();

        if (!$job) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy job'], 404);
        }

        return response()->json($this->formatJobResponse($job));
    }

    protected function formatJobResponse(SrtGenerateJob $job): array
    {
        $isCompleted = $job->status === 'completed';

        return [
            'success' => $job->status !== 'failed',
            'job_id' => $job->id,
            'status' => $job->status,
            'stage' => $job->stage,
            'is_final' => in_array($job->status, ['completed', 'failed']),
            'original_filename' => $job->original_filename,
            'language' => $job->language,
            'srt_content' => $isCompleted ? $job->srt_content : null,
            'characters_used' => $job->characters_used,
            'credits_deducted' => $job->credits_deducted,
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
