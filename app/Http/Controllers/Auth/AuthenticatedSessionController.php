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
    /**
     * Display the login view.
     *
     * The login screen surfaces every seeded user with an account-membership
     * subtitle so the operator can click an entry to autofill credentials.
     * No dev/host gate yet — this is the only environment.
     */
    public function create(): View
    {
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

        return view('auth.login', [
            'devUsers' => $devUsers,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * Operators without any account membership land on the system overview;
     * everyone else lands on the trader-facing dashboard. The user-facing
     * /dashboard surface will be redesigned later — until then it stays
     * the rich admin layout for the trader identity.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $userId = (int) Auth::id();
        $hasAccounts = DB::table('accounts')->where('user_id', $userId)->exists();

        $target = $hasAccounts
            ? route('dashboard', absolute: false)
            : route('system.dashboard', absolute: false);

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
