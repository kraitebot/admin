<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    prepareCoreBillingSchema();
});

it('autofills every local quick-pick with the configured clone password', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['auth.local_quick_pick_password' => 'Local"Clone&Pass1!']);

    $admin = User::factory()->create([
        'name' => 'Local Admin',
        'email' => 'local-admin@kraite.test',
        'is_admin' => true,
    ]);
    $trader = User::factory()->create([
        'name' => 'Local Trader',
        'email' => 'local-trader@kraite.test',
        'is_admin' => false,
    ]);

    $response = $this->get(route('login'))
        ->assertOk()
        ->assertSee($admin->email)
        ->assertSee($trader->email)
        ->assertSee('data-quick-pick-password="Local&quot;Clone&amp;Pass1!"', false)
        ->assertSee('dataset.quickPickPassword', false)
        ->assertDontSee("value = 'password'", false);

    expect(substr_count($response->getContent(), 'data-quick-pick-user'))
        ->toBe(2);
});

it('never exposes the local quick-pick password outside the local environment', function (): void {
    config(['auth.local_quick_pick_password' => 'MustNeverReachProduction1!']);
    User::factory()->create(['email' => 'production-user@kraite.test']);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Dev quick-pick')
        ->assertDontSee('MustNeverReachProduction1!', false)
        ->assertDontSee('data-quick-pick-user', false);
});
