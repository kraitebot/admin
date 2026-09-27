<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
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
            if ($this->shouldRedirectToSystem($user, $request)) {
                return redirect()->route('system.dashboard');
            }

            abort(403, 'Trader access required.');
        }

        return $next($request);
    }

    private function shouldRedirectToSystem(?Authenticatable $user, Request $request): bool
    {
        return $user !== null && $request->isMethodSafe() && ! $request->expectsJson();
    }
}
