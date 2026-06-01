<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;
use Throwable;

class AccountController extends Controller
{
    private const ACCOUNT_CONNECTIVITY_DRAFT_PREFIX = 'Connection test for account ';

    private const REGISTRATION_CONNECTIVITY_DRAFT_NAME = 'Registration connectivity test';

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

        $query = Account::with(['apiSystem', 'user'])
            ->where('name', 'not like', self::ACCOUNT_CONNECTIVITY_DRAFT_PREFIX.'%')
            ->where('name', '!=', self::REGISTRATION_CONNECTIVITY_DRAFT_NAME);

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

        $data = $request->only(self::EDITABLE_FIELDS);

        if (! Auth::user()->is_admin && $account->disabled_reason !== null) {
            $data['can_trade'] = false;
        }

        $account->update($data);

        return back()
            ->with('status', 'Account updated.')
            ->with('updated_account_id', $account->id);
    }

    public function saveCredentials(Request $request, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = $this->accountForCurrentUser((int) $request->input('account_id'));
        $exchange = (string) $account->apiSystem?->canonical;

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'passphrase' => [
                'nullable',
                Rule::requiredIf(fn (): bool => in_array($exchange, ['kucoin', 'bitget'], true)),
                'string',
                'max:2000',
            ],
            'tested_block_uuid' => ['required', 'uuid'],
        ]);

        $testedAccount = $this->accountForBlock((string) $data['tested_block_uuid']);

        if (! $this->isConnectivityDraftFor($testedAccount, $account)) {
            return response()->json([
                'message' => 'Run a connection test before saving these API keys.',
            ], 422);
        }

        if (! $this->credentialsMatch($testedAccount, $exchange, $data)) {
            return response()->json([
                'message' => 'The API keys changed after the test. Test the new keys before saving.',
            ], 422);
        }

        $payload = $this->augmentConnectivityPayload($connectivity->status((string) $data['tested_block_uuid']));

        if (! (bool) $payload['is_complete']) {
            return response()->json([
                'message' => 'Wait for the connection test to finish before saving these API keys.',
            ], 422);
        }

        $allConnected = (bool) $payload['all_connected'];
        $credentials = $this->credentialColumns(
            exchange: $exchange,
            apiKey: (string) $data['api_key'],
            apiSecret: (string) $data['api_secret'],
            passphrase: $data['passphrase'] ?? null,
        );

        DB::transaction(function () use ($account, $allConnected, $credentials, $testedAccount): void {
            $account->all_credentials = $credentials;
            $account->forceFill([
                'can_trade' => $allConnected,
                'disabled_reason' => $allConnected ? null : 'Some Kraite IP addresses are not allowed in your exchange account.',
                'disabled_at' => $allConnected ? null : now(),
            ])->save();

            $testedAccount->forceDelete();
        });

        return response()->json([
            'message' => $allConnected
                ? 'API keys saved. Trading is enabled for this account.'
                : 'API keys saved. Trading stays disabled until the Kraite IP addresses are allowed in your exchange account.',
            'account' => $this->serialize($account->load(['apiSystem', 'user'])),
        ]);
    }

    public function testConnectivity(Request $request, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = $this->accountForCurrentUser((int) $request->input('account_id'));
        $exchange = (string) $account->apiSystem?->canonical;

        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'passphrase' => [
                'nullable',
                Rule::requiredIf(fn (): bool => in_array($exchange, ['kucoin', 'bitget'], true)),
                'string',
                'max:2000',
            ],
        ]);

        $draft = $this->draftConnectivityAccountFor($account);
        $this->fillDraftConnectivityAccount($draft, $account, $data);
        $draft->save();

        return response()->json($this->augmentConnectivityPayload($connectivity->start($draft)));
    }

    public function connectivityStatus(string $blockUuid, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $this->accountForBlock($blockUuid);
        $payload = $this->augmentConnectivityPayload($connectivity->status($blockUuid));

        return response()->json($payload);
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
            'is_active' => (bool) $account->is_active,
            'disabled_reason' => $account->disabled_reason,
            'disabled_at' => $account->disabled_at ? (string) $account->disabled_at : null,
            'has_credentials' => $this->hasRequiredCredentials($account),
            'requires_passphrase' => in_array((string) $account->apiSystem?->canonical, ['kucoin', 'bitget'], true),
        ];

        foreach (self::EDITABLE_FIELDS as $field) {
            $base[$field] = $account->{$field};
        }

        return $base;
    }

    private function accountForCurrentUser(int $accountId): Account
    {
        $query = Account::with(['apiSystem', 'user'])->where('id', $accountId);

        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }

        return $query->firstOrFail();
    }

    private function accountForBlock(string $blockUuid): Account
    {
        $step = Step::query()
            ->where('block_uuid', $blockUuid)
            ->where('class', TestExchangeConnectivityStep::class)
            ->first();

        if (! $step instanceof Step || $step->relatable_id === null) {
            throw (new ModelNotFoundException)->setModel(Step::class, [$blockUuid]);
        }

        return $this->accountForCurrentUser((int) $step->relatable_id);
    }

    private function draftConnectivityAccountFor(Account $account): Account
    {
        return Account::query()
            ->where('user_id', $account->user_id)
            ->where('name', $this->connectivityDraftName($account))
            ->where('is_active', false)
            ->where('can_trade', false)
            ->first() ?? new Account;
    }

    /**
     * @param  array{api_key: string, api_secret: string, passphrase?: string|null}  $data
     */
    private function fillDraftConnectivityAccount(Account $draft, Account $account, array $data): void
    {
        $exchange = (string) $account->apiSystem?->canonical;

        $draft->forceFill([
            'uuid' => $draft->uuid ?: (string) Str::uuid(),
            'user_id' => $account->user_id,
            'api_system_id' => $account->api_system_id,
            'trade_configuration_id' => $account->trade_configuration_id,
            'name' => $this->connectivityDraftName($account),
            'portfolio_quote' => $account->portfolio_quote ?: 'USDT',
            'trading_quote' => $account->trading_quote ?: 'USDT',
            'margin' => $account->margin,
            'balance_for_trading_basis' => $account->balance_for_trading_basis,
            'can_trade' => false,
            'is_active' => false,
            'disabled_reason' => null,
            'disabled_at' => null,
            'profit_percentage' => $account->profit_percentage,
            'total_limit_orders_filled_to_notify' => $account->total_limit_orders_filled_to_notify,
            'stop_market_initial_percentage' => $account->stop_market_initial_percentage,
            'override_tp' => $account->override_tp,
            'override_sl' => $account->override_sl,
            'total_positions_long' => $account->total_positions_long,
            'total_positions_short' => $account->total_positions_short,
            'position_leverage_long' => $account->position_leverage_long,
            'position_leverage_short' => $account->position_leverage_short,
            'margin_percentage_long' => $account->margin_percentage_long,
            'margin_percentage_short' => $account->margin_percentage_short,
            'margin_mode' => $account->margin_mode,
            'on_hedge_mode' => $account->on_hedge_mode,
            'allow_other_positions' => $account->allow_other_positions,
            'allow_other_orders' => $account->allow_other_orders,
        ]);

        $draft->forceFill($this->emptyCredentialColumns());
        $draft->all_credentials = $this->credentialColumns(
            exchange: $exchange,
            apiKey: (string) $data['api_key'],
            apiSecret: (string) $data['api_secret'],
            passphrase: $data['passphrase'] ?? null,
        );
    }

    private function isConnectivityDraftFor(Account $draft, Account $account): bool
    {
        return (int) $draft->user_id === (int) $account->user_id
            && $draft->name === $this->connectivityDraftName($account)
            && (bool) $draft->is_active === false
            && (bool) $draft->can_trade === false;
    }

    /**
     * @param  array{api_key: string, api_secret: string, passphrase?: string|null}  $data
     */
    private function credentialsMatch(Account $testedAccount, string $exchange, array $data): bool
    {
        $testedCredentials = $testedAccount->all_credentials;
        $expectedCredentials = $this->credentialColumns(
            exchange: $exchange,
            apiKey: (string) $data['api_key'],
            apiSecret: (string) $data['api_secret'],
            passphrase: $data['passphrase'] ?? null,
        );

        foreach ($expectedCredentials as $key => $value) {
            if (($testedCredentials[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function connectivityDraftName(Account $account): string
    {
        return self::ACCOUNT_CONNECTIVITY_DRAFT_PREFIX.$account->id;
    }

    private function hasRequiredCredentials(Account $account): bool
    {
        $exchange = (string) $account->apiSystem?->canonical;
        $credentials = $account->all_credentials;

        return filled($credentials["{$exchange}_api_key"] ?? null)
            && filled($credentials["{$exchange}_api_secret"] ?? null)
            && (! in_array($exchange, ['kucoin', 'bitget'], true) || filled($credentials["{$exchange}_passphrase"] ?? null));
    }

    /**
     * @return array<string, null>
     */
    private function emptyCredentialColumns(): array
    {
        return [
            'binance_api_key' => null,
            'binance_api_secret' => null,
            'bybit_api_key' => null,
            'bybit_api_secret' => null,
            'kraken_api_key' => null,
            'kraken_private_key' => null,
            'kucoin_api_key' => null,
            'kucoin_api_secret' => null,
            'kucoin_passphrase' => null,
            'bitget_api_key' => null,
            'bitget_api_secret' => null,
            'bitget_passphrase' => null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function credentialColumns(string $exchange, string $apiKey, string $apiSecret, ?string $passphrase): array
    {
        return match ($exchange) {
            'binance' => [
                'binance_api_key' => $apiKey,
                'binance_api_secret' => $apiSecret,
            ],
            'bybit' => [
                'bybit_api_key' => $apiKey,
                'bybit_api_secret' => $apiSecret,
            ],
            'kucoin' => [
                'kucoin_api_key' => $apiKey,
                'kucoin_api_secret' => $apiSecret,
                'kucoin_passphrase' => $passphrase,
            ],
            'bitget' => [
                'bitget_api_key' => $apiKey,
                'bitget_api_secret' => $apiSecret,
                'bitget_passphrase' => $passphrase,
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function augmentConnectivityPayload(array $payload): array
    {
        $servers = collect($payload['servers'] ?? []);
        $connected = $servers->where('status', 'connected')->count();
        $failed = $servers->where('status', 'not_connected')->count();
        $total = (int) ($payload['total_servers'] ?? $servers->count());
        $isComplete = (bool) ($payload['is_complete'] ?? false);

        return array_merge($payload, [
            'is_complete' => $isComplete,
            'connected_servers' => $connected,
            'failed_servers' => $failed,
            'all_connected' => $isComplete && $total > 0 && $failed === 0 && $connected === $total,
        ]);
    }
}
