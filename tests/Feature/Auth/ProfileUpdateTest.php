<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_change_password_updates_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson('/api/account/change-password', [
                'current_password' => 'old-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson('/api/account/change-password', [
                'current_password' => 'totally-wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_change_password_revokes_other_sessions(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        $currentToken = $user->createToken('current-session')->plainTextToken;
        $otherToken = $user->createToken('other-device')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $currentToken])
            ->putJson('/api/account/change-password', [
                'current_password' => 'old-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])->assertOk();

        $otherTokenId = explode('|', $otherToken)[0];
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherTokenId]);

        \Illuminate\Support\Facades\Auth::forgetGuards();

        // The current token was stamped with the pre-increment token_version at
        // mint time, so after the password change bumps token_version it should
        // also fail on the *next* request — the client that just changed the
        // password must re-authenticate too.
        $this->withHeaders(['Authorization' => 'Bearer ' . $currentToken])
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_change_name_updates_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->withHeaders($this->authHeader($user))
            ->putJson('/api/account/change-name', ['name' => 'New Name'])
            ->assertOk();

        $this->assertEquals('New Name', $user->fresh()->name);
    }

    public function test_update_profile_uploads_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/account/profile', [
                'name' => 'Updated Name',
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $response->assertOk()->assertJsonStructure(['message', 'user']);

        $fresh = $user->fresh();
        $this->assertEquals('Updated Name', $fresh->name);
        $this->assertNotNull($fresh->avatar);
        Storage::disk('public')->assertExists($fresh->avatar);
    }
}
