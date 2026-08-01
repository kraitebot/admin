<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\Account;

/**
 * Validation + ownership gate for the edit-account form.
 *
 * The numeric `in:` lists are deliberate — the operator picks from a
 * curated set of values rather than free-typing arbitrary numbers, which
 * keeps risk-relevant settings (leverage, margin %, slot count) inside
 * a tested envelope. They live in public constants so the mobile app is
 * offered exactly the choices this request accepts — one source of truth,
 * no drift between the picker and the rule.
 */
final class UpdateAccountRequest extends FormRequest
{
    /** @var array<int, string> */
    public const PROFIT_PERCENTAGES = ['0.360', '0.380', '0.400'];

    /** @var array<int, string> */
    public const STOP_MARKET_PERCENTAGES = ['2.50', '5.00', '7.50'];

    /** @var array<int, int> */
    public const POSITION_COUNTS = [1, 4, 5, 6];

    /** @var array<int, int> */
    public const POSITION_LEVERAGES = [10, 15, 20];

    /** @var array<int, string> */
    public const MARGIN_PERCENTAGES = ['4.00', '5.00', '6.00'];

    public function authorize(): bool
    {
        return Account::query()
            ->where('id', $this->input('account_id'))
            ->where('user_id', Auth::id())
            ->exists();
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
            'trading_quote' => ['required', 'string', 'max:20'],

            'can_trade' => ['boolean'],

            'profit_percentage' => ['required', 'numeric', Rule::in(self::PROFIT_PERCENTAGES)],
            'stop_market_initial_percentage' => ['required', 'numeric', Rule::in(self::STOP_MARKET_PERCENTAGES)],

            'total_positions_long' => ['required', 'integer', Rule::in(self::POSITION_COUNTS)],
            'total_positions_short' => ['required', 'integer', Rule::in(self::POSITION_COUNTS)],

            'position_leverage_long' => ['required', 'integer', Rule::in(self::POSITION_LEVERAGES)],
            'position_leverage_short' => ['required', 'integer', Rule::in(self::POSITION_LEVERAGES)],

            'margin_percentage_long' => ['required', 'numeric', Rule::in(self::MARGIN_PERCENTAGES)],
            'margin_percentage_short' => ['required', 'numeric', Rule::in(self::MARGIN_PERCENTAGES)],
        ];
    }

    public function prepareForValidation(): void
    {
        // HTML form posts only ship checked checkboxes — coerce so the
        // `boolean` rule sees a real bool either way.
        if ($this->has('can_trade')) {
            $this->merge([
                'can_trade' => $this->boolean('can_trade'),
            ]);
        }
    }
}
