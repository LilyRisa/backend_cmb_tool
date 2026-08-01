<?php

namespace Tests\Feature\Api;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_track_creates_a_new_usage_row_on_first_use(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', ['feature_name' => 'video-dub']);

        $response->assertOk()->assertJsonPath('data.usage_count', 1);
        $this->assertDatabaseHas('feature_usages', ['user_id' => $user->id, 'feature_name' => 'video-dub', 'usage_count' => 1]);
    }

    public function test_track_increments_an_existing_usage_row(): void
    {
        $user = User::factory()->create();
        FeatureUsage::factory()->create(['user_id' => $user->id, 'feature_name' => 'video-dub', 'usage_count' => 4]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', ['feature_name' => 'video-dub']);

        $response->assertOk()->assertJsonPath('data.usage_count', 5);
        $this->assertDatabaseCount('feature_usages', 1);
    }

    public function test_track_requires_feature_name(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', [])
            ->assertStatus(422);
    }

    public function test_track_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/feature-usage', ['feature_name' => 'video-dub'])->assertStatus(401);
    }
}
