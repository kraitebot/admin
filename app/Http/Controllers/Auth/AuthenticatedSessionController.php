<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use stdClass;

final class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        $configuredPassword = app()->isLocal() ? config('auth.local_quick_pick_password') : null;
        $devQuickPickPassword = is_string($configuredPassword) && $configuredPassword !== ''
            ? $configuredPassword
            : null;

        return view('auth.login', [
            'devUsers' => $devQuickPickPassword === null ? collect() : $this->quickPickUsers(),
            'devQuickPickPassword' => $devQuickPickPassword,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * The landing target follows the user's role. Intended destinations from
     * another role are discarded before redirecting.
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
        if ($this->intendedRequiresDifferentRole($request, $isAdmin)) {
            $request->session()->forget('url.intended');
        }

        $request->session()->regenerate();

        $target = $isAdmin
            ? route('system.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($target);
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

    /** @return Collection<int, array{name: string, email: string, is_admin: bool, subtitle: string}> */
    private function quickPickUsers(): Collection
    {
        $accountsByUser = $this->quickPickAccountsByUser();

        return DB::table('users')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_admin'])
            ->map(function (stdClass $user) use ($accountsByUser): array {
                $accounts = $accountsByUser->get($user->id, collect());
                $subtitle = match (true) {
                    $accounts->isEmpty() && (bool) $user->is_admin => 'Sysadmin',
                    $accounts->isEmpty() => 'No accounts',
                    default => $accounts->map(fn (stdClass $account): string => "{$account->exchange} · {$account->account_name}")->implode(' · '),
                };

                return [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'subtitle' => $subtitle,
                ];
            });
    }

    /** @return Collection<int|string, Collection<int, stdClass>> */
    private function quickPickAccountsByUser(): Collection
    {
        return DB::table('accounts')
            ->join('api_systems', 'api_systems.id', '=', 'accounts.api_system_id')
            ->select('accounts.user_id', 'accounts.name as account_name', 'api_systems.name as exchange')
            ->orderBy('api_systems.name')
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Whether the page waiting behind this login belongs to the other role.
     * Resolved from route middleware so future role-specific routes are
     * covered without maintaining a path list.
     */
    private function intendedRequiresDifferentRole(Request $request, bool $isAdmin): bool
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

        if ($match !== null) {
            $incompatibleMiddleware = $isAdmin ? 'trader' : 'admin';

            return in_array($incompatibleMiddleware, $match->gatherMiddleware(), true);
        }

        return $this->fallbackRequiresDifferentRole($intended, $isAdmin);
    }

    private function fallbackRequiresDifferentRole(string $intended, bool $isAdmin): bool
    {
        return ! $isAdmin
            && str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/system');
    }
}
