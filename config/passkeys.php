<?php

declare(strict_types=1);

return [
    'relying_party_id' => config('domains.api'),
    'allowed_origins' => [
        'https://'.config('domains.api'),
    ],
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
    'timeout' => 60_000,
    'guard' => 'web',
    'middleware' => ['web'],
    'management_middleware' => ['password.confirm'],
    'throttle' => 'throttle:6,1',
    'redirect' => '/',
];
