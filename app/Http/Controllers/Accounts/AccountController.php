<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;
use Throwable;

class AccountController extends Controller
{
    private const ACCOUNT_CONNECTIVITY_DRAFT_PREFIX = 'Connection test for account ';

    private const REGISTRATION_CONNECTIVITY_DRAFT_NAME = 'Registration connectivity test';

    private const CONNECTIVITY_FAILED_REASON = 'Connectivity failed from one or more Kraite servers.';

    private const CONNECTIVITY_AUTO_DISABLED_REASON_PREFIX = 'All worker IPs blacklisted on ';

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

    public function edit(AccountServerConnectivityService $connectivity): View
    {
        $isAdmin = (bool) Auth::user()->is_admin;

        $query = Account::with(['apiSystem', 'user.subscription'])
            ->withCount([
                'positions as open_positions_count' => static fn (Builder $query): Builder => $query->opened(),
            ])
            ->where('name', 'not like', self::ACCOUNT_CONNECTIVITY_DRAFT_PREFIX.'%')
            ->where('name', '!=', self::REGISTRATION_CONNECTIVITY_DRAFT_NAME);

        if (! $isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $accountModels = $query
            ->orderBy('user_id')
            ->orderBy('name')
            ->get();

        $connectivityServerModels = $connectivity->apiConnectivityServers();
        $activeConnectivityBans = $this->activeConnectivityBans($accountModels);

        $accounts = $accountModels
            ->map(fn (Account $account) => $this->serialize(
                $account,
                $this->connectivityHealth($account, $activeConnectivityBans, $connectivityServerModels),
            ))
            ->all();

        $connectivityServers = $connectivityServerModels
            ->map(static fn (Server $server): array => [
                'id' => $server->id,
                'hostname' => $server->hostname,
                'ip_address' => $server->ip_address,
            ])
            ->values()
            ->all();

        return view('accounts.edit', [
            'accounts' => $accounts,
            'connectivityServers' => $connectivityServers,
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

    public function update(UpdateAccountRequest $request): JsonResponse|RedirectResponse
    {
        $query = Account::with('user.subscription')
            ->withCount([
                'positions as open_positions_count' => static fn (Builder $query): Builder => $query->opened(),
            ])
            ->where('id', $request->input('account_id'));
        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }
        $account = $query->firstOrFail();

        $data = $request->only(self::EDITABLE_FIELDS);
        $subscriptionActive = $this->subscriptionActive($account);

        if (($data['can_trade'] ?? false) && ! $subscriptionActive) {
            throw ValidationException::withMessages([
                'can_trade' => 'Trading cannot be enabled while the subscription is inactive.',
            ]);
        }

        $this->rejectLockedQuoteChanges($account, $data);

        if (! Auth::user()->is_admin && $account->disabled_reason !== null) {
            $data['can_trade'] = false;
        }

        $account->update($data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Account updated.',
                'account' => [
                    'id' => $account->id,
                    'can_trade' => (bool) $account->can_trade,
                    'portfolio_quote' => $account->portfolio_quote,
                    'trading_quote' => $account->trading_quote,
                    'subscription_active' => $subscriptionActive,
                    'open_positions_count' => (int) $account->open_positions_count,
                ],
            ]);
        }

        return back()
            ->with('status', 'Account updated.')
            ->with('updated_account_id', $account->id);
    }

    public function disableTrading(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = $this->accountForCurrentUser((int) $validated['account_id']);
        $account->update(['can_trade' => false]);

        return response()->json([
            'message' => 'Trading disabled for this account.',
            'account' => [
                'id' => $account->id,
                'can_trade' => (bool) $account->can_trade,
            ],
        ]);
    }

    public function saveCredentials(Request $request, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'tested_block_uuid' => ['required', 'uuid'],
        ]);

        $account = $this->accountForCurrentUser((int) $validated['account_id']);
        $exchange = (string) $account->apiSystem?->canonical;
        $credentials = $this->validateConnectivityCredentials($request, $exchange, false);
        $testedAccount = $this->accountForBlock((string) $validated['tested_block_uuid']);
        $usesSavedCredentials = ! $this->hasCredentialInput($credentials);

