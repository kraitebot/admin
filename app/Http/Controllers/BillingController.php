<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User as KraiteUser;
use Kraite\Core\Models\WalletTransaction;

/**
 * User-facing billing area.
 *
 * Self-service surface for the authenticated user. Top-up itself is a
 * mock button until the NOWPayments integration ships — it's a hook
 * point only, no actual gateway call.
 */
final class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->kraiteUser($request);

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('billing', [
            'user' => $user,
            'tier' => $user->subscription,
            'subscriptions' => Subscription::where('is_active', true)->orderBy('id')->get(),
            'transactions' => $transactions,
            'runwayDays' => $user->walletRunwayDays(),
            'trialActive' => $user->isTrialActive(),
            'inClosingMode' => $user->isInClosingMode(),
        ]);
    }

    public function startTrading(Request $request): RedirectResponse
    {
        $user = $this->kraiteUser($request);

        if ($user->trial_started_at === null) {
            $user->trial_started_at = now();
            $user->save();
        }

        return redirect()
            ->route('billing')
            ->with('status', 'Trial started. Enjoy your free days!');
    }

    public function changeSubscription(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
        ]);

        $user = $this->kraiteUser($request);

        $user->subscription_id = (int) $data['subscription_id'];
        $user->save();

        return redirect()
            ->route('billing')
            ->with('status', 'Plan updated.');
    }

    private function kraiteUser(Request $request): KraiteUser
    {
        return KraiteUser::with('subscription')->findOrFail($request->user()->id);
    }
}
