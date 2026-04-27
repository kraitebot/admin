<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Throwable;

class AccountController extends Controller
{
    /**
     * Editable column allowlist. Mirrors the validation rules in
     * UpdateAccountRequest — the controller never accepts arbitrary input
     * straight off the request, so adding a new column means updating
     * BOTH this list and the FormRequest rules.
     *
     * @var array<int, string>
     */
    private const EDITABLE_FIELDS = [
        'name', 'portfolio_quote', 'trading_quote',
        'can_trade',
        'profit_percentage', 'stop_market_initial_percentage',
        'total_positions_long', 'total_positions_short',
        'position_leverage_long', 'position_leverage_short',
        'margin_percentage_long', 'margin_percentage_short',
    ];

    public function edit(): View
    {
        $isAdmin = (bool) Auth::user()->is_admin;

        $query = Account::with(['apiSystem', 'user']);
        if (! $isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $accounts = $query
            ->orderBy('user_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => $this->serialize($a))
            ->all();

        return view('accounts.edit', [
            'accounts' => $accounts,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Live list of quote assets the operator actually holds on the chosen
     * account's exchange. Drives the Portfolio / Trading quote dropdowns
     * — only currencies with a non-zero wallet balance show up, so the
     * form can never accept a quote the account can't pay margin in.
     *
     * Cached 60s per account to absorb dropdown-bouncing without hammering
     * the exchange API. Falls back to an empty list on API failure (the
     * dropdown then renders empty + the form refuses to submit).
     */
    public function quotes(Request $request): JsonResponse
    {
        $request->validate(['account_id' => ['required', 'integer']]);

        $query = Account::where('id', $request->input('account_id'));
        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }
        $account = $query->firstOrFail();

        $assets = Cache::remember(
            "account.{$account->id}.available-quotes",
            60,
            fn () => $this->fetchAvailableAssets($account),
        );

        return response()->json([
            'account_id' => $account->id,
            'assets' => $assets,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function fetchAvailableAssets(Account $account): array
    {
        try {
            $resp = $account->apiQueryBalance();

            // Binance returns a flat array of {asset, balance, ...} records.
            // The mapper resolves to the aggregated USDT figure for app
            // logic; for the operator-facing quote picker we read the raw
            // body directly to surface every asset with a non-zero wallet.
            $body = (string) ($resp->response?->getBody() ?? '');
            $rows = $body !== '' ? json_decode($body, true) : null;

            if (! is_array($rows)) {
                return [];
            }

            return collect($rows)
                ->filter(fn ($row) => is_array($row)
                    && isset($row['asset'], $row['balance'])
                    && is_numeric($row['balance'])
                    && (float) $row['balance'] > 0)
                ->pluck('asset')
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        $query = Account::where('id', $request->input('account_id'));
        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }
        $account = $query->firstOrFail();

        $account->update($request->only(self::EDITABLE_FIELDS));

        return back()
            ->with('status', 'Account updated.')
            ->with('updated_account_id', $account->id);
    }

    /**
     * Flatten an Account into the shape the edit form expects — identity
     * fields the dropdown uses + every editable column in EDITABLE_FIELDS.
     *
     * @return array<string, mixed>
     */
    private function serialize(Account $account): array
    {
        $base = [
            'id' => $account->id,
            'exchange' => $account->apiSystem?->name ?? 'Unknown',
            'exchange_canonical' => $account->apiSystem?->canonical,
            'owner' => $account->user?->name ?? 'Unknown',
        ];

        foreach (self::EDITABLE_FIELDS as $field) {
            $base[$field] = $account->{$field};
        }

        return $base;
    }
}
