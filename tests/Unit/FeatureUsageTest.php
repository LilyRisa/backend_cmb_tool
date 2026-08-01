<?php

namespace Tests\Unit;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_deletion_preserves_the_row_with_null_user_id(): void
    {
        $user = User::factory()->create();
        $usage = FeatureUsage::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertNull($usage->fresh()->user_id);
    }

    public function test_casts_are_applied(): void
    {
        $usage = FeatureUsage::factory()->create(['usage_count' => 5]);

        $this->assertIsInt($usage->fresh()->usage_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $usage->fresh()->last_used_at);
    }
}
