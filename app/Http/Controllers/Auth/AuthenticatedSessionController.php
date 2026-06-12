<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        $devUsers = collect();

        if (app()->isLocal()) {
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

        return view('auth.login', [
            'devUsers' => $devUsers,
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

        $request->session()->regenerate();

        $target = $request->user()?->is_admin
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
}
