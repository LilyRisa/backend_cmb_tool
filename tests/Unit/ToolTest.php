<?php

namespace Tests\Unit;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_tool_with_all_fields(): void
    {
        $tool = Tool::create([
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-421',
            'type' => 'cmb_core',
            'version' => '4.2.1',
            'description' => 'Desktop automation tool',
            'download_url' => 'https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%204.2.1.exe',
            'file_size' => '202 MB',
            'sha256' => '17C8248621BE5C34CC7FE2BA3F49F404AA98DFF79447BBC374CC97A01FE33A40',
            'changelog' => 'Fix login and facebook processing bugs',
            'is_active' => true,
            'is_latest' => true,
            'download_count' => 0,
            'released_at' => '2026-07-05',
        ]);

        $this->assertTrue($tool->is_active);
        $this->assertTrue($tool->is_latest);
        $this->assertIsInt($tool->download_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $tool->released_at);
    }

    public function test_active_scope_filters_correctly(): void
    {
        Tool::factory()->create(['is_active' => true]);
        Tool::factory()->create(['is_active' => false]);

        $this->assertCount(1, Tool::where('is_active', true)->get());
    }
}
