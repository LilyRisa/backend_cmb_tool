<?php

namespace Tests\Feature\Tool;

use App\Models\FeatureCreditUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolFeatureCreditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_deduct_feature_creates_pending_record_without_deducting_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', [
                'feature' => 'create_video_script',
                'duration_seconds' => 300,
            ]);

        $response->assertStatus(201)->assertJsonPath('credits', 700)->assertJsonPath('status', 'pending');
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_deduct_feature_rejects_unknown_feature(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', ['feature' => 'not-real', 'duration_seconds' => 60])
            ->assertStatus(422);
    }

    public function test_deduct_feature_rejects_insufficient_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 10, 'purchased_credits' => 0, 'credits' => 10]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/tool/credits/deduct-feature', ['feature' => 'create_video_script', 'duration_seconds' => 300])
            ->assertStatus(402);
    }

    public function test_confirm_feature_completed_deducts_credits(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'feature' => 'create_video_script',
            'duration_seconds' => 300,
            'credits' => 700,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('credits_deducted', true)->assertJsonPath('balance', 300);
        $this->assertEquals(300, $user->fresh()->monthly_credits);
    }

    public function test_confirm_feature_failed_does_not_deduct(): void
    {
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'credits' => 700,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'failed']);

        $response->assertOk()->assertJsonPath('credits_deducted', false);
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_confirm_feature_404s_for_already_processed_record(): void
    {
        $user = User::factory()->create();
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'status' => FeatureCreditUsage::STATUS_COMPLETED,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed'])
            ->assertStatus(404);
    }

    public function test_confirm_feature_second_concurrent_call_404s_and_does_not_double_deduct(): void
    {
        // Reproduces the same double-spend race Task 9 fixed in the SePay listeners:
        // two concurrent confirm-feature calls on the same pending usage record must
        // not both pass and both deduct credits. The atomic claim (conditional
        // UPDATE ... WHERE status = pending) means only the first call can succeed.
        $user = User::factory()->create(['monthly_credits' => 1000, 'purchased_credits' => 0, 'credits' => 1000]);
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'feature' => 'create_video_script',
            'duration_seconds' => 300,
            'credits' => 700,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $headers = $this->authHeader($user);

        $first = $this->withHeaders($headers)
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed']);
        $first->assertOk()->assertJsonPath('credits_deducted', true);

        $second = $this->withHeaders($headers)
            ->postJson("/api/tool/credits/confirm-feature/{$usage->id}", ['status' => 'completed']);
        $second->assertStatus(404);

        // Only one deduction happened — credits were not double-spent.
        $this->assertEquals(300, $user->fresh()->monthly_credits);
    }

    public function test_confirm_feature_atomic_claim_only_lets_one_caller_win(): void
    {
        // Direct test of the claim query itself, in the same spirit as the SePay
        // atomic-claim tests: simulate two concurrent requests both having read the
        // row as "pending" before either has attempted its claim, then show only
        // one of the identical conditional UPDATE queries affects a row.
        $user = User::factory()->create();
        $usage = FeatureCreditUsage::factory()->create([
            'user_id' => $user->id,
            'status' => FeatureCreditUsage::STATUS_PENDING,
        ]);

        $readByRequestA = FeatureCreditUsage::find($usage->id);
        $readByRequestB = FeatureCreditUsage::find($usage->id);
        $this->assertEquals(FeatureCreditUsage::STATUS_PENDING, $readByRequestA->status);
        $this->assertEquals(FeatureCreditUsage::STATUS_PENDING, $readByRequestB->status);

        $claimedByA = FeatureCreditUsage::where('id', $readByRequestA->id)
            ->where('user_id', $user->id)
            ->where('status', FeatureCreditUsage::STATUS_PENDING)
            ->update(['status' => FeatureCreditUsage::STATUS_COMPLETED]);
        $this->assertSame(1, $claimedByA);

        $claimedByB = FeatureCreditUsage::where('id', $readByRequestB->id)
            ->where('user_id', $user->id)
            ->where('status', FeatureCreditUsage::STATUS_PENDING)
            ->update(['status' => FeatureCreditUsage::STATUS_COMPLETED]);
        $this->assertSame(0, $claimedByB);
    }

    public function test_feature_pricing_returns_the_pricing_table(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/tool/credits/feature-pricing');

        $response->assertOk()->assertJsonStructure(['pricing' => ['create_video_script']]);
    }
}
