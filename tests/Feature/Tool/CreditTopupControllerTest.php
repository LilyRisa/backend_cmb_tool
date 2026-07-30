<?php

namespace Tests\Feature\Tool;

use App\Models\PendingCreditTopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditTopupControllerTest extends TestCase
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

    public function test_packages_lists_all_configured_packages(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/packages');

        $response->assertOk()->assertJsonCount(5, 'packages');
    }

    public function test_create_topup_returns_bank_info_and_qr(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'starter']);

        $response->assertOk()->assertJsonStructure(['topup_id', 'package', 'transaction_code', 'bank_info', 'qr_url']);
        $this->assertDatabaseHas('pending_credit_topups', [
            'user_id' => $user->id,
            'package_id' => 'starter',
            'credits' => 5000,
            'amount' => 30000,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);
    }

    public function test_create_topup_rejects_unknown_package(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'not-a-real-package'])
            ->assertStatus(422);
    }

    public function test_create_topup_fails_when_bank_not_configured(): void
    {
        config(['sepay.account_number' => null]);
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/topup', ['package_id' => 'starter'])
            ->assertStatus(500);
    }

    public function test_topup_status_returns_current_state(): void
    {
        $user = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/tool/credits/topup/status/{$topup->id}");

        $response->assertOk()->assertJsonPath('id', $topup->id)->assertJsonPath('status', 'pending');
    }

    public function test_topup_status_404s_for_another_users_topup(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $topup = PendingCreditTopup::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->authHeader($other))
            ->getJson("/api/tool/credits/topup/status/{$topup->id}")
            ->assertStatus(404);
    }
}
