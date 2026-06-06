<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Surface domains
|--------------------------------------------------------------------------
|
| One Laravel project serves two surfaces split by host:
|
|  - admin   → the trader (client) product: dashboard, positions,
|              projections, BSCS, accounts, billing.
|  - console → the sysadmin product: system dashboard, users, commands,
|              step dispatcher, backtesting, billing management, SQL.
|
| Both share the UI component library, the Vite bundle, and the user
| pool (`is_admin` gates the console). Route groups bind to these hosts
| in routes/web.php and routes/console-web.php.
|
*/

return [
    'admin' => env('ADMIN_DOMAIN', 'admin.kraite.test'),
    'console' => env('CONSOLE_DOMAIN', 'console.kraite.test'),
];
