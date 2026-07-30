<?php

namespace Tests\Feature\Tool;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolCreditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_balance_returns_credit_summary(): void
    {
        $user = User::factory()->create([
            'monthly_credits' => 3000,
            'purchased_credits' => 500,
            'package_type' => 'premium',
        ]);
        CreditTransaction::factory()->create([
            'user_id' => $user->id,
            'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -200,
            'balance_after' => 3300,
        ]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits');

        $response->assertOk()
            ->assertJsonPath('monthly_credits', 3000)
            ->assertJsonPath('purchased_credits', 500)
            ->assertJsonPath('credits', 3500)
            ->assertJsonPath('total_used', 200)
            ->assertJsonPath('is_premium', true);
    }

    public function test_transactions_returns_paginated_history(): void
    {
        $user = User::factory()->create();
        CreditTransaction::factory()->count(3)->create(['user_id' => $user->id, 'type' => 'deduct']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/transactions');

        $response->assertOk()->assertJsonCount(3, 'transactions');
    }

    public function test_transactions_filters_by_type(): void
    {
        $user = User::factory()->create();
        CreditTransaction::factory()->create(['user_id' => $user->id, 'type' => 'deduct']);
        CreditTransaction::factory()->create(['user_id' => $user->id, 'type' => 'topup']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/tool/credits/transactions?type=topup');

        $response->assertOk()->assertJsonCount(1, 'transactions');
    }

    public function test_referral_info_generates_code_if_missing(): void
    {
        $user = User::factory()->create(['referral_code' => null]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/referral');

        $response->assertOk()->assertJsonStructure(['referral_code', 'referral_link', 'total_referrals']);
        $this->assertNotNull($user->fresh()->referral_code);
    }

    public function test_referral_info_counts_referred_users(): void
    {
        $referrer = User::factory()->create();
        User::factory()->count(2)->create(['referred_by' => $referrer->id]);

        $response = $this->withHeaders($this->authHeader($referrer))->getJson('/api/tool/credits/referral');

        $response->assertOk()->assertJsonPath('total_referrals', 2);
    }
}
