<?php

namespace App\Services;

use App\Models\SystemSetting;

class CreditService
{
    const CHARS_PER_CREDIT = 10;
    const SRT_TRANSLATE_CHARS_PER_CREDIT = 50;

    public static function calculateCredits(string $text): int
    {
        $charCount = mb_strlen($text);
        if ($charCount <= 0) return 0;

        return (int) ceil($charCount / self::CHARS_PER_CREDIT);
    }

    public static function calculateSrtTranslateCredits(int $characterCount): int
    {
        if ($characterCount <= 0) return 0;

        return (int) ceil($characterCount / self::SRT_TRANSLATE_CHARS_PER_CREDIT);
    }

    public static function characterCount(string $text): int
    {
        return mb_strlen($text);
    }

    public static function estimate(string $text): array
    {
        $characters = mb_strlen($text);

        return [
            'characters' => $characters,
            'credits' => $characters <= 0 ? 0 : (int) ceil($characters / self::CHARS_PER_CREDIT),
        ];
    }

    public static function creditsToMinutes(int $credits, ?int $charsPerMinute = null): float
    {
        $cpm = max($charsPerMinute ?? SystemSetting::getCharsPerMinute(), 1);

        return round(($credits * self::CHARS_PER_CREDIT) / $cpm, 2);
    }

    public static function charactersToMinutes(int $characters, ?int $charsPerMinute = null): float
    {
        $cpm = max($charsPerMinute ?? SystemSetting::getCharsPerMinute(), 1);

        return round($characters / $cpm, 2);
    }

    public static function charactersToCredits(int $charactersUsed): int
    {
        if ($charactersUsed <= 0) return 0;

        return (int) ceil($charactersUsed / self::CHARS_PER_CREDIT);
    }
}
