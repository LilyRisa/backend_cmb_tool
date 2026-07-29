<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_reach_dashboard(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertStatus(403);
    }

    public function test_admin_login_with_valid_credentials_redirects_to_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => bcrypt('adminpass123')]);

        $response = $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'adminpass123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_rejects_non_admin_user(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'password' => bcrypt('userpass123')]);

        $response = $this->post(route('admin.login.submit'), [
            'email' => $user->email,
            'password' => 'userpass123',
        ]);

        $response->assertRedirect();
        $this->assertGuest();
    }

    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(3)->create(['is_admin' => false]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('totalUsers', 4);
    }
}
