<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ad-hoc routes registered directly on the already-booted router —
        // this is a plain Laravel app TestCase, not Orchestra Testbench,
        // so there is no defineRoutes() hook to override.
        Route::middleware(['auth:sanctum', 'token.version'])->get('/__test/token-version', function () {
            return response()->json(['ok' => true]);
        });

        Route::middleware(['auth:sanctum', 'email.verified'])->get('/__test/email-verified', function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_stale_token_version_is_rejected(): void
    {
        $user = User::factory()->create(['token_version' => 2]);
        $token = $user->createToken('test')->plainTextToken;

        // Simulate the token being issued when token_version was 1 (e.g. password reset happened after)
        $user->update(['token_version' => 3]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/token-version');

        $response->assertStatus(401)->assertJson(['error' => 'Token expired']);
    }

    public function test_current_token_version_is_accepted(): void
    {
        $user = User::factory()->create(['token_version' => 1]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/token-version');

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_unverified_email_is_blocked(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/email-verified');

        $response->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    }

    public function test_verified_email_passes(): void
    {
        $user = User::factory()->create(); // factory sets email_verified_at = now()
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/__test/email-verified');

        $response->assertOk();
    }
}
