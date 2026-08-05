<?php

namespace Tests\Feature;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSpaRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_renders_public_marketing_homepage(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('cmb-landing');
    }

    public function test_homepage_shows_download_link_for_latest_active_tool(): void
    {
        $tool = Tool::factory()->create([
            'type' => 'cmb_core',
            'version' => '5.1.0',
            'download_url' => 'https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT-5.1.0.exe',
            'is_active' => true,
            'is_latest' => true,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewHas('latestTool', fn ($latestTool) => $latestTool->is($tool));
        $response->assertSee($tool->download_url, false);
        $response->assertSee('v5.1.0', false);
    }

    public function test_homepage_hides_download_link_when_no_latest_tool_exists(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewHas('latestTool', fn ($latestTool) => $latestTool === null);
        $response->assertSee('Sắp ra mắt');
        $response->assertDontSee('.exe', false);
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

    public function test_unmatched_api_path_route_excludes_csrf_and_session_middleware(): void
    {
        // Regression guard for a real-world-only failure mode: PHPUnit's
        // VerifyCsrfToken::runningUnitTests() unconditionally bypasses CSRF
        // during tests, so a plain HTTP assertion here would pass whether or
        // not the route is actually CSRF-exempt — it would only catch this
        // bug in production (419 instead of 404). Assert the exemption
        // directly against routing config instead.
        //
        // This route is registered in routes/web.php, which runs under the
        // full `web` middleware group (app/Http/Kernel.php) — not just CSRF.
        // Without excluding session/cookie middleware too, every unmatched
        // /api/* hit (e.g. routine bot/scanner traffic, unthrottled) would
        // write a session file to disk and set Set-Cookie headers just to
        // return a 404. Assert the full exclusion set, not just CSRF.
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/this-route-does-not-exist', 'DELETE')
        );
        $excluded = $route->excludedMiddleware();

        foreach ([
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \App\Http\Middleware\EncryptCookies::class,
        ] as $middlewareClass) {
            $this->assertContains($middlewareClass, $excluded);
        }
    }

    public function test_route_registered_after_boot_is_not_shadowed_by_the_fallback(): void
    {
        // Regression guard for the round-1 bug this migration fixed: a plain
        // Route::get('/{any}')->where('any', '.*') catch-all matches in
        // registration order, so it shadows any route added to the router
        // AFTER application boot (e.g. a route a test registers in setUp()).
        // Route::fallback() only fires when nothing else matches at dispatch
        // time, so it correctly defers to routes registered late. Registering
        // a route here, at test-time (i.e. after boot), and confirming it
        // still wins is what makes this a real regression guard rather than
        // something the existing route-order-dependent tests happen to cover
        // incidentally.
        \Illuminate\Support\Facades\Route::get('/__late/registered', fn () => response()->json(['ok' => true]));
        $this->getJson('/__late/registered')->assertOk()->assertJson(['ok' => true]);
    }
}
