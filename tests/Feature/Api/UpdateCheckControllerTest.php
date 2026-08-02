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

    public function test_legacy_latest_requires_no_type_param_and_matches_old_backends_response_shape(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'is_active' => true, 'is_latest' => false]);
        $latest = Tool::factory()->create([
            'type' => 'cmb_core',
            'version' => '4.2.1',
            'is_active' => true,
            'is_latest' => true,
            'file_size' => '202 MB',
            'sha256' => str_repeat('a', 64),
        ]);

        // No ?type= — the legacy route hardcodes 'cmb_core', unlike getCmbLatestVersion().
        $response = $this->getJson('/api/cmb/latest');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertEquals('4.2.1', $response->json('data.version'));
        $this->assertEquals($latest->download_url, $response->json('data.download_url'));
        $this->assertEquals($latest->download_url, $response->json('data.direct_url'));
        $this->assertEquals(str_repeat('a', 64), $response->json('data.sha256'));
        $this->assertEquals('202 MB', $response->json('data.file_size_formatted'));
        // 202 MB → raw byte count, not the formatted string — the client's
        // download-progress math divides by this and needs a number.
        $this->assertEquals((int) round(202 * 1024 * 1024), $response->json('data.file_size'));
    }

    public function test_legacy_latest_404s_when_no_active_latest_tool_exists(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'is_active' => false, 'is_latest' => true]);

        $this->getJson('/api/cmb/latest')->assertStatus(404)->assertJsonPath('success', false);
    }
}
