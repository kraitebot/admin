<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_already_authenticated_admins_visiting_login_land_on_the_console(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('login'));

        $response->assertRedirect(route('system.dashboard', absolute: false));
    }

    public function test_already_authenticated_users_visiting_login_land_on_the_dashboard(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
