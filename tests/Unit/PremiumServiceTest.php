<?php

namespace Tests\Unit;

use App\Models\CreditTransaction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PremiumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_sets_premium_and_expiry_from_now_for_free_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free', 'package_expires_at' => null, 'monthly_credits' => 0]);

        $subscription = PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ], 'tx-123');

        $fresh = $user->fresh();
        $this->assertEquals('premium', $fresh->package_type);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $fresh->package_expires_at->timestamp, 5);
        $this->assertEquals(5000, $fresh->monthly_credits);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertEquals('tx-123', $subscription->transaction_id);
    }

    public function test_activate_extends_cumulatively_from_existing_future_expiry(): void
    {
        $futureExpiry = now()->addDays(10);
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => $futureExpiry,
            'monthly_credits' => 5000,
        ]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $fresh = $user->fresh();
        $this->assertEqualsWithDelta($futureExpiry->copy()->addDays(30)->timestamp, $fresh->package_expires_at->timestamp, 5);
    }

    public function test_activate_never_decreases_monthly_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 8000, 'package_type' => 'free', 'package_expires_at' => null]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $this->assertEquals(8000, $user->fresh()->monthly_credits);
    }

    public function test_activate_records_credit_transaction_only_when_credits_increase(): void
    {
        $user = User::factory()->create(['monthly_credits' => 0, 'package_type' => 'free', 'package_expires_at' => null]);

        PremiumService::activate($user, [
            'id' => 'monthly',
            'price' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
        ]);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => CreditTransaction::TYPE_SUBSCRIPTION,
            'amount' => 5000,
        ]);
    }
}