        if (! $this->isConnectivityDraftFor($testedAccount, $account)) {
            return response()->json([
                'message' => 'Run a connection test before saving these API keys.',
            ], 422);
        }

        if ($usesSavedCredentials) {
            if (! $this->hasRequiredCredentials($account)) {
                return response()->json([
                    'message' => 'This account has no saved API credentials to apply.',
                ], 422);
            }

            if (! $this->credentialsMatch($testedAccount, $exchange, $this->credentialsForAccount($account, $exchange))) {
                return response()->json([
                    'message' => 'The saved API credentials changed during the test. Test them again.',
                ], 422);
            }
        } else {
            $credentials = $this->validateConnectivityCredentials($request, $exchange, true);

            if (! $this->credentialsMatch($testedAccount, $exchange, $credentials)) {
                return response()->json([
                    'message' => 'The API keys changed after the test. Test the new keys before saving.',
                ], 422);
            }
        }

        $payload = $this->augmentConnectivityPayload($connectivity->status((string) $validated['tested_block_uuid']));

        if (! (bool) $payload['is_complete']) {
            return response()->json([
                'message' => 'Wait for the connection test to finish before saving these API keys.',
            ], 422);
        }

        $allConnected = (bool) $payload['all_connected'];
        $credentialColumns = $usesSavedCredentials
            ? null
            : $this->credentialColumns(
                exchange: $exchange,
                apiKey: (string) $credentials['api_key'],
                apiSecret: (string) $credentials['api_secret'],
                passphrase: $credentials['passphrase'] ?? null,
            );

        $wasConnectivityAutoDisabled = $this->isConnectivityAutoDisabled($account);
        $hadConnectivityFailure = $wasConnectivityAutoDisabled
            || $account->disabled_reason === self::CONNECTIVITY_FAILED_REASON;

        DB::transaction(function () use ($account, $allConnected, $credentialColumns, $testedAccount, $wasConnectivityAutoDisabled, $hadConnectivityFailure): void {
            if ($credentialColumns !== null) {
                $account->all_credentials = $credentialColumns;
            }

            $state = ['can_trade' => $allConnected];

            if ($allConnected && $hadConnectivityFailure) {
                $state['disabled_reason'] = null;
                $state['disabled_at'] = null;
            } elseif (! $allConnected && ! $wasConnectivityAutoDisabled) {
                $state['disabled_reason'] = self::CONNECTIVITY_FAILED_REASON;
                $state['disabled_at'] = now();
            }

            if ($allConnected && $wasConnectivityAutoDisabled) {
                $state['is_active'] = true;
            }

            $account->forceFill($state)->save();

            if ($allConnected) {
                ForbiddenHostname::query()
                    ->where('account_id', $account->id)
                    ->where('api_system_id', $account->api_system_id)
                    ->whereIn('type', [
                        ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                        ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
                    ])
                    ->delete();
            }

            $testedAccount->forceDelete();
        });

        $account = $account->fresh(['apiSystem', 'user.subscription']);
        $account->loadCount([
            'positions as open_positions_count' => static fn (Builder $query): Builder => $query->opened(),
        ]);
        $connectivityServers = $connectivity->apiConnectivityServers();
        $activeConnectivityBans = $this->activeConnectivityBans(collect([$account]));

