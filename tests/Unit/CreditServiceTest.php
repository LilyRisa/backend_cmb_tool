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
}
