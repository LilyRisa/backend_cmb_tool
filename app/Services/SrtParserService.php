<?php

namespace App\Services;

class SrtParserService
{
    public function parse(string $content): array
    {
        $content = $this->normalizeLineEndings($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $blocks = preg_split('/\n\s*\n/', trim($content));
        $entries = [];
        $totalCharacters = 0;

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }

            $entry = $this->parseBlock($block);
            if ($entry !== null) {
                $entries[] = $entry;
                $totalCharacters += mb_strlen($entry['text']);
            }
        }

        if (empty($entries)) {
            throw new \InvalidArgumentException('File SRT không hợp lệ hoặc không chứa subtitle nào.');
        }

        return [
            'entries' => $entries,
            'total_characters' => $totalCharacters,
        ];
    }

    protected function parseBlock(string $block): ?array
    {
        $lines = explode("\n", $block);

        if (count($lines) < 3) {
            return null;
        }

        $index = (int) trim($lines[0]);
        if ($index <= 0) {
            return null;
        }

        $timecode = trim($lines[1]);
        if (!preg_match('/^\d{2}:\d{2}:\d{2}[,.]\d{3}\s*-->\s*\d{2}:\d{2}:\d{2}[,.]\d{3}/', $timecode)) {
            return null;
        }

        [$start, $end] = array_map('trim', explode('-->', $timecode));

        $textLines = array_slice($lines, 2);
        $text = implode(' ', array_map('trim', $textLines));
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (empty($text)) {
            return null;
        }

        return [
            'index' => $index,
            'start' => trim($start),
            'end' => trim($end),
            'text' => $text,
        ];
    }

    public function sanitizeSrt(string $srtContent): string
    {
        $parsed = $this->parse($srtContent);
        $cleaned = [];
        $removed = [];

        foreach ($parsed['entries'] as $entry) {
            $stripped = preg_replace('/[\p{P}\p{S}\s]+/u', '', $entry['text']);

            if (mb_strlen($stripped) === 0) {
                $removed[] = "#{$entry['index']}: \"{$entry['text']}\"";
                continue;
            }

            $cleaned[] = $entry;
        }

        if (!empty($removed)) {
            \Illuminate\Support\Facades\Log::info('[SrtParser] Sanitized junk subtitles', [
                'removed_count' => count($removed),
                'removed' => $removed,
                'remaining' => count($cleaned),
            ]);
        }

        $blocks = [];
        foreach ($cleaned as $i => $entry) {
            $index = $i + 1;
            $blocks[] = "{$index}\n{$entry['start']} --> {$entry['end']}\n{$entry['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }

    protected function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }
}
