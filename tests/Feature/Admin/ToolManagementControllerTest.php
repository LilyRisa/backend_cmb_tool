<?php

namespace Tests\Feature\Admin;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_tools(): void
    {
        Tool::factory()->count(3)->create(['type' => 'cmb_core']);

        $response = $this->actingAsAdmin()->get('/admin/tools');

        $response->assertOk();
        $response->assertViewHas('tools', fn ($tools) => $tools->total() === 3);
    }

    public function test_store_creates_a_new_tool_and_unmarks_previous_latest(): void
    {
        $existingLatest = Tool::factory()->create(['type' => 'cmb_core', 'is_latest' => true]);

        $response = $this->actingAsAdmin()->post('/admin/tools', [
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-500',
            'type' => 'cmb_core',
            'version' => '5.0.0',
            'download_url' => 'https://cdn.cmbcore.com/x.exe',
            'file_size' => '210 MB',
            'changelog' => 'Big release',
            'is_active' => '1',
            'is_latest' => '1',
        ]);

        $response->assertRedirect(route('admin.tools.index'));
        $this->assertDatabaseHas('tools', ['slug' => 'cmb-core-marketing-500', 'is_latest' => 1]);
        $this->assertFalse($existingLatest->fresh()->is_latest);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/tools', ['name' => 'X'])
            ->assertSessionHasErrors(['slug', 'type', 'version', 'download_url']);
    }

    public function test_update_edits_an_existing_tool(): void
    {
        $tool = Tool::factory()->create(['type' => 'cmb_core', 'changelog' => 'old']);

        $response = $this->actingAsAdmin()->put("/admin/tools/{$tool->id}", [
            'name' => $tool->name,
            'slug' => $tool->slug,
            'type' => $tool->type,
            'version' => $tool->version,
            'download_url' => $tool->download_url,
            'changelog' => 'new changelog',
            'is_active' => '1',
            'is_latest' => '0',
        ]);

        $response->assertRedirect(route('admin.tools.index'));
        $this->assertEquals('new changelog', $tool->fresh()->changelog);
    }

    public function test_destroy_removes_a_tool(): void
    {
        $tool = Tool::factory()->create();

        $this->actingAsAdmin()->delete("/admin/tools/{$tool->id}")->assertRedirect(route('admin.tools.index'));

        $this->assertDatabaseMissing('tools', ['id' => $tool->id]);
    }

    public function test_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/tools')->assertRedirect();
    }
}
