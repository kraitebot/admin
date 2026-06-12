<?php

declare(strict_types=1);

use App\Models\User;

/*
 * The sysadmin console is no longer a separate host — it lives on the admin
 * host under the `/system` prefix, gated by `auth` + `admin`. Surface and
 * access follow the user's role, not the host.
 */

it('redirects guests from the sysadmin console to login', function (): void {
    $this->get('https://admin.kraite.test/system/dashboard')
        ->assertRedirect();
});

it('forbids non-admin users from the sysadmin console', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertForbidden();
});

it('lets admins reach the sysadmin console on the admin host', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertSuccessful();
});

it('sends sysadmin logins to the system dashboard', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://admin.kraite.test/login', [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://admin.kraite.test/system/dashboard');
});

it('sends trader logins to the trader dashboard', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://admin.kraite.test/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://admin.kraite.test/dashboard');
});
