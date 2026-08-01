<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The last step of signing up: kraite.com finishes the wizard, mints a
 * one-time token, and sends the brand-new trader here. If this link fails
 * they land on a login form seconds after creating an account, which is the
 * worst possible first impression — so the happy path and every way the link
 * can be abused are pinned.
 */
beforeEach(function (): void {
    prepareCoreBillingSchema();
});

function handoffUrl(string $token): string
{
    return 'https://admin.kraite.test/register-handoff/'.$token;
}

it('signs the new trader straight into their dashboard', function (): void {
    $token = str_repeat('a', 64);
    $user = User::factory()->create([
        'status' => 'active',
        'remember_token' => hash('sha256', $token),
    ]);

    $this->get(handoffUrl($token))
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionHas('registration_welcome', true);

    $this->assertAuthenticated();

    expect(auth()->id())->toBe($user->id);
});

it('burns the link after one use', function (): void {
    $token = str_repeat('b', 64);
    $user = User::factory()->create([
        'status' => 'active',
        'remember_token' => hash('sha256', $token),
    ]);

    $this->get(handoffUrl($token))->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->remember_token)->toBeNull();

    $this->flushSession();
    Auth::logout();

    $this->get(handoffUrl($token))->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
});

it('refuses a token that belongs to no one', function (): void {
    $this->get(handoffUrl(str_repeat('c', 64)))->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
});

it('refuses to sign in an account that was never activated', function (): void {
    $token = str_repeat('d', 64);
    User::factory()->create([
        'status' => 'draft',
        'remember_token' => hash('sha256', $token),
    ]);

    $this->get(handoffUrl($token))->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
});

it('refuses to turn a sysadmin handoff token into a trader session', function (): void {
    $token = str_repeat('e', 64);
    $admin = User::factory()->create([
        'email' => 'handoff-admin@kraite.test',
        'is_admin' => true,
        'status' => 'active',
        'remember_token' => hash('sha256', $token),
    ]);

    $this->get(handoffUrl($token))
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
    expect($admin->fresh()->remember_token)->toBe(hash('sha256', $token));
});

it('keeps an authenticated sysadmin on the system surface when a trader handoff opens', function (): void {
    $token = str_repeat('f', 64);
    $admin = User::factory()->create([
        'email' => 'handoff-session-admin@kraite.test',
        'is_admin' => true,
    ]);
    $trader = User::factory()->create([
        'email' => 'handoff-session-trader@kraite.test',
        'is_admin' => false,
        'status' => 'active',
        'remember_token' => hash('sha256', $token),
    ]);

    $this->actingAs($admin)
        ->get(handoffUrl($token))
        ->assertRedirect(route('system.dashboard'));

    $this->assertAuthenticatedAs($admin);
    expect($trader->fresh()->remember_token)->toBe(hash('sha256', $token));
});
