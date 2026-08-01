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

    const FEATURE_PRICING = [
        'create_video_script' => [
            'credits_per_minute' => 140,
            'max_duration_seconds' => 1200,
        ],
        'image_generation' => [
            'max_count' => 4,
        ],
    ];

    public static function calculateFeatureCredits(string $feature, int $durationSeconds): ?array
    {
        if (!isset(self::FEATURE_PRICING[$feature])) {
            return null;
        }

        $pricing = self::FEATURE_PRICING[$feature];

        // image_generation is priced per-image (count-based), not per-minute — the
        // incoming $durationSeconds value represents the image count (n) for this
        // feature specifically, and the credit-per-image rate is admin-configurable
        // via SystemSetting rather than a hardcoded constant.
        if ($feature === 'image_generation') {
            $maxCount = $pricing['max_count'];
            $count = $durationSeconds;

            if ($count < 1 || $count > $maxCount) {
                throw new \InvalidArgumentException(
                    "Image count must be between 1 and {$maxCount} for feature '{$feature}'."
                );
            }

            $creditsPerImage = SystemSetting::getImageGenCreditsPerImage();

            return [
                'feature' => $feature,
                'duration_seconds' => $count,
                'credits' => $count * $creditsPerImage,
            ];
        }

        $maxDuration = $pricing['max_duration_seconds'];

        if ($durationSeconds < 1 || $durationSeconds > $maxDuration) {
            throw new \InvalidArgumentException(
                "Duration must be between 1 and {$maxDuration} seconds for feature '{$feature}'."
            );
        }

        $minutes = ceil($durationSeconds / 60);
        $credits = (int) ($minutes * $pricing['credits_per_minute']);

        return [
            'feature' => $feature,
            'duration_seconds' => $durationSeconds,
            'credits' => $credits,
        ];
    }

    public static function getFeaturePricing(): array
    {
        return self::FEATURE_PRICING;
    }
}