        return response()->json([
            'message' => $this->connectivitySaveMessage(
                $usesSavedCredentials,
                $allConnected,
                (bool) $account->is_active,
                $this->subscriptionActive($account),
            ),
            'account' => $this->serialize(
                $account,
                $this->connectivityHealth($account, $activeConnectivityBans, $connectivityServers),
            ),
        ]);
    }

    public function testConnectivity(Request $request, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = $this->accountForCurrentUser((int) $validated['account_id']);
        $exchange = (string) $account->apiSystem?->canonical;
        $credentials = $this->validateConnectivityCredentials($request, $exchange, false);
        $credentialsMode = 'replacement';

        if (! $this->hasCredentialInput($credentials)) {
            if (! $this->hasRequiredCredentials($account)) {
                return response()->json([
                    'message' => 'Enter API credentials before testing this account.',
                ], 422);
            }
            $credentials = $this->credentialsForAccount($account, $exchange);
            $credentialsMode = 'saved';
        } else {
            $credentials = $this->validateConnectivityCredentials($request, $exchange, true);
        }

        $draft = $this->draftConnectivityAccountFor($account);
        $this->fillDraftConnectivityAccount($draft, $account, $credentials);
        $draft->save();

        return response()->json(array_merge(
            $this->augmentConnectivityPayload($connectivity->start($draft)),
            ['credentials_mode' => $credentialsMode],
        ));
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
    private function serialize(Account $account, array $connectivityHealth): array
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
            'connectivity_health' => $connectivityHealth,
            'subscription_active' => $this->subscriptionActive($account),
            'open_positions_count' => (int) ($account->open_positions_count ?? 0),
        ];

        foreach (self::EDITABLE_FIELDS as $field) {
            $base[$field] = $account->{$field};
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectLockedQuoteChanges(Account $account, array $data): void
    {
        $portfolioQuoteChanged = ($data['portfolio_quote'] ?? $account->portfolio_quote) !== $account->portfolio_quote;
        $tradingQuoteChanged = ($data['trading_quote'] ?? $account->trading_quote) !== $account->trading_quote;

        if (! $portfolioQuoteChanged && ! $tradingQuoteChanged) {
            return;
        }

        $errors = [];
        $hasOpenPositions = (int) $account->open_positions_count > 0;
        $tradingWillBeEnabled = (bool) ($data['can_trade'] ?? $account->can_trade);

        if ($portfolioQuoteChanged && $hasOpenPositions) {
            $errors['portfolio_quote'] = 'Portfolio quote cannot change while this account has open positions.';
        } elseif ($portfolioQuoteChanged && $tradingWillBeEnabled) {
            $errors['portfolio_quote'] = 'Portfolio quote cannot change while account trading is enabled.';
        }

        if ($tradingQuoteChanged && $hasOpenPositions) {
            $errors['trading_quote'] = 'Trading quote cannot change while this account has open positions.';
        } elseif ($tradingQuoteChanged && $tradingWillBeEnabled) {
            $errors['trading_quote'] = 'Trading quote cannot change while account trading is enabled.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function subscriptionActive(Account $account): bool
    {
        return $account->user?->billing()->subscription()->isActive() ?? false;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, ForbiddenHostname>
     */
    private function activeConnectivityBans(Collection $accounts): Collection
    {
        $apiSystemIds = $accounts->pluck('api_system_id')->filter()->unique()->values();
        $accountIds = $accounts->pluck('id')->filter()->unique()->values();

        if ($apiSystemIds->isEmpty() || $accountIds->isEmpty()) {
            return collect();
        }

        return ForbiddenHostname::query()
            ->whereIn('api_system_id', $apiSystemIds)
            ->where(static function ($query) use ($accountIds): void {
                $query->whereIn('account_id', $accountIds)->orWhereNull('account_id');
            })
            ->where(static function ($query): void {
                $query->whereNull('forbidden_until')->orWhere('forbidden_until', '>', now());
            })
            ->get();
    }

    /**
     * @param  Collection<int, ForbiddenHostname>  $activeBans
     * @param  Collection<int, Server>  $connectivityServers
     * @return array{kind: string, label: string, blocked_servers: int, total_servers: int}
     */
    private function connectivityHealth(Account $account, Collection $activeBans, Collection $connectivityServers): array
    {
        $totalServers = $connectivityServers->count();

        if ($this->isConnectivityAutoDisabled($account)) {
            return [
                'kind' => 'critical',
                'label' => 'Trading stopped: no safe server route',
                'blocked_servers' => 0,
                'total_servers' => $totalServers,
            ];
        }

        if (! $this->hasRequiredCredentials($account)) {
            return [
                'kind' => 'unconfigured',
                'label' => 'Not connected',
                'blocked_servers' => 0,
                'total_servers' => $totalServers,
            ];
        }

        if ($totalServers === 0) {
            return [
                'kind' => 'unconfigured',
                'label' => 'No eligible servers configured',
                'blocked_servers' => 0,
                'total_servers' => 0,
            ];
        }

        $accountBans = $activeBans->filter(
            static fn (ForbiddenHostname $ban): bool => (int) $ban->api_system_id === (int) $account->api_system_id
                && ($ban->account_id === null || (int) $ban->account_id === (int) $account->id),
        );

        $eligibleServerIps = $connectivityServers
            ->pluck('ip_address')
            ->filter()
            ->unique();
        $blockedServerCount = $accountBans
            ->whereIn('type', [
                ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                ForbiddenHostname::TYPE_IP_RATE_LIMITED,
                ForbiddenHostname::TYPE_IP_BANNED,
            ])
            ->pluck('ip_address')
            ->intersect($eligibleServerIps)
            ->unique()
            ->count();
        $hasAccountBlock = $accountBans->contains(
            static fn (ForbiddenHostname $ban): bool => $ban->type === ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
        );

        if ($hasAccountBlock || $blockedServerCount > 0 || $account->disabled_reason === self::CONNECTIVITY_FAILED_REASON) {
            return [
                'kind' => 'degraded',
                'label' => $blockedServerCount > 0
                    ? sprintf('%d of %d servers blocked', $blockedServerCount, $totalServers)
                    : 'Connectivity degraded',
                'blocked_servers' => $blockedServerCount,
                'total_servers' => $totalServers,
            ];
        }

        return [
            'kind' => 'healthy',
            'label' => 'No active server blocks',
            'blocked_servers' => 0,
            'total_servers' => $totalServers,
        ];
    }

    private function isConnectivityAutoDisabled(Account $account): bool
    {
        return ! $account->is_active
            && Str::startsWith((string) $account->disabled_reason, self::CONNECTIVITY_AUTO_DISABLED_REASON_PREFIX);
    }

    private function connectivitySaveMessage(
        bool $usesSavedCredentials,
        bool $allConnected,
        bool $isActive,
        bool $subscriptionActive,
    ): string {
        if (! $allConnected) {
            return $usesSavedCredentials
                ? 'Connectivity result applied. Trading stays disabled until every Kraite server connects.'
                : 'API keys saved. Trading stays disabled until connectivity succeeds from every Kraite server.';
        }

        if (! $isActive) {
            return $usesSavedCredentials
                ? 'Connectivity result applied. Every server connected, but this account remains inactive.'
                : 'API keys saved. Every server connected, but this account remains inactive.';
        }

        if (! $subscriptionActive) {
            return $usesSavedCredentials
                ? 'Connectivity result applied. The connection is ready, but an inactive subscription prevents new positions.'
                : 'API keys saved. The connection is ready, but an inactive subscription prevents new positions.';
        }

        return $usesSavedCredentials
            ? 'Connectivity result applied. Trading is enabled for this account.'
            : 'API keys saved. Trading is enabled for this account.';
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
     * @return array{api_key?: string|null, api_secret?: string|null, passphrase?: string|null}
     */
    private function validateConnectivityCredentials(Request $request, string $exchange, bool $required): array
    {
        $presenceRule = $required ? 'required' : 'nullable';

        return $request->validate([
            'api_key' => [$presenceRule, 'string', 'max:2000'],
            'api_secret' => [$presenceRule, 'string', 'max:2000'],
            'passphrase' => [
                Rule::requiredIf($required && in_array($exchange, ['kucoin', 'bitget'], true)),
                'nullable',
                'string',
                'max:2000',
            ],
        ]);
    }

    /**
     * @param  array{api_key?: string|null, api_secret?: string|null, passphrase?: string|null}  $credentials
     */
    private function hasCredentialInput(array $credentials): bool
    {
        return filled($credentials['api_key'] ?? null)
            || filled($credentials['api_secret'] ?? null)
            || filled($credentials['passphrase'] ?? null);
    }

    /**
     * @return array{api_key: string, api_secret: string, passphrase: string|null}
     */
    private function credentialsForAccount(Account $account, string $exchange): array
    {
        $credentials = $account->all_credentials;

        return [
            'api_key' => (string) ($credentials["{$exchange}_api_key"] ?? ''),
            'api_secret' => (string) ($credentials["{$exchange}_api_secret"] ?? ''),
            'passphrase' => isset($credentials["{$exchange}_passphrase"])
                ? (string) $credentials["{$exchange}_passphrase"]
                : null,
        ];
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
