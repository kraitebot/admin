<?php

declare(strict_types=1);

use Kraite\Core\Models\User;

User::withoutEvents(function (): void {
    User::updateOrCreate(
        ['email' => 'browser.rail@kraite.test'],
        [
            'uuid' => '44444444-4444-4444-8444-444444444444',
            'name' => 'Browser Rail',
            'email_verified_at' => now(),
            'password' => 'rail-password',
            'status' => 'confirmed',
            'is_active' => true,
            'can_trade' => false,
            'is_admin' => false,
            'subscription_id' => null,
            'active_account_id' => null,
            'notification_channels' => ['mail'],
        ],
    );
});
