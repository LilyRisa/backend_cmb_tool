<?php

namespace App\Jobs;

use App\Models\SrtGenerateJob;
use App\Services\CreditService;
use App\Services\GroqService;
use App\Services\SrtParserService;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSrtGenerate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    protected SrtGenerateJob $generateJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected ?string $language;

    public function __construct(
        SrtGenerateJob $generateJob,
        string $audioFilePath,
        string $audioFileName,
        ?string $language = null
    ) {
        $this->generateJob = $generateJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->language = $language;
    }

    public function handle(GroqService $groq): void
    {
        $job = $this->generateJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            $this->cleanup();
            return;
        }

        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $srtContent = $groq->transcribeRaw($this->audioFilePath, $this->audioFileName, $this->language);
        } catch (\Throwable $e) {
            Log::error('SrtGenerate STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update(['stage' => 'merging']);

        try {
            $srtParser = app(SrtParserService::class);
            $srtContent = $srtParser->sanitizeSrt($srtContent);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $parsed = $srtParser->parse($srtContent);
            $charactersUsed = $parsed['total_characters'];
            $creditsNeeded = CreditService::calculateSrtTranslateCredits($charactersUsed);

            if ($creditsNeeded > 0) {
                $deducted = $user->deductCredits($creditsNeeded, "SRT Generation ({$charactersUsed} chars)", 'srt_generate', $job->id);

                if (!$deducted) {
                    $job->update([
                        'srt_content' => $srtContent,
                        'characters_used' => $charactersUsed,
                        'status' => 'failed',
                        'stage' => 'done',
                        'error' => 'Không đủ credit. Cần ' . $creditsNeeded . ' credits.',
                    ]);
                    $this->cleanup();
                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::error('SrtGenerate credit deduction failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'done', 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_content' => $srtContent,
            'characters_used' => $charactersUsed ?? 0,
            'credits_deducted' => $creditsNeeded ?? 0,
            'status' => 'completed',
            'stage' => 'done',
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessSrtGenerate job failed permanently', ['job_id' => $this->generateJob->id, 'error' => $exception?->getMessage()]);

        $this->generateJob->update([
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
