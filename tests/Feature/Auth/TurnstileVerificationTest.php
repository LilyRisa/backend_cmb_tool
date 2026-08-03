<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_without_turnstile_token_when_not_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => null]);

        User::factory()->create(['email' => 'nocaptcha@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'nocaptcha@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_rejects_missing_turnstile_token_when_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);

        User::factory()->create(['email' => 'missingtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'missingtoken@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }

    public function test_login_rejects_invalid_turnstile_token(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200),
        ]);

        User::factory()->create(['email' => 'badtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'badtoken@example.com',
            'password' => 'password',
            'cf_turnstile_token' => 'bad-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_failed');
    }

    public function test_login_succeeds_with_valid_turnstile_token(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);

        User::factory()->create(['email' => 'goodtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'goodtoken@example.com',
            'password' => 'password',
            'cf_turnstile_token' => 'good-token',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_register_rejects_missing_turnstile_token_when_configured(): void
    {
        config(['services.cloudflare_turnstile.secret_key' => 'test-secret']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }
}
