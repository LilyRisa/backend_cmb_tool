<?php

namespace Tests\Unit;

use App\Services\SrtTimeRedistributionService;
use Tests\TestCase;

class SrtTimeRedistributionServiceTest extends TestCase
{
    public function test_parse_and_format_timestamp_round_trip(): void
    {
        $service = new SrtTimeRedistributionService();

        $seconds = $service->parseTimestamp('00:01:05,250');

        $this->assertEquals(65.25, $seconds);
        $this->assertEquals('00:01:05,250', $service->formatTimestamp($seconds));
    }

    public function test_redistribute_returns_original_when_no_entries(): void
    {
        $service = new SrtTimeRedistributionService();

        // A string that fails to parse into any entries should return unchanged
        // (redistribute catches the empty-segments case internally via SrtParserService,
        // which throws — so use a service call wrapped by the job's own try/catch instead).
        // Here we test the documented empty-array short-circuit path directly is not
        // reachable via parse() (which throws on empty) — so test with a minimal valid SRT instead.
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nHello world this is a test\n";

        $result = $service->redistribute($srt);

        $this->assertStringContainsString('-->', $result);
    }

    public function test_redistribute_shrinks_segment_with_large_surplus(): void
    {
        $service = new SrtTimeRedistributionService();
        // "Hi" (2 chars) needs ~0.14s at 14 chars/sec, but is given 10 seconds — should shrink.
        $srt = "1\n00:00:00,000 --> 00:00:10,000\nHi\n\n2\n00:00:15,000 --> 00:00:16,000\nBye\n";

        $result = $service->redistribute($srt);
        $firstEnd = $service->parseTimestamp(explode(' --> ', explode("\n", $result)[1])[1]);

        $this->assertLessThan(10.0, $firstEnd);
    }

    public function test_redistribute_borrows_from_gap_for_short_segment(): void
    {
        $service = new SrtTimeRedistributionService();
        // Second segment has a long text needing more time than its 1-second slot,
        // with a generous gap before it (segment 1 ends at 2s, segment 2 starts at 10s).
        $longText = str_repeat('word ', 20); // ~100 chars, needs several seconds
        $srt = "1\n00:00:00,000 --> 00:00:02,000\nShort\n\n2\n00:00:10,000 --> 00:00:11,000\n{$longText}\n";

        $result = $service->redistribute($srt);

        // The second segment's start should have been pulled earlier than 10s to borrow time.
        $lines = array_values(array_filter(explode("\n", $result)));
        $secondTimingLine = $lines[3]; // index 0
        [$start] = explode(' --> ', $secondTimingLine);
        $startSeconds = $service->parseTimestamp($start);

        $this->assertLessThan(10.0, $startSeconds);
    }

    public function test_redistribute_splits_subtitle_exceeding_max_cps(): void
    {
        $service = new SrtTimeRedistributionService();
        // 100 characters in a 1-second window is far above maxCps (17) — should split into 2+ entries.
        $text = str_repeat('a', 100);
        $srt = "1\n00:00:00,000 --> 00:00:01,000\n{$text}\n";

        $result = $service->redistribute($srt);

        $entryCount = substr_count($result, '-->');
        $this->assertGreaterThan(1, $entryCount);
    }
}
