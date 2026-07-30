<?php

namespace Tests\Feature\Tool;

use App\Models\PendingSubscriptionPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.account_number' => '0123456789',
            'sepay.account_name' => 'CONG TY TNHH CMB',
            'sepay.bank_name' => 'MBBank',
        ]);
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_current_returns_no_subscription_for_free_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/subscription');

        $response->assertOk()
            ->assertJsonPath('has_subscription', false)
            ->assertJsonPath('is_premium', false)
            ->assertJsonStructure(['plans']);
    }

    public function test_current_returns_active_subscription_details(): void
    {
        $user = User::factory()->create(['package_type' => 'premium', 'package_expires_at' => now()->addDays(20)]);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => Subscription::STATUS_ACTIVE,
            'expires_at' => now()->addDays(20),
        ]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/subscription');

        $response->assertOk()->assertJsonPath('has_subscription', true)->assertJsonPath('is_premium', true);
    }

    public function test_subscribe_creates_pending_payment_with_bank_info(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/subscription/subscribe', ['plan' => 'monthly']);

        $response->assertOk()->assertJsonStructure(['subscription_payment_id', 'plan', 'transaction_code', 'bank_info', 'qr_url']);
        $this->assertDatabaseHas('pending_subscription_payments', [
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => PendingSubscriptionPayment::STATUS_PENDING,
        ]);
    }

    public function test_subscribe_rejects_unknown_plan(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/subscription/subscribe', ['plan' => 'not-a-real-plan'])
            ->assertStatus(422);
    }

    public function test_status_returns_pending_payment_state(): void
    {
        $user = User::factory()->create();
        $payment = PendingSubscriptionPayment::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/subscription/status/{$payment->id}");

        $response->assertOk()->assertJsonPath('id', $payment->id)->assertJsonPath('status', 'pending');
    }
}
