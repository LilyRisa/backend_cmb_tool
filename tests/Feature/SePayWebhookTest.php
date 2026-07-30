<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PendingCreditTopup;
use App\Models\PendingSubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SePayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.webhook_token' => 'test-webhook-secret',
            'sepay.pattern' => 'CMB',
        ]);
    }

    private function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => random_int(100000, 999999),
            'gateway' => 'MBBank',
            'transactionDate' => now()->toDateTimeString(),
            'accountNumber' => '0123456789',
            'subAccount' => '',
            'code' => '',
            'content' => '',
            'transferType' => 'in',
            'description' => 'Chuyen tien',
            'transferAmount' => 0,
            'referenceCode' => 'REF' . random_int(1000, 9999),
            'accumulated' => 0,
        ], $overrides);
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/api/sepay/webhook', $payload, [
            'Authorization' => 'Apikey test-webhook-secret',
        ]);
    }

    public function test_webhook_credits_user_when_topup_transaction_code_matches(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB121234567890',
        ]);

        $response = $this->postWebhook($this->webhookPayload([
            'content' => 'CMB121234567890 chuyen tien',
            'transferAmount' => 30000,
        ]));

        $response->assertNoContent();
        $this->assertEquals(PendingCreditTopup::STATUS_COMPLETED, $topup->fresh()->status);
        $this->assertEquals(5000, $user->fresh()->purchased_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'topup',
            'amount' => 5000,
        ]);
    }

    public function test_webhook_grants_referral_commission_on_topup(): void
    {
        $referrer = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $user = User::factory()->create(['referred_by' => $referrer->id, 'purchased_credits' => 0, 'credits' => 0]);
        PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB999888777',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB999888777',
            'transferAmount' => 30000,
        ]));

        $this->assertEquals(500, $referrer->fresh()->purchased_credits); // 10% of 5000
    }

    public function test_webhook_ignores_amount_below_expected(): void
    {
        $user = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'amount' => 30000,
            'transaction_code' => 'CMB555444333',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB555444333',
            'transferAmount' => 10000,
        ]));

        $this->assertEquals(PendingCreditTopup::STATUS_PENDING, $topup->fresh()->status);
    }

    public function test_webhook_is_idempotent_for_already_completed_topup(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        $topup = PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB111222333',
        ]);

        $payload = $this->webhookPayload(['content' => 'CMB111222333', 'transferAmount' => 30000]);
        $this->postWebhook($payload)->assertNoContent();

        // Second webhook with a different SePay transaction id but same content — must not double-credit
        $this->postWebhook(array_merge($payload, ['id' => $payload['id'] + 1]))->assertNoContent();

        $this->assertEquals(5000, $user->fresh()->purchased_credits);
    }

    public function test_webhook_activates_subscription_when_pattern_matches(): void
    {
        $user = User::factory()->create(['package_type' => 'free', 'package_expires_at' => null, 'monthly_credits' => 0]);
        $payment = PendingSubscriptionPayment::factory()->create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'amount' => 99000,
            'duration_days' => 30,
            'monthly_credits' => 5000,
            'transaction_code' => 'CMBSUB777666555',
        ]);

        $response = $this->postWebhook($this->webhookPayload([
            'content' => 'CMBSUB777666555',
            'transferAmount' => 99000,
        ]));

        $response->assertNoContent();
        $this->assertEquals(PendingSubscriptionPayment::STATUS_COMPLETED, $payment->fresh()->status);
        $fresh = $user->fresh();
        $this->assertEquals('premium', $fresh->package_type);
        $this->assertEquals(5000, $fresh->monthly_credits);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_webhook_rejects_invalid_bearer_token(): void
    {
        $response = $this->postJson('/api/sepay/webhook', $this->webhookPayload(), [
            'Authorization' => 'Apikey wrong-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_ignores_outgoing_transfers(): void
    {
        $user = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);
        PendingCreditTopup::factory()->create([
            'user_id' => $user->id,
            'credits' => 5000,
            'amount' => 30000,
            'transaction_code' => 'CMB222333444',
        ]);

        $this->postWebhook($this->webhookPayload([
            'content' => 'CMB222333444',
            'transferAmount' => 30000,
            'transferType' => 'out',
        ]));

        $this->assertEquals(0, $user->fresh()->purchased_credits);
    }
}
