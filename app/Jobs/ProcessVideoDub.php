<?php

namespace App\Jobs;

use App\Models\VideoDubJob;

use App\Services\GenMaxService;
use App\Services\GroqService;
use App\Services\OpenRouterService;
use App\Services\SrtChunkTranslationService;
use App\Services\SrtParserService;
use App\Services\SrtTimeRedistributionService;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoDub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // No auto-retry — a retry after TTS submission would double-charge, since
    // GenMaxService::textToSpeechSrt() has already deducted credits by then.
    public int $tries = 1;

    // Allow up to 10 minutes for the full STT + chunked-translate + TTS-submit pipeline.
    public int $timeout = 600;

    protected VideoDubJob $dubJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected array $params;

    public function __construct(
        VideoDubJob $dubJob,
        string $audioFilePath,
        string $audioFileName,
        array $params
    ) {
        $this->dubJob = $dubJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->params = $params;
    }

    public function handle(
        GroqService $groq,
        OpenRouterService $openRouter,
        GenMaxService $genMax,
    ): void {
        $job = $this->dubJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            $this->cleanup();
            return;
        }

        // ── Step 1: Whisper STT ──────────────────────────────────────────
        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $fakeFile = new \Illuminate\Http\UploadedFile(
                $this->audioFilePath,
                $this->audioFileName,
                null,
                null,
                true // test mode — skip is_uploaded_file check
            );
            $srtOriginal = $groq->transcribe($fakeFile);
        } catch (\Throwable $e) {
            Log::error('VideoDub STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_original' => $srtOriginal,
            'stage' => 'translating',
        ]);

        // ── Step 2: Translate SRT (chunked to avoid LLM truncation) ─────
        try {
            $chunkTranslator = app(SrtChunkTranslationService::class);
            $srtTranslated = $chunkTranslator->translate(
                $srtOriginal,
                $this->params['target_language'],
                fn(string $chunk, string $lang, string $context = '') => $openRouter->translate($chunk, $lang, 'srt', $context)
            );
        } catch (\Throwable $e) {
            Log::error('VideoDub translate failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'translating', 'error' => 'Translation failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        // ── Step 2.5: Redistribute SRT timing ─────────────────────────
        // Borrow time from neighbouring gaps so longer translated segments
        // get the extra seconds they need for natural TTS. Non-fatal.
        try {
            $redistributor = app(SrtTimeRedistributionService::class);
            $srtTranslated = $redistributor->redistribute($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT redistribution skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        // ── Step 2.6: Sanitize junk subtitles ─────────────────────────
        // Remove entries with no real spoken content (e.g. ".", "...", pure
        // punctuation) that would cause TTS to fail. Non-fatal.
        try {
            $srtParser = app(SrtParserService::class);
            $srtTranslated = $srtParser->sanitizeSrt($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        $job->update([
            'srt_translated' => $srtTranslated,
            'stage' => 'tts',
        ]);

        // ── Step 3: Submit TTS with full SRT (single request) ────────────
        $ttsParams = array_filter([
            'model_id' => $this->params['model_id'] ?? null,
            'provider' => $this->params['provider'] ?? 'elevenlabs',
            'language_code' => $this->params['target_language'],
            'voice_settings' => $this->params['voice_settings'] ?? null,
        ]);

        $ttsResult = $genMax->textToSpeechSrt(
            $user,
            $this->params['voice_id'],
            $srtTranslated,
            $ttsParams
        );

        if (!($ttsResult['success'] ?? false)) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => $ttsResult['data']['error'] ?? 'TTS submission failed',
            ]);
            $this->cleanup();
            return;
        }

        $ttsTaskId = $ttsResult['data']['id'] ?? null;

        if (!$ttsTaskId) {
            $job->update([
                'status' => 'failed',
                'stage' => 'done',
                'error' => 'TTS returned no task ID',
            ]);
            $this->cleanup();
            return;
        }

        $job->update([
            'status' => 'tts_pending',
            'stage' => 'tts',
            'tts_task_ids' => [$ttsTaskId],
            'characters_used' => $ttsResult['data']['total_characters'] ?? 0,
            'credits_deducted' => $ttsResult['data']['credits_deducted'] ?? 0,
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessVideoDub job failed permanently', [
            'job_id' => $this->dubJob->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->dubJob->update([
            'status' => 'failed',
            'error' => 'Pipeline crashed: ' . ($exception?->getMessage() ?? 'Unknown error'),
        ]);

        $this->cleanup();
    }

    protected function cleanup(): void
    {
        if (file_exists($this->audioFilePath)) {
            @unlink($this->audioFilePath);
        }
    }
}
