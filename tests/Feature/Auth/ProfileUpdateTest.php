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

        $this->withHeaders($this->authHeader($user))
            ->putJson('/api/account/change-password', [
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
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
