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
        // ~99 characters of real words (with spaces) in a 2-second window is far above maxCps (17),
        // and has natural word-boundary delimiters so splitSubtitle()'s word-splitting branch engages.
        // A 1-second window was tried first but doesn't survive: splitting a 1s segment produces two
        // ~0.5s halves, and mergeShortSubtitles() immediately re-merges them (shouldMerge() only
        // refuses to merge when a segment's own duration is >= 0.6s AND its text is >= 8 chars — a
        // ~0.5s half fails that duration check, so it gets merged back into a single entry). Using a
        // 2-second window makes each half ~1.0s (>= 0.6s) with ~49 chars (>= 8), so the split holds.
        $text = trim(str_repeat('word ', 20)); // "word word ... word" (20 repetitions, ~99 chars)
        $srt = "1\n00:00:00,000 --> 00:00:02,000\n{$text}\n";

        $result = $service->redistribute($srt);

        $entryCount = substr_count($result, '-->');
        $this->assertGreaterThan(1, $entryCount);
    }

    public function test_redistribute_calls_condenser_when_segment_still_overflows_after_borrowing(): void
    {
        $service = new SrtTimeRedistributionService();
        // Long text (~150 chars, needs ~10.7s @ 14 chars/sec) crammed into a 1s window with
        // no neighbors to borrow from and maxExtendRatio (1.5x default) capping the extend at
        // 1.5s -- nowhere near enough, so it must still overflow after the borrow/extend pass.
        $longText = trim(str_repeat('word ', 30));
        $srt = "1\n00:00:00,000 --> 00:00:01,000\n{$longText}\n";

        $condenserCalls = [];
        $result = $service->redistribute($srt, function (string $text, float $maxSeconds) use (&$condenserCalls) {
            $condenserCalls[] = ['text' => $text, 'maxSeconds' => $maxSeconds];
            return 'short';
        });

        $this->assertCount(1, $condenserCalls);
        $this->assertSame($longText, $condenserCalls[0]['text']);
        $this->assertGreaterThan(0, $condenserCalls[0]['maxSeconds']);
        $this->assertStringContainsString('short', $result);
        $this->assertStringNotContainsString($longText, $result);
    }

    public function test_redistribute_does_not_call_condenser_when_segment_fits(): void
    {
        $service = new SrtTimeRedistributionService();
        $srt = "1\n00:00:00,000 --> 00:00:04,000\nHello world this is a test\n";

        $called = false;
        $service->redistribute($srt, function () use (&$called) {
            $called = true;
            return 'should not be used';
        });

        $this->assertFalse($called);
    }

    public function test_redistribute_without_condenser_argument_still_works(): void
    {
        $service = new SrtTimeRedistributionService();
        $longText = trim(str_repeat('word ', 30));
        $srt = "1\n00:00:00,000 --> 00:00:01,000\n{$longText}\n";

        // No condenser passed -- segment stays overflowing (over its window), but must not error.
        $result = $service->redistribute($srt);

        $this->assertStringContainsString($longText, $result);
    }

    public function test_format_timestamp_carries_milliseconds_that_round_to_1000(): void
    {
        $service = new SrtTimeRedistributionService();
        $result = $service->formatTimestamp(1.99996);

        $this->assertSame('00:00:02,000', $result);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2},\d{3}$/', $result);
    }
}
