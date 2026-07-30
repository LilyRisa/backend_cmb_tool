<?php

namespace Tests\Unit;

use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_is_active_when_status_active_and_not_expired(): void
    {
        $sub = Subscription::factory()->create([
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($sub->isActive());
    }

    public function test_subscription_is_not_active_when_expired(): void
    {
        $sub = Subscription::factory()->create([
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($sub->isActive());
    }

    public function test_user_active_subscription_returns_latest_active(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
        $active = Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertEquals($active->id, $user->activeSubscription()->id);
    }

    public function test_pending_subscription_payment_found_by_normalized_transaction_code(): void
    {
        PendingSubscriptionPayment::factory()->create([
            'transaction_code' => 'CMBSUB121234567890',
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        $found = PendingSubscriptionPayment::findByTransactionCode('CMB SUB1 2123-4567890');

        $this->assertNotNull($found);
    }

    public function test_pending_subscription_payment_mark_completed(): void
    {
        $payment = PendingSubscriptionPayment::factory()->create([
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);

        $payment->markCompleted();

        $this->assertEquals(PendingSubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->completed_at);
    }

    public function test_pending_credit_topup_found_by_normalized_transaction_code(): void
    {
        PendingCreditTopup::factory()->create([
            'transaction_code' => 'CMB121234567890',
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);

        $found = PendingCreditTopup::findByTransactionCode('CMB 121-2345 67890');

        $this->assertNotNull($found);
    }
}
