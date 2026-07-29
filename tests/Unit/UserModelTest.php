<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_gets_a_unique_referral_code_on_creation(): void
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->assertNotEmpty($user->referral_code);
        $this->assertEquals(8, strlen($user->referral_code));
    }

    public function test_is_premium_returns_false_for_free_package(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $this->assertFalse($user->isPremium());
    }

    public function test_is_premium_returns_true_for_unexpired_premium_package(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($user->isPremium());
    }

    public function test_deduct_credits_fails_when_insufficient(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 5,
            'purchased_credits' => 0,
            'credits' => 5,
        ]);

        $result = $user->deductCredits(10, 'test deduction');

        $this->assertFalse($result);
        $this->assertEquals(5, $user->fresh()->monthly_credits);
    }

    public function test_deduct_credits_succeeds_and_records_transaction(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 20,
            'purchased_credits' => 5,
            'credits' => 25,
        ]);

        $result = $user->deductCredits(22, 'test deduction');

        $this->assertTrue($result);
        $fresh = $user->fresh();
        $this->assertEquals(0, $fresh->monthly_credits);
        $this->assertEquals(3, $fresh->purchased_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'amount' => -22,
            'balance_after' => 3,
        ]);
    }
}
