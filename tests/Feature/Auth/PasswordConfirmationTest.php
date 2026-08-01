<?php

declare(strict_types=1);

use App\Models\User;

test('password can be confirmed', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

test('confirmed sysadmin passwords fall back to the system dashboard', function (): void {
    $admin = User::factory()->create([
        'email' => 'confirmed-password-admin@kraite.test',
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('system.dashboard', absolute: false))
        ->assertSessionHasNoErrors();
});

test('password is not confirmed with an invalid password', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors();
});
