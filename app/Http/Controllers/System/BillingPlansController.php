<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Kraite\Core\Models\Subscription;

/**
 * Sysadmin plan-management surface. Live-tunable subscription tiers
 * (rate, trial, caps, name, canonical, active flag) so a Christmas
 * promo or a new tier ships without a deploy.
 *
 * Mounted under /system/billing/plans; the existing user-billing
 * views at /system/users sit as a sibling tab.
 */
final class BillingPlansController extends Controller
{
    public function index(): View
    {
        return view('system.billing.plans', [
            'subscriptions' => Subscription::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, isUpdate: false);

        Subscription::create($data);

        return redirect()
            ->route('system.billing.plans')
            ->with('status', 'Plan created.');
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $this->validateData($request, isUpdate: true, ignoreId: $subscription->id);

        $subscription->update($data);

        return redirect()
            ->route('system.billing.plans')
            ->with('status', 'Plan updated.');
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        if ($subscription->users()->exists()) {
            return redirect()
                ->route('system.billing.plans')
                ->with('error', 'Cannot delete a plan that still has users on it. Move them off first.');
        }

        $subscription->delete();

        return redirect()
            ->route('system.billing.plans')
            ->with('status', 'Plan deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $isUpdate, ?int $ignoreId = null): array
    {
        $canonicalRule = 'required|string|max:64|alpha_dash|unique:subscriptions,canonical';
        if ($isUpdate && $ignoreId !== null) {
            $canonicalRule .= ','.$ignoreId;
        }

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'canonical' => $canonicalRule,
            'description' => 'nullable|string|max:1000',
            'monthly_rate_usdt' => 'required|numeric|min:0',
            'trial_days' => 'required|integer|min:0',
            'max_accounts' => 'nullable|integer|min:1',
            'max_balance' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $data['canonical'] = Str::lower($data['canonical']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
