<?php

namespace Tests\Feature;

use Tests\TestCase;

class ToolSpaRouteTest extends TestCase
{
    public function test_root_renders_tool_spa(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_login_renders_tool_spa(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_register_renders_tool_spa(): void
    {
        $response = $this->get('/register');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_unmatched_path_falls_back_to_tool_spa(): void
    {
        $response = $this->get('/whatever/nested/path');
        $response->assertOk();
        $response->assertViewIs('tool-spa');
    }

    public function test_admin_login_is_not_shadowed_by_catch_all(): void
    {
        $response = $this->get('/admin/login');
        $response->assertOk();
        $response->assertViewIs('admin.login');
    }

    public function test_api_me_is_not_shadowed_by_catch_all(): void
    {
        $response = $this->getJson('/api/me');
        $response->assertStatus(401);
    }

    public function test_unmatched_api_path_returns_404_not_spa(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');
        $response->assertStatus(404);
    }

    public function test_unmatched_api_path_returns_404_for_non_get_verbs(): void
    {
        // Regression guard for a GET-only Route::fallback(): a DELETE (or any
        // non-GET verb) to an unmatched /api/* path must still 404, not 405 —
        // 405 would mean Laravel's alternate-verb check is treating the SPA
        // fallback as a structural match for this URI.
        $response = $this->deleteJson('/api/this-route-does-not-exist');
        $response->assertStatus(404);
    }
}
