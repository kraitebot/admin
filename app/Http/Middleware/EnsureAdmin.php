<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAdmin
 *
 * Gate for routes that must be restricted to admin users only. Reads the
 * `is_admin` flag on the authenticated user. Anything that rejects (no
 * user, or is_admin=false) returns 403 so unauthenticated clients never
 * get redirected to login and then silently bounced; the failure is
 * explicit. For in-repo uses the parent group should still include
 * `auth` so the login redirect happens first — this middleware is a
 * strict admin check on top of authentication.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! (bool) ($user->is_admin ?? false)) {
            abort(403, 'Admin access required.');
        }

        return $next($request);
    }
}
