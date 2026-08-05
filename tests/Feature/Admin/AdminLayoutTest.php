<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_full_stat_and_chart_data(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString('Total Users', $html);
        $this->assertStringContainsString('creditChart', $html);
    }

    public function test_breadcrumb_partial_renders_linked_and_current_items(): void
    {
        $html = view('admin._partials._breadcrumb', [
            'items' => [
                ['label' => 'Video Dub Jobs', 'url' => '/admin/videodub'],
                ['label' => 'Job #5'],
            ],
        ])->render();

        $this->assertStringContainsString('Video Dub Jobs', $html);
        $this->assertStringContainsString('/admin/videodub', $html);
        $this->assertStringContainsString('Job #5', $html);
    }

    public function test_layout_extends_correctly_with_a_throwaway_page(): void
    {
        $blade = <<<'BLADE'
@extends('admin.layout')
@section('title', 'Test Page')
@section('page-title', 'Test Page')
@section('content')
<p>hello from test page</p>
@endsection
BLADE;

        \Illuminate\Support\Facades\File::put(resource_path('views/admin/_test_throwaway.blade.php'), $blade);

        $html = view('admin._test_throwaway')->render();

        \Illuminate\Support\Facades\File::delete(resource_path('views/admin/_test_throwaway.blade.php'));

        $this->assertStringContainsString('Test Page', $html);
        $this->assertStringContainsString('hello from test page', $html);
        $this->assertStringContainsString('bootstrap', strtolower($html));
    }
}
