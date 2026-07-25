<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        $devUsers = collect();
        $devQuickPickPassword = null;

        if (app()->isLocal()) {
            $configuredPassword = config('auth.local_quick_pick_password');

            if (is_string($configuredPassword) && $configuredPassword !== '') {
                $devQuickPickPassword = $configuredPassword;
                $users = DB::table('users')
                    ->orderByDesc('is_admin')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'is_admin']);

                $accountsByUser = DB::table('accounts')
                    ->join('api_systems', 'api_systems.id', '=', 'accounts.api_system_id')
                    ->select('accounts.user_id', 'accounts.name as account_name', 'api_systems.name as exchange')
                    ->orderBy('api_systems.name')
                    ->get()
                    ->groupBy('user_id');

                $devUsers = $users->map(function ($user) use ($accountsByUser) {
                    $accounts = $accountsByUser->get($user->id, collect());

                    $subtitle = match (true) {
                        $accounts->isEmpty() && (bool) $user->is_admin => 'Sysadmin',
                        $accounts->isEmpty() => 'No accounts',
                        default => $accounts->map(fn ($a) => "{$a->exchange} · {$a->account_name}")->implode(' · '),
                    };

                    return [
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_admin' => (bool) $user->is_admin,
                        'subtitle' => $subtitle,
                    ];
                });
            }
        }

        return view('auth.login', [
            'devUsers' => $devUsers,
            'devQuickPickPassword' => $devQuickPickPassword,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * The landing target follows the user's ROLE: sysadmins land on the
     * system overview (their default surface), everyone else on the trader
     * dashboard. Sysadmins can switch to the trader surface any time via the
     * top-bar toggle; the `admin` middleware still 403s non-admins on any
     * `system.*` route they reach directly.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $isAdmin = (bool) $request->user()?->is_admin;

        // The page that triggered the login belonged to whoever was here
        // before. A sysadmin session that expired on a `/system/*` page
        // leaves that page waiting, and sending the next person there just
        // walls them with a 403 — so a non-admin drops it and lands on their
        // own dashboard instead.
        if (! $isAdmin && $this->intendedRequiresAdmin($request)) {
            $request->session()->forget('url.intended');
        }

        $request->session()->regenerate();

        $target = $isAdmin
            ? route('system.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($target);
    }

    /**
     * Whether the page waiting behind this login is one only a sysadmin may
     * open. Resolved from the route's own middleware so a future admin-only
     * route outside `/system` is covered too; the prefix is the fallback for
     * an intended URL the router cannot match.
     */
    private function intendedRequiresAdmin(Request $request): bool
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || $intended === '') {
            return false;
        }

        // Matched without binding the route: this is a read-only question
        // asked mid-request, and binding would hand the shared route objects
        // the parameters of a fabricated request.
        $probe = Request::create($intended);
        $match = collect(Route::getRoutes())->first(fn (RoutingRoute $route): bool => $route->matches($probe));

        return $match !== null
            ? in_array('admin', $match->gatherMiddleware(), true)
            : str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/system');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
