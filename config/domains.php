<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Surface domains
|--------------------------------------------------------------------------
|
| One Laravel project serves three surfaces split by route group:
|
|  - trader  → the client product: dashboard, positions, projections,
|              BSCS, accounts, billing (routes/web.php).
|  - sysadmin → `/system/*`: system dashboard, users, commands, step
|              dispatcher, backtesting, billing management, SQL
|              (routes/console-web.php, `is_admin`-gated).
|  - mobile API → `api.kraite.com/v1/*`: first-party, read-only trader
|                 endpoints authenticated with scoped Sanctum tokens.
|
| The standalone console.kraite.com app was retired and wiped on
| 2026-07-09 (deploy-notes Entry 97) — the sysadmin surface lives here.
|
*/

return [
    'admin' => env('ADMIN_DOMAIN', 'admin.kraite.test'),
    'api' => env('API_DOMAIN', 'api.kraite.com'),
];
