<?php

return [
    'prefix' => 'hub-ui',

    'app' => [
        'name' => env('APP_NAME', 'Laravel'),
        'logo' => null,
        'dashboard_route' => 'dashboard',
    ],

    'theme' => [
        'default_mode' => 'dark',

        'colors' => [
            'primary'   => '#22c55e',
            'secondary' => '#6366f1',
            'success'   => '#22c55e',
            'warning'   => '#f59e0b',
            'danger'    => '#ef4444',
            'info'      => '#3b82f6',
        ],
    ],

    'layout' => [
        'fonts' => [
            'body' => 'Inter',
            'heading' => 'Space Grotesk',
            'mono' => 'JetBrains Mono',
        ],
    ],

    'sidebar' => [
        'width' => 'w-28',
        'persistence' => true,
    ],

    'features' => [
        'toast' => true,
        'confirmation' => true,
        'navigate_fade' => true,
    ],
];
