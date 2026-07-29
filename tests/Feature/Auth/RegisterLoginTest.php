<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_with_premium_trial_and_returns_token(): void
    {
        $response = $this->postJson('/api/user/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'user', 'email_verified', 'minutes_remaining']);

        $this->assertDatabaseHas('users', [
            'email' => 'bob@example.com',
            'package_type' => 'premium',
        ]);

        $user = User::where('email', 'bob@example.com')->first();
        $this->assertEquals(5000, $user->monthly_credits);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'bonus',
            'amount' => 5000,
        ]);
        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'action' => 'register',
        ]);
    }

    public function test_register_grants_referral_bonus_to_referrer(): void
    {
        $referrer = User::factory()->create(['purchased_credits' => 0, 'credits' => 0]);

        $this->postJson('/api/user/register', [
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'secret123',
            'ref' => $referrer->referral_code,
        ])->assertOk();

        $this->assertEquals(800, $referrer->fresh()->purchased_credits);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/user/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/user/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_version', 'email_verified']);
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_login_rejects_invalid_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/user/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }
}
