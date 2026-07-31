<?php

namespace App\Jobs;

use App\Models\SrtTranslateJob;
use App\Services\CreditService;
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

class ProcessSrtTranslate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    protected SrtTranslateJob $translateJob;
    protected string $audioFilePath;
    protected string $audioFileName;
    protected array $params;

    public function __construct(
        SrtTranslateJob $translateJob,
        string $audioFilePath,
        string $audioFileName,
        array $params
    ) {
        $this->translateJob = $translateJob;
        $this->audioFilePath = $audioFilePath;
        $this->audioFileName = $audioFileName;
        $this->params = $params;
    }

    public function handle(GroqService $groq, OpenRouterService $openRouter): void
    {
        $job = $this->translateJob;
        $user = $job->user;

        if (!$user) {
            $job->update(['status' => 'failed', 'error' => 'User not found']);
            $this->cleanup();
            return;
        }

        $job->update(['status' => 'processing', 'stage' => 'transcribing']);

        try {
            $fakeFile = new \Illuminate\Http\UploadedFile($this->audioFilePath, $this->audioFileName, null, null, true);
            $srtOriginal = $groq->transcribe($fakeFile);
        } catch (\Throwable $e) {
            Log::error('SrtTranslate STT failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'error' => 'Transcription failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update(['srt_original' => $srtOriginal, 'stage' => 'translating']);

        try {
            $chunkTranslator = app(SrtChunkTranslationService::class);
            $srtTranslated = $chunkTranslator->translate(
                $srtOriginal,
                $this->params['target_language'],
                fn(string $chunk, string $lang, string $context = '') => $openRouter->translate($chunk, $lang, 'srt', $context)
            );
        } catch (\Throwable $e) {
            Log::error('SrtTranslate translate failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'translating', 'error' => 'Translation failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        try {
            $redistributor = app(SrtTimeRedistributionService::class);
            $srtTranslated = $redistributor->redistribute($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT redistribution skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $srtTranslated = $srtParser->sanitizeSrt($srtTranslated);
        } catch (\Throwable $e) {
            Log::warning('SRT sanitization skipped', ['job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        try {
            $srtParser = app(SrtParserService::class);
            $parsed = $srtParser->parse($srtTranslated);
            $charactersUsed = $parsed['total_characters'];
            $creditsNeeded = CreditService::calculateSrtTranslateCredits($charactersUsed);

            if ($creditsNeeded > 0) {
                $deducted = $user->deductCredits(
                    $creditsNeeded,
                    "SRT Translation ({$charactersUsed} chars → {$this->params['target_language']})",
                    'srt_translate',
                    $job->id
                );

                if (!$deducted) {
                    $job->update([
                        'srt_translated' => $srtTranslated,
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
            Log::error('SrtTranslate credit deduction failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => 'failed', 'stage' => 'done', 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
            $this->cleanup();
            return;
        }

        $job->update([
            'srt_translated' => $srtTranslated,
            'characters_used' => $charactersUsed ?? 0,
            'credits_deducted' => $creditsNeeded ?? 0,
            'status' => 'completed',
            'stage' => 'done',
        ]);

        $this->cleanup();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessSrtTranslate job failed permanently', ['job_id' => $this->translateJob->id, 'error' => $exception?->getMessage()]);

        $this->translateJob->update([
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
