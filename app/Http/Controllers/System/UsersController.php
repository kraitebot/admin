<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\Wallet;

/**
 * Admin user management.
 *
 * The admin lists every Kraite user, sees their billing state, and can
 * apply manual credit / debit adjustments through the wallet ledger.
 * Used to seed test users on launch and for one-off corrections.
 */
final class UsersController extends Controller
{
    public function index(?User $user = null): View
    {
        $users = User::with('subscription')
            ->orderBy('email')
            ->get();

        $selected = $user;
        $transactions = collect();

        if ($selected) {
            $selected->load('subscription', 'accounts.apiSystem');

            $transactions = WalletTransaction::where('user_id', $selected->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        return view('system.users.index', [
            'users' => $users,
            'selected' => $selected,
            'subscriptions' => Subscription::where('is_active', true)->orderBy('id')->get(),
            'transactions' => $transactions,
        ]);
    }

    public function adjustCredit(Request $request, User $user, Wallet $wallet): RedirectResponse
    {
        $data = $request->validate([
            'amount_usdt' => 'required|numeric|not_in:0',
            'description' => 'required|string|max:255',
        ]);

        $amount = (float) $data['amount_usdt'];
        $admin = $request->user();

        $meta = [
            'admin_user_id' => $admin?->id,
            'admin_email' => $admin?->email,
        ];

        if ($amount > 0) {
            $wallet->credit(
                user: $user,
                amount: $amount,
                type: WalletTransaction::TYPE_CREDIT_ADMIN,
                description: $data['description'],
                meta: $meta,
            );
        } else {
            $wallet->debit(
                user: $user,
                amount: abs($amount),
                type: WalletTransaction::TYPE_DEBIT_ADMIN,
                description: $data['description'],
                meta: $meta,
            );
        }

        return redirect()
            ->route('system.users', $user)
            ->with('status', 'Wallet adjusted.');
    }

    public function changeSubscription(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
        ]);

        $user->subscription_id = (int) $data['subscription_id'];
        $user->save();

        return redirect()
            ->route('system.users', $user)
            ->with('status', 'Subscription updated.');
    }

    public function changeActiveAccount(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'active_account_id' => 'nullable|integer|exists:accounts,id',
        ]);

        $accountId = $data['active_account_id'] ?? null;

        if ($accountId !== null) {
            $belongsToUser = $user->accounts()->where('id', $accountId)->exists();

            if (! $belongsToUser) {
                return redirect()
                    ->route('system.users', $user)
                    ->with('error', 'That account does not belong to this user.');
            }
        }

        $user->active_account_id = $accountId !== null ? (int) $accountId : null;
        $user->save();

        return redirect()
            ->route('system.users', $user)
            ->with('status', 'Active account updated.');
    }

    public function startTrial(User $user): RedirectResponse
    {
        if ($user->trial_started_at !== null) {
            return redirect()
                ->route('system.users', $user)
                ->with('status', 'Trial already started for this user.');
        }

        $user->trial_started_at = now();
        $user->save();

        return redirect()
            ->route('system.users', $user)
            ->with('status', 'Trial started.');
    }

    public function changeTrialDays(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'trial_days_override' => 'nullable|integer|min:0|max:365',
        ]);

        $user->trial_days_override = $data['trial_days_override'] ?? null;
        $user->save();

        $msg = $user->trial_days_override === null
            ? 'Trial duration reset to tier default.'
            : "Trial duration overridden to {$user->trial_days_override} days.";

        return redirect()
            ->route('system.users', $user)
            ->with('status', $msg);
    }
}
