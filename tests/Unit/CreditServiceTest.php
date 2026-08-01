<?php

namespace Tests\Unit;

use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_credits_rounds_up_by_ten_chars_per_credit(): void
    {
        $this->assertEquals(1, CreditService::calculateCredits('short'));
        $this->assertEquals(2, CreditService::calculateCredits('exactly ten')); // 11 chars -> ceil(11/10)=2
        $this->assertEquals(0, CreditService::calculateCredits(''));
    }

    public function test_credits_to_minutes_uses_default_chars_per_minute(): void
    {
        // 100 credits * 10 chars/credit = 1000 chars / 800 chars-per-min = 1.25
        $this->assertEquals(1.25, CreditService::creditsToMinutes(100, 800));
    }

    public function test_calculate_feature_credits_for_known_feature(): void
    {
        $result = CreditService::calculateFeatureCredits('create_video_script', 300);

        $this->assertEquals([
            'feature' => 'create_video_script',
            'duration_seconds' => 300,
            'credits' => 700,
        ], $result);
    }

    public function test_calculate_feature_credits_returns_null_for_unknown_feature(): void
    {
        $this->assertNull(CreditService::calculateFeatureCredits('not_a_real_feature', 60));
    }

    public function test_calculate_feature_credits_throws_when_duration_exceeds_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreditService::calculateFeatureCredits('create_video_script', 99999);
    }

    public function test_get_feature_pricing_returns_the_full_table(): void
    {
        $pricing = CreditService::getFeaturePricing();

        $this->assertArrayHasKey('create_video_script', $pricing);
        $this->assertEquals(140, $pricing['create_video_script']['credits_per_minute']);
    }

    public function test_calculate_feature_credits_for_image_generation_uses_configured_price_per_image(): void
    {
        \App\Models\SystemSetting::setImageGenCreditsPerImage(150);

        $result = CreditService::calculateFeatureCredits('image_generation', 3);

        $this->assertEquals([
            'feature' => 'image_generation',
            'duration_seconds' => 3,
            'credits' => 450,
        ], $result);
    }

    public function test_calculate_feature_credits_for_image_generation_uses_default_price_when_unset(): void
    {
        $result = CreditService::calculateFeatureCredits('image_generation', 1);

        $this->assertEquals(200, $result['credits']);
    }

    public function test_calculate_feature_credits_throws_when_image_count_exceeds_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreditService::calculateFeatureCredits('image_generation', 5);
    }
}
