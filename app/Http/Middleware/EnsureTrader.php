<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTrader
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (bool) ($user->is_admin ?? false)) {
            if ($user && $request->isMethodSafe() && ! $request->expectsJson()) {
                return redirect()->route('system.dashboard');
            }

            abort(403, 'Trader access required.');
        }

        return $next($request);
    }
}
