<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorize_issues_a_code_for_verified_user(): void
    {
        $user = User::factory()->create(); // verified by factory default
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt']);

        $response->assertOk()->assertJsonStructure(['code']);
        $this->assertDatabaseHas('oauth_codes', ['user_id' => $user->id, 'client_id' => 'cmb-core-mkt']);
    }

    public function test_authorize_rejects_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'email_not_verified');
    }

    public function test_authorize_rejects_unknown_client(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'not-a-real-client'])
            ->assertStatus(400);
    }

    public function test_callback_redirects_to_desktop_protocol_with_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $code = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/oauth/authorize', ['client_id' => 'cmb-core-mkt'])
            ->json('code');

        $response = $this->get("/oauth/callback?code={$code}&client=cmb-core-mkt&state=xyz");

        $response->assertRedirect();
        $this->assertStringStartsWith('cmbcoremkt://callback?token=', $response->headers->get('Location'));
        $this->assertStringContainsString('state=xyz', $response->headers->get('Location'));
    }

    public function test_callback_rejects_expired_or_missing_code(): void
    {
        $this->get('/oauth/callback?code=bogus&client=cmb-core-mkt')->assertStatus(400);
    }
}
