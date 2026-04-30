<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Load the shared kraite env file BEFORE Laravel boots config. If
// this runs inside a service provider's register() instead, the
// values are not visible to config/*.php (config is read earlier).
$kraiteEnv = '/home/waygou/.env.kraite';
if (is_readable($kraiteEnv)) {
    Dotenv::createImmutable(dirname($kraiteEnv), basename($kraiteEnv))->safeLoad();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/payments',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
