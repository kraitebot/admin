<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    prepareCoreBillingSchema();
});

it('sends sysadmins away from trader pages and rejects trader mutations', function (): void {
    $admin = User::factory()->create([
        'name' => 'Boundary Admin',
        'email' => 'boundary-admin@kraite.test',
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->get('https://admin.kraite.test/profile')
        ->assertRedirect(route('system.dashboard'));

    $this->getJson('https://admin.kraite.test/profile')
        ->assertForbidden();

    $this->patch('https://admin.kraite.test/profile', [
        'name' => 'Mutated Admin',
        'email' => $admin->email,
    ])->assertForbidden();

    expect($admin->fresh()->name)->toBe('Boundary Admin');
});

it('keeps trader pages available to traders without requiring an account', function (): void {
    $trader = User::factory()->create([
        'email' => 'boundary-trader@kraite.test',
        'is_admin' => false,
    ]);

    $this->actingAs($trader)
        ->get('https://admin.kraite.test/profile')
        ->assertSuccessful();
});

it('places every authenticated trader web route behind the trader boundary', function (): void {
    $missingTraderBoundary = collect(Route::getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getDomain() === config('domains.admin'))
        ->filter(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route): bool => in_array('admin', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route): bool => in_array('trader', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($missingTraderBoundary)->toBe([]);
});

it('places every authenticated mobile route behind the trader boundary', function (): void {
    $missingTraderBoundary = collect(Route::getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getDomain() === config('domains.api'))
        ->filter(fn (RoutingRoute $route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route): bool => in_array('trader', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => $route->uri())
        ->values()
        ->all();

    expect($missingTraderBoundary)->toBe([]);
});
