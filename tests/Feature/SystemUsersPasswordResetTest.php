<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAdmin;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('lets an admin dispatch a password-reset email to a beta user', function (): void {
    // EnsureAdmin reads `is_admin` from the kraitebot/core users-table
    // extension which isn't migrated in admin's sqlite test schema.
    // Bypassing the gate here keeps the test focused on the new
    // controller action (the gate itself is not the unit under test).
    $this->withoutMiddleware(EnsureAdmin::class);

    $admin = User::factory()->create([
        'email' => 'admin+beta-reset-test@kraite.com',
    ]);

    $betaUser = User::factory()->create([
        'email' => 'beta+pending-test@kraite.com',
    ]);

    $response = $this
        ->actingAs($admin)
        ->post(route('system.users.password-reset', $betaUser));

    $response
        ->assertRedirect(route('system.users', $betaUser))
        ->assertSessionHas('status', "Password reset link sent to {$betaUser->email}.");

    Notification::assertSentTo($betaUser, ResetPasswordNotification::class);
    Notification::assertNotSentTo($admin, ResetPasswordNotification::class);
});

it('redirects guests to login without sending mail', function (): void {
    $target = User::factory()->create([
        'email' => 'target+guest-blocked-test@kraite.com',
    ]);

    $response = $this->post(route('system.users.password-reset', $target));

    $response->assertRedirect(route('login'));

    Notification::assertNothingSent();
});
