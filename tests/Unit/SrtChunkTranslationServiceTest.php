<?php

namespace Tests\Unit;

use App\Services\SrtChunkTranslationService;
use Tests\TestCase;

class SrtChunkTranslationServiceTest extends TestCase
{
    private function buildSrt(int $count): string
    {
        $blocks = [];
        for ($i = 1; $i <= $count; $i++) {
            $start = sprintf('00:00:%02d,000', $i);
            $end = sprintf('00:00:%02d,000', $i + 1);
            $blocks[] = "{$i}\n{$start} --> {$end}\nLine {$i}";
        }
        return implode("\n\n", $blocks) . "\n";
    }

    public function test_translate_single_chunk_preserves_timestamps_and_translates_text(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70);
        $srt = $this->buildSrt(2);

        $translator = fn(string $chunk, string $lang, string $context = '') =>
            "1\n00:00:01,000 --> 00:00:02,000\nDòng 1\n\n2\n00:00:02,000 --> 00:00:03,000\nDòng 2\n";

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertStringContainsString('00:00:01,000 --> 00:00:02,000', $result);
        $this->assertStringContainsString('Dòng 1', $result);
        $this->assertStringContainsString('Dòng 2', $result);
    }

    public function test_translate_splits_into_multiple_chunks_when_exceeding_chunk_size(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 2);
        $srt = $this->buildSrt(5);
        $callCount = 0;

        $translator = function (string $chunk, string $lang, string $context = '') use (&$callCount) {
            $callCount++;
            // Echo back the chunk renumbered 1..N with translated marker, same count.
            $lines = substr_count(trim($chunk), "\n\n") + 1;
            $blocks = [];
            for ($i = 1; $i <= $lines; $i++) {
                $blocks[] = "{$i}\n00:00:0{$i},000 --> 00:00:0{$i},500\nTranslated {$i}";
            }
            return implode("\n\n", $blocks);
        };

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertEquals(3, $callCount); // ceil(5/2) = 3 chunks
        $this->assertEquals(5, substr_count($result, '-->'));
    }

    public function test_translate_retries_on_count_mismatch_then_succeeds(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 2);
        $srt = $this->buildSrt(2);
        $attempt = 0;

        $translator = function (string $chunk, string $lang, string $context = '') use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                // Wrong count on first attempt
                return "1\n00:00:01,000 --> 00:00:02,000\nOnly one";
            }
            return "1\n00:00:01,000 --> 00:00:02,000\nDòng 1\n\n2\n00:00:02,000 --> 00:00:03,000\nDòng 2\n";
        };

        $result = $service->translate($srt, 'vi', $translator);

        $this->assertEquals(2, $attempt);
        $this->assertStringContainsString('Dòng 1', $result);
    }

    public function test_translate_throws_after_exhausting_retries(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 2);
        $srt = $this->buildSrt(2);

        $translator = fn(string $chunk, string $lang, string $context = '') => "1\n00:00:01,000 --> 00:00:02,000\nOnly one";

        $this->expectException(\RuntimeException::class);

        $service->translate($srt, 'vi', $translator);
    }

    public function test_translate_throws_if_timestamps_are_modified(): void
    {
        $service = new SrtChunkTranslationService(chunkSize: 70, maxRetries: 1);
        $srt = $this->buildSrt(1);

        $translator = fn(string $chunk, string $lang, string $context = '') => "1\n00:00:05,000 --> 00:00:06,000\nWrong timing";

        $this->expectException(\RuntimeException::class);

        $service->translate($srt, 'vi', $translator);
    }
}
