<?php

namespace Tests\Unit;

use App\Services\SrtParserService;
use Tests\TestCase;

class SrtParserServiceTest extends TestCase
{
    private SrtParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SrtParserService();
    }

    public function test_parse_extracts_entries_with_index_timing_and_text(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nHello world.\n\n2\n00:00:05,000 --> 00:00:07,500\nSecond line.\n";

        $result = $this->parser->parse($srt);

        $this->assertCount(2, $result['entries']);
        $this->assertEquals(1, $result['entries'][0]['index']);
        $this->assertEquals('00:00:01,000', $result['entries'][0]['start']);
        $this->assertEquals('00:00:04,000', $result['entries'][0]['end']);
        $this->assertEquals('Hello world.', $result['entries'][0]['text']);
        $this->assertEquals('Second line.', $result['entries'][1]['text']);
        $this->assertEquals(mb_strlen('Hello world.') + mb_strlen('Second line.'), $result['total_characters']);
    }

    public function test_parse_strips_html_tags_and_multiline_text(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\n<i>Line one</i>\nLine two\n";

        $result = $this->parser->parse($srt);

        $this->assertEquals('Line one Line two', $result['entries'][0]['text']);
    }

    public function test_parse_throws_on_content_with_no_valid_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->parser->parse("this is not an srt file at all");
    }

    public function test_parse_handles_bom_and_crlf_line_endings(): void
    {
        $srt = "\xEF\xBB\xBF1\r\n00:00:01,000 --> 00:00:02,000\r\nHi.\r\n";

        $result = $this->parser->parse($srt);

        $this->assertCount(1, $result['entries']);
        $this->assertEquals('Hi.', $result['entries'][0]['text']);
    }

    public function test_sanitize_srt_removes_punctuation_only_entries_and_renumbers(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nReal text.\n\n2\n00:00:03,000 --> 00:00:04,000\n...\n\n3\n00:00:05,000 --> 00:00:06,000\nMore text.\n";

        $sanitized = $this->parser->sanitizeSrt($srt);
        $reparsed = $this->parser->parse($sanitized);

        $this->assertCount(2, $reparsed['entries']);
        $this->assertEquals(1, $reparsed['entries'][0]['index']);
        $this->assertEquals(2, $reparsed['entries'][1]['index']);
        $this->assertEquals('Real text.', $reparsed['entries'][0]['text']);
        $this->assertEquals('More text.', $reparsed['entries'][1]['text']);
    }
}
