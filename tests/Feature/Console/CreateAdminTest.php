<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_new_admin_user_when_email_does_not_exist(): void
    {
        $this->artisan('admin:create', [
            'email' => 'newadmin@example.com',
            'password' => 'supersecret123',
            'name' => 'Root Admin',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@example.com',
            'name' => 'Root Admin',
            'is_admin' => true,
            'package_type' => 'premium',
        ]);

        $user = User::where('email', 'newadmin@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('supersecret123', $user->password));
    }

    public function test_uses_default_name_when_not_provided(): void
    {
        $this->artisan('admin:create', [
            'email' => 'defaultname@example.com',
            'password' => 'supersecret123',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'defaultname@example.com',
            'name' => 'Admin',
        ]);
    }

    public function test_promotes_existing_user_to_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'already-exists@example.com',
            'is_admin' => false,
            'password' => Hash::make('original-password'),
        ]);

        $this->artisan('admin:create', [
            'email' => 'already-exists@example.com',
            'password' => 'ignored-password',
        ])->assertExitCode(0);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_admin);
        // Promoting an existing user must not clobber their existing password.
        $this->assertTrue(Hash::check('original-password', $fresh->password));
    }
}
