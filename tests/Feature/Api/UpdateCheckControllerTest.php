<?php

namespace Tests\Feature\Api;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_version_returns_the_latest_active_tool_for_type(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.0', 'is_active' => true, 'is_latest' => false]);
        $latest = Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.1', 'is_active' => true, 'is_latest' => true]);

        $response = $this->getJson('/api/cmb/latest-version?type=cmb_core');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.version', '4.2.1');
        $this->assertEquals($latest->download_url, $response->json('data.download_url'));
    }

    public function test_latest_version_ignores_inactive_tools(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'is_active' => false, 'is_latest' => true]);

        $this->getJson('/api/cmb/latest-version?type=cmb_core')->assertStatus(404);
    }

    public function test_latest_version_requires_type(): void
    {
        $this->getJson('/api/cmb/latest-version')->assertStatus(422);
    }

    public function test_versions_returns_all_active_versions_for_type_ordered_newest_first(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.1.0', 'released_at' => '2026-01-01', 'is_active' => true]);
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.0', 'released_at' => '2026-03-01', 'is_active' => true]);
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.0.0', 'released_at' => '2025-12-01', 'is_active' => false]);

        $response = $this->getJson('/api/cmb/versions?type=cmb_core');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals('4.2.0', $response->json('data.0.version'));
    }
}
