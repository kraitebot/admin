<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\Account;

/**
 * Validation + ownership gate for the edit-account form. Sysadmin can hit
 * any account; everyone else is scoped to their own — same 404 surface
 * either way.
 *
 * The numeric `in:` lists are deliberate — the operator picks from a
 * curated set of values rather than free-typing arbitrary numbers, which
 * keeps risk-relevant settings (leverage, margin %, slot count) inside
 * a tested envelope.
 */
final class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $query = Account::where('id', $this->input('account_id'));

        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }

        return $query->exists();
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],

            'name' => ['required', 'string', 'max:255'],
            // Quote currencies are validated against the live asset list
            // the controller surfaces via /accounts/edit/quotes; we keep
            // the field rule loose here (string + max length) and trust
            // the upstream API as the source of truth — locking a static
            // list would block any new asset Binance adds (e.g. BFUSD
            // expansion to other regions).
            'portfolio_quote' => ['required', 'string', 'max:20'],
            'trading_quote'   => ['required', 'string', 'max:20'],

            'can_trade' => ['boolean'],

            'profit_percentage'              => ['required', 'numeric', Rule::in(['0.360', '0.380', '0.400'])],
            'stop_market_initial_percentage' => ['required', 'numeric', Rule::in(['2.50', '5.00', '7.50'])],

            'total_positions_long'  => ['required', 'integer', Rule::in([4, 5, 6])],
            'total_positions_short' => ['required', 'integer', Rule::in([4, 5, 6])],

            'position_leverage_long'  => ['required', 'integer', Rule::in([10, 15, 20])],
            'position_leverage_short' => ['required', 'integer', Rule::in([10, 15, 20])],

            'margin_percentage_long'  => ['required', 'numeric', Rule::in(['4.00', '5.00', '6.00'])],
            'margin_percentage_short' => ['required', 'numeric', Rule::in(['4.00', '5.00', '6.00'])],
        ];
    }

    public function prepareForValidation(): void
    {
        // HTML form posts only ship checked checkboxes — coerce so the
        // `boolean` rule sees a real bool either way.
        $this->merge([
            'can_trade' => $this->boolean('can_trade'),
        ]);
    }
}
