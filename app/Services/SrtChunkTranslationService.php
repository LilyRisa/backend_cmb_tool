<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SrtChunkTranslationService
{
    protected SrtParserService $parser;
    protected int $chunkSize;
    protected int $maxRetries;
    protected int $overlapSize;

    public function __construct(
        ?SrtParserService $parser = null,
        int $chunkSize = 70,
        int $maxRetries = 3,
        int $overlapSize = 5
    ) {
        $this->parser = $parser ?? app(SrtParserService::class);
        $this->chunkSize = $chunkSize;
        $this->maxRetries = $maxRetries;
        $this->overlapSize = $overlapSize;
    }

    public function translate(string $srtContent, string $targetLanguage, callable $translator): string
    {
        $parsed = $this->parser->parse($srtContent);
        $entries = $parsed['entries'];
        $totalCount = count($entries);

        Log::info('[SrtChunkTranslation] Starting', [
            'total_subtitles' => $totalCount,
            'chunk_size' => $this->chunkSize,
            'chunks_needed' => (int) ceil($totalCount / $this->chunkSize),
        ]);

        $chunks = array_chunk($entries, $this->chunkSize);

        $translatedEntries = [];
        $previousContext = '';

        foreach ($chunks as $chunkIndex => $chunkEntries) {
            $chunkNumber = $chunkIndex + 1;
            $chunkTotal = count($chunks);
            $expectedCount = count($chunkEntries);

            $chunkSrt = $this->entriesToSrt($chunkEntries, renumber: true);

            $translatedChunkEntries = $this->translateChunkWithRetry(
                $chunkSrt,
                $targetLanguage,
                $translator,
                $expectedCount,
                $chunkNumber,
                $chunkTotal,
                $previousContext
            );

            foreach ($chunkEntries as $i => $originalEntry) {
                $translatedEntries[] = [
                    'index' => $originalEntry['index'],
                    'start' => $originalEntry['start'],
                    'end' => $originalEntry['end'],
                    'text' => $translatedChunkEntries[$i]['text'] ?? $originalEntry['text'],
                ];
            }

            if ($chunkIndex < count($chunks) - 1) {
                $overlapEntries = array_slice($translatedChunkEntries, -$this->overlapSize);
                $overlapOriginal = array_slice($chunkEntries, -$this->overlapSize);
                $contextEntries = [];
                foreach ($overlapEntries as $j => $tEntry) {
                    $contextEntries[] = [
                        'index' => $overlapOriginal[$j]['index'] ?? ($j + 1),
                        'start' => $overlapOriginal[$j]['start'],
                        'end' => $overlapOriginal[$j]['end'],
                        'text' => $tEntry['text'],
                    ];
                }
                $previousContext = $this->entriesToSrt($contextEntries, renumber: false);
            }
        }

        $this->validateOutput($entries, $translatedEntries);

        $result = $this->entriesToSrt($translatedEntries, renumber: false);

        Log::info('[SrtChunkTranslation] Completed', [
            'input_count' => $totalCount,
            'output_count' => count($translatedEntries),
        ]);

        return $result;
    }

    protected function translateChunkWithRetry(
        string $chunkSrt,
        string $targetLanguage,
        callable $translator,
        int $expectedCount,
        int $chunkNumber,
        int $chunkTotal,
        string $contextSrt = ''
    ): array {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $translatedSrt = $translator($chunkSrt, $targetLanguage, $contextSrt);
                $translatedSrt = $this->stripMarkdownWrapper($translatedSrt);

                $parsed = $this->parser->parse($translatedSrt);
                $translatedEntries = $parsed['entries'];

                if (count($translatedEntries) === $expectedCount) {
                    if ($attempt > 1) {
                        Log::info("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} succeeded on attempt {$attempt}");
                    }
                    return $translatedEntries;
                }

                $actualCount = count($translatedEntries);
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} count mismatch", [
                    'attempt' => $attempt,
                    'expected' => $expectedCount,
                    'actual' => $actualCount,
                ]);

                $lastException = new \RuntimeException(
                    "Chunk {$chunkNumber}/{$chunkTotal}: expected {$expectedCount} subtitles, got {$actualCount}"
                );
            } catch (\InvalidArgumentException $e) {
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} parse failed", ['attempt' => $attempt, 'error' => $e->getMessage()]);
                $lastException = $e;
            } catch (\RuntimeException $e) {
                Log::warning("[SrtChunkTranslation] Chunk {$chunkNumber}/{$chunkTotal} API error", ['attempt' => $attempt, 'error' => $e->getMessage()]);
                $lastException = $e;
            }

            if ($attempt < $this->maxRetries) {
                usleep($attempt * 500_000);
            }
        }

        throw new \RuntimeException(
            "Chunk {$chunkNumber}/{$chunkTotal} failed after {$this->maxRetries} attempts: "
                . ($lastException?->getMessage() ?? 'Unknown error')
        );
    }

    protected function validateOutput(array $inputEntries, array $outputEntries): void
    {
        $inputCount = count($inputEntries);
        $outputCount = count($outputEntries);

        if ($outputCount !== $inputCount) {
            throw new \RuntimeException(
                "SRT translation validation failed: input has {$inputCount} subtitles, output has {$outputCount}"
            );
        }

        foreach ($outputEntries as $i => $entry) {
            $expectedIndex = $inputEntries[$i]['index'];
            if ($entry['index'] !== $expectedIndex) {
                throw new \RuntimeException(
                    "SRT translation validation failed: subtitle #{$entry['index']} at position " . ($i + 1)
                        . " should be #{$expectedIndex}"
                );
            }
        }

        foreach ($outputEntries as $i => $entry) {
            if ($entry['start'] !== $inputEntries[$i]['start'] || $entry['end'] !== $inputEntries[$i]['end']) {
                $idx = $entry['index'];
                throw new \RuntimeException("SRT translation validation failed: timestamps modified for subtitle #{$idx}");
            }
        }

        Log::info('[SrtChunkTranslation] Validation passed', [
            'subtitle_count' => $outputCount,
            'first_index' => $outputEntries[0]['index'] ?? '?',
            'last_index' => end($outputEntries)['index'] ?? '?',
        ]);
    }

    protected function entriesToSrt(array $entries, bool $renumber = false): string
    {
        $blocks = [];

        foreach ($entries as $i => $entry) {
            $index = $renumber ? ($i + 1) : $entry['index'];
            $blocks[] = "{$index}\n{$entry['start']} --> {$entry['end']}\n{$entry['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }

    protected function stripMarkdownWrapper(string $text): string
    {
        $text = trim($text);
        return preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $text);
    }
}
