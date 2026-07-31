<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    public function test_layout_renders_page_title_and_content(): void
    {
        $html = view('admin.dashboard', [
            'totalUsers' => 1,
            'premiumUsers' => 1,
            'newUsersToday' => 1,
        ])->render();

        // Existing dashboard view is intentionally untouched (Global
        // Constraints: minimize blast radius) — this just proves the
        // rendering pipeline still works after adding the new layout files
        // alongside it.
        $this->assertStringContainsString('Admin Dashboard', $html);
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
