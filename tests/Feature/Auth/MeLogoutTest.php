<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MeLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_current_user_with_package_info(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(5),
            'monthly_credits' => 100,
            'purchased_credits' => 20,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('package_current', 'premium')
            ->assertJsonPath('package_expired', false)
            ->assertJsonPath('monthly_credits', 100)
            ->assertJsonPath('purchased_credits', 20)
            ->assertJsonStructure(['avatar_url', 'minutes_remaining', 'email_verified']);
    }

    public function test_me_marks_expired_package_as_free(): void
    {
        $user = User::factory()->create([
            'package_type' => 'premium',
            'package_expires_at' => now()->subDay(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('package_current', 'free')
            ->assertJsonPath('package_expired', true)
            ->assertJsonPath('package_last', 'premium');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        // Laravel's Sanctum guard memoizes the resolved user on the guard
        // instance for the lifetime of the container (Illuminate\Auth\RequestGuard::user()
        // caches after first resolution). In production every HTTP request gets a
        // fresh container/guard, so this never matters — but within a single test
        // method the app/container persists across the two HTTP calls above, so the
        // second call would otherwise see the stale cached user despite the token
        // row having been deleted. Forgetting the resolved guards forces Sanctum to
        // re-resolve the authenticated user (and therefore re-check the now-deleted
        // token) on the next request, matching real request-per-request behavior.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_unauthenticated_api_request_without_json_headers_returns_401_not_500(): void
    {
        // Deliberately using the plain get() helper (no Accept: application/json
        // header, unlike getJson()) so $request->expectsJson() is false. There is
        // no named "login" route in this app, so the default AuthenticationException
        // handling used to fall through to route('login') and 500 with a
        // RouteNotFoundException instead of returning 401.
        $response = $this->get('/api/me');

        $response->assertStatus(401);
    }
}
