<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('redirects guests to login without sending mail', function (): void {
    $target = User::factory()->create([
        'email' => 'target+guest-blocked-test@kraite.com',
    ]);

    $response = $this->post(route('system.users.password-reset', $target));

    $response->assertRedirect(route('login'));

    Notification::assertNothingSent();
});
