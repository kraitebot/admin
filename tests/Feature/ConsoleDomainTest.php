<?php

declare(strict_types=1);

use App\Models\User;

it('redirects guests on the console domain to login', function (): void {
    $this->get('https://console.kraite.test/dashboard')
        ->assertRedirect();
});

it('forbids non-admin users on the console domain', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://console.kraite.test/dashboard')
        ->assertForbidden();
});

it('lets admins reach the console dashboard', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('https://console.kraite.test/dashboard')
        ->assertSuccessful();
});

it('keeps the trader dashboard off the console domain', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    // /projections only exists on the admin domain — the console host
    // must not serve trader pages.
    $this->actingAs($admin)
        ->get('https://console.kraite.test/projections')
        ->assertNotFound();
});

it('keeps console pages off the admin domain', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    // The old /system/* paths are gone from the admin domain; the
    // console surface owns them now (without the prefix).
    $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertNotFound();
});

it('sends console logins to the system dashboard', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://console.kraite.test/login', [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://console.kraite.test/dashboard');
});

it('sends admin-domain logins to the trader dashboard', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
        'password' => bcrypt('secret-password'),
    ]);

    $this->post('https://admin.kraite.test/login', [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect('https://admin.kraite.test/dashboard');
});
