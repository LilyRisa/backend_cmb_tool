<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SrtTimeRedistributionService
{
    protected float $charsPerSec;
    protected float $wordsPerSec;
    protected float $minGap;
    protected float $shrinkPadding;
    protected float $maxExtendRatio;
    protected float $maxCps;

    public function __construct(
        float $charsPerSec = 14.0,
        float $wordsPerSec = 2.2,
        float $minGap = 0.12,
        float $shrinkPadding = 0.15,
        float $maxExtendRatio = 1.5,
        float $maxCps = 17.0
    ) {
        $this->charsPerSec = $charsPerSec;
        $this->wordsPerSec = $wordsPerSec;
        $this->minGap = $minGap;
        $this->shrinkPadding = $shrinkPadding;
        $this->maxExtendRatio = $maxExtendRatio;
        $this->maxCps = $maxCps;
    }

    public function redistribute(string $srtContent): string
    {
        $parser = app(SrtParserService::class);
        $parsed = $parser->parse($srtContent);

        $segments = $parsed['entries'];

        if (count($segments) === 0) {
            return $srtContent;
        }

        $segs = array_map(fn($s) => [
            'index' => $s['index'],
            'start' => $this->parseTimestamp($s['start']),
            'end' => $this->parseTimestamp($s['end']),
            'text' => trim($s['text']),
        ], $segments);

        $segs = $this->splitOverflowSubtitles($segs);
        $segs = $this->mergeShortSubtitles($segs);

        $n = count($segs);

        $needed = [];
        for ($i = 0; $i < $n; $i++) {
            $needed[$i] = $this->estimateDuration($segs[$i]['text']);
        }

        $totalShrunk = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $currentDuration = $segs[$i]['end'] - $segs[$i]['start'];
            $surplus = $currentDuration - $needed[$i] - $this->shrinkPadding;

            if ($surplus > 0.05) {
                $segs[$i]['end'] -= $surplus;
                $totalShrunk += $surplus;
            }
        }

        $totalBorrowed = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $currentDuration = $segs[$i]['end'] - $segs[$i]['start'];
            $deficit = $needed[$i] - $currentDuration;

            if ($deficit <= 0.01) {
                continue;
            }

            $originalDuration = $currentDuration;
            $maxExtend = $originalDuration * ($this->maxExtendRatio - 1.0);
            $deficit = min($deficit, $maxExtend);

            if ($deficit <= 0.01) {
                continue;
            }

            if ($i > 0) {
                $gapBefore = $segs[$i]['start'] - $segs[$i - 1]['end'];
                $canBorrowBack = max(0, $gapBefore - $this->minGap);
                $borrowBack = min($deficit, $canBorrowBack);

                if ($borrowBack > 0.01) {
                    $segs[$i]['start'] -= $borrowBack;
                    $deficit -= $borrowBack;
                    $totalBorrowed += $borrowBack;
                }
            }

            if ($deficit > 0.01 && $i < $n - 1) {
                $gapAfter = $segs[$i + 1]['start'] - $segs[$i]['end'];
                $canBorrowFwd = max(0, $gapAfter - $this->minGap);
                $borrowFwd = min($deficit, $canBorrowFwd);

                if ($borrowFwd > 0.01) {
                    $segs[$i]['end'] += $borrowFwd;
                    $deficit -= $borrowFwd;
                    $totalBorrowed += $borrowFwd;
                }
            }

            if ($deficit > 0.01 && $i === $n - 1) {
                $segs[$i]['end'] += $deficit;
                $totalBorrowed += $deficit;
            }
        }

        if ($totalShrunk > 0.01 || $totalBorrowed > 0.01) {
            Log::info('[SrtRetiming] Redistributed', [
                'segments' => $n,
                'shrunk_seconds' => round($totalShrunk, 2),
                'borrowed_seconds' => round($totalBorrowed, 2),
            ]);
        }

        return $this->buildSrt($segs);
    }

    protected function splitOverflowSubtitles(array $segments): array
    {
        $result = [];

        foreach ($segments as $seg) {
            $duration = $seg['end'] - $seg['start'];

            if ($duration > 0 && mb_strlen($seg['text']) / $duration > $this->maxCps) {
                $split = $this->splitSubtitle($seg);
                foreach ($split as $s) {
                    $result[] = $s;
                }
            } else {
                $result[] = $seg;
            }
        }

        return $result;
    }

    protected function splitSubtitle(array $seg): array
    {
        $text = $seg['text'];

        $parts = preg_split('/([,.;!?]\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($parts) <= 2) {
            $words = preg_split('/\s+/u', $text);
            if (count($words) <= 1) {
                return [$seg];
            }
            $half = (int) ceil(count($words) / 2);
            $text1 = implode(' ', array_slice($words, 0, $half));
            $text2 = implode(' ', array_slice($words, $half));
        } else {
            $half = (int) ceil(count($parts) / 2);
            $text1 = trim(implode('', array_slice($parts, 0, $half)));
            $text2 = trim(implode('', array_slice($parts, $half)));
        }

        if ($text1 === '' || $text2 === '') {
            return [$seg];
        }

        $totalLen = mb_strlen($text1) + mb_strlen($text2);
        $ratio = mb_strlen($text1) / $totalLen;
        $totalDur = $seg['end'] - $seg['start'];
        $mid = $seg['start'] + ($totalDur * $ratio);

        return [
            ['start' => $seg['start'], 'end' => $mid, 'text' => $text1],
            ['start' => $mid, 'end' => $seg['end'], 'text' => $text2],
        ];
    }

    protected function mergeShortSubtitles(array $segments): array
    {
        $result = [];
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            $seg = $segments[$i];

            if ($i < $count - 1 && $this->shouldMerge($seg, $segments[$i + 1])) {
                $result[] = [
                    'start' => $seg['start'],
                    'end' => $segments[$i + 1]['end'],
                    'text' => trim($seg['text'] . ' ' . $segments[$i + 1]['text']),
                ];
                $i++;
            } else {
                $result[] = $seg;
            }
        }

        return $result;
    }

    protected function shouldMerge(array $seg, array $next): bool
    {
        $duration = $seg['end'] - $seg['start'];
        $textLen = mb_strlen($seg['text']);

        if ($duration >= 0.6 && $textLen >= 8) {
            return false;
        }

        $gap = $next['start'] - $seg['end'];
        if ($gap > 2.0) {
            return false;
        }

        return true;
    }

    protected function estimateDuration(string $text): float
    {
        $chars = mb_strlen($text);
        $words = str_word_count($text);

        $charDuration = $chars / $this->charsPerSec;
        $wordDuration = $words > 0 ? $words / $this->wordsPerSec : 0;

        $punctuationPause = substr_count($text, ',') * 0.12
            + substr_count($text, '.') * 0.18
            + substr_count($text, '?') * 0.18
            + substr_count($text, '!') * 0.18;

        return max($charDuration, $wordDuration) + $punctuationPause;
    }

    public function parseTimestamp(string $ts): float
    {
        $ts = str_replace(',', '.', trim($ts));

        if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})\.(\d{3})$/', $ts, $m)) {
            return 0.0;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
    }

    public function formatTimestamp(float $seconds): string
    {
        $seconds = max(0, $seconds);

        $h = floor($seconds / 3600);
        $seconds -= $h * 3600;

        $m = floor($seconds / 60);
        $seconds -= $m * 60;

        $s = floor($seconds);
        $ms = round(($seconds - $s) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }

    protected function buildSrt(array $segs): string
    {
        $blocks = [];

        foreach ($segs as $i => $seg) {
            $idx = $i + 1;
            $start = $this->formatTimestamp($seg['start']);
            $end = $this->formatTimestamp($seg['end']);

            $blocks[] = "{$idx}\n{$start} --> {$end}\n{$seg['text']}";
        }

        return implode("\n\n", $blocks) . "\n";
    }
}
