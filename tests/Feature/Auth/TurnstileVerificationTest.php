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
        config([
            'services.cloudflare_turnstile.site_key' => 'test-site',
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);

        User::factory()->create(['email' => 'missingtoken@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'missingtoken@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }

    public function test_login_rejects_invalid_turnstile_token(): void
    {
        config([
            'services.cloudflare_turnstile.site_key' => 'test-site',
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);
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
        config([
            'services.cloudflare_turnstile.site_key' => 'test-site',
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);
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
        config([
            'services.cloudflare_turnstile.site_key' => 'test-site',
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'turnstile_required');
    }

    public function test_login_succeeds_when_cloudflare_returns_a_server_error(): void
    {
        // Fix 1: Http::asForm()->post(...) does NOT throw on a non-2xx
        // response — only network-level failures reach the catch block. If
        // Cloudflare returns e.g. a 503, the old code called ->json() on it
        // anyway, got null/empty, and treated that as "success" => false,
        // returning a 422 turnstile_failed — blocking every login/register
        // in the app during a Cloudflare outage. The design intent (per the
        // method's own comments) is fail-open; this proves it actually does.
        config([
            'services.cloudflare_turnstile.site_key' => 'test-site',
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response('Service Unavailable', 503),
        ]);

        User::factory()->create(['email' => 'cfoutage@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cfoutage@example.com',
            'password' => 'password',
            'cf_turnstile_token' => 'some-token',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_succeeds_without_token_when_only_secret_key_is_configured(): void
    {
        // Fix 2: an operator setting CLOUDFLARE_CAPTCHA_SECRET_KEY without
        // CLOUDFLARE_CAPTCHA_SITE_KEY is a natural mistake — server-side
        // verification is what this task added. If only secret_key is set,
        // the frontend widget never renders (it's driven by site_key), so no
        // token can ever arrive, and the old code would 422 every login
        // forever. verifyTurnstile() must no-op unless BOTH keys are set.
        config([
            'services.cloudflare_turnstile.site_key' => null,
            'services.cloudflare_turnstile.secret_key' => 'test-secret',
        ]);

        User::factory()->create(['email' => 'splitbrain@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'splitbrain@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token']);
    }
}
