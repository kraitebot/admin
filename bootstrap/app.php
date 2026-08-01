<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        // Already-authenticated arrivals (session/remember cookie hitting `/`
        // or /login) follow the same role-aware landing as a fresh form login
        // (AuthenticatedSessionController::store): sysadmins land on the
        // console, everyone else on the trader dashboard. Without this the
        // framework default sends every authenticated user to `dashboard`.
        $middleware->redirectUsersTo(fn ($request) => $request->user()?->is_admin
            ? route('system.dashboard', absolute: false)
            : route('dashboard', absolute: false));

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'ability' => CheckAbilities::class,
        ]);

        $middleware->preventRequestForgery(except: [
            'webhooks/payments',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form left open past the session lifetime posts a dead CSRF token.
        // Instead of the bare 419 wall, bounce back to the page it came from
        // with the harmless input restored, so the visitor just re-submits.
        // The framework has already mapped TokenMismatchException to a 419
        // HttpException by the time render callbacks run, so match on that.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Reload the page and try again.',
                ], 419);
            }

            // The bounce target comes from the Referer header, which a
            // cross-site form post controls — only ever return the visitor
            // to one of our own pages, never to whatever sent them here.
            $fallback = route('login');
            $previous = url()->previous($fallback);

            if (parse_url($previous, PHP_URL_HOST) !== $request->getHost()) {
                $previous = $fallback;
            }

            return redirect()
                ->to($previous)
                ->withInput($request->except('password', 'password_confirmation', '_token'))
                ->with('status', 'Your session expired for security. Please try again.');
        });
    })->create();
