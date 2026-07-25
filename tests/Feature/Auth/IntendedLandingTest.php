<?php

declare(strict_types=1);

use App\Models\User;

/**
 * A sysadmin session that expires on a `/system/*` page leaves that page
 * waiting as the login's intended target. Whoever signs in next inherits it —
 * and a trader inheriting a sysadmin page used to land on the bare
 * "403 ADMIN ACCESS REQUIRED" wall seconds after a successful login.
 */
beforeEach(function (): void {
    prepareCoreBillingSchema();
});

it('lands a trader on their own dashboard when a sysadmin page was waiting', function (): void {
    $trader = User::factory()->create(['is_admin' => false]);

    $this->withSession(['url.intended' => 'https://admin.kraite.test/system/positions'])
        ->post('https://admin.kraite.test/login', [
            'email' => $trader->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

it('still takes a sysadmin to the page that was waiting', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->withSession(['url.intended' => 'https://admin.kraite.test/system/positions'])
        ->post('https://admin.kraite.test/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])
        ->assertRedirect('https://admin.kraite.test/system/positions');
});

it('keeps honouring a waiting page a trader is allowed to open', function (): void {
    $trader = User::factory()->create(['is_admin' => false]);

    $this->withSession(['url.intended' => 'https://admin.kraite.test/projections'])
        ->post('https://admin.kraite.test/login', [
            'email' => $trader->email,
            'password' => 'password',
        ])
        ->assertRedirect('https://admin.kraite.test/projections');
});

it('drops an unroutable sysadmin path rather than walling the trader', function (): void {
    $trader = User::factory()->create(['is_admin' => false]);

    $this->withSession(['url.intended' => 'https://admin.kraite.test/system/does-not-exist'])
        ->post('https://admin.kraite.test/login', [
            'email' => $trader->email,
            'password' => 'password',
        ])
        ->assertRedirect(route('dashboard', absolute: false));
});
