<?php

declare(strict_types=1);

use Kraite\Core\Models\ModelLog;
use Kraite\Core\Models\User;

ModelLog::disable();

User::withoutEvents(function (): void {
    User::updateOrCreate(
        ['email' => 'browser.registration@kraite.test'],
        [
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Browser Registration',
            'email_verified_at' => now(),
            'password' => 'temporary-password',
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
