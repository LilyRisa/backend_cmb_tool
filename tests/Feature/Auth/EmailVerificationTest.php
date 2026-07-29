<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_sends_verification_email(): void
    {
        Mail::fake();

        $this->postJson('/api/user/register', [
            'name' => 'Dave',
            'email' => 'dave@example.com',
            'password' => 'secret123',
        ])->assertOk();

        Mail::assertSent(\App\Mail\EmailVerificationMail::class);
    }

    public function test_verify_email_with_valid_token_marks_user_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $plainToken = Str::random(64);

        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($plainToken),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get("/email/verify/{$plainToken}");

        $response->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_with_invalid_token_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get('/email/verify/not-a-real-token')->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_verification_is_rate_limited_within_two_minutes(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/resend-verification')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/resend-verification')
            ->assertStatus(429);
    }
}
