<?php

declare(strict_types=1);

namespace App\Support\Registration;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;

final class RegistrationConnectivityWorkflow
{
    public function __construct(private readonly AccountServerConnectivityService $connectivity) {}

    /**
     * @param  array{exchange: string, api_key: string, api_secret: string, passphrase?: string|null}  $data
     * @return array<string, mixed>
     */
    public function start(User $user, array $data): array
    {
        $account = $this->draftAccountFor($user) ?? new Account;

        $this->fillDraftAccount($account, $user, $data);
        $account->save();

        $payload = $this->connectivity->start($account);

        $user->forceFill([
            'current_connectivity_test_uuid' => $payload['block_uuid'] ?? null,
        ])->save();

        return $this->augmentPayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user, string $blockUuid): array
    {
        $this->authorizeBlockForUser($user, $blockUuid);

        return $this->augmentPayload($this->connectivity->status($blockUuid));
    }

    /**
     * @return array{is_complete: bool, all_connected: bool, draft_account: Account|null, payload: array<string, mixed>|null}
     */
    public function evaluate(User $user, ?string $blockUuid): array
    {
        $blockUuid ??= $user->current_connectivity_test_uuid;

        if (! is_string($blockUuid) || $blockUuid === '') {
            return [
                'is_complete' => false,
                'all_connected' => false,
                'draft_account' => null,
                'payload' => null,
            ];
        }

        $this->authorizeBlockForUser($user, $blockUuid);

        $payload = $this->augmentPayload($this->connectivity->status($blockUuid));

        return [
            'is_complete' => (bool) $payload['is_complete'],
            'all_connected' => (bool) $payload['all_connected'],
            'draft_account' => $this->draftAccountForBlock($user, $blockUuid),
            'payload' => $payload,
        ];
    }

    private function draftAccountFor(User $user): ?Account
    {
        $blockUuid = $user->current_connectivity_test_uuid;

        if (is_string($blockUuid) && $blockUuid !== '') {
            $account = $this->draftAccountForBlock($user, $blockUuid);

            if ($account instanceof Account) {
                return $account;
            }
        }

        return Account::query()
            ->where('user_id', $user->id)
            ->where('name', 'Registration connectivity test')
            ->where('is_active', false)
            ->where('can_trade', false)
            ->latest('id')
            ->first();
    }

    private function draftAccountForBlock(User $user, string $blockUuid): ?Account
    {
        $step = Step::query()
            ->where('block_uuid', $blockUuid)
            ->where('class', TestExchangeConnectivityStep::class)
            ->first();

        if (! $step instanceof Step || $step->relatable_id === null) {
            return null;
        }

        $account = Account::find((int) $step->relatable_id);

        if (! $account instanceof Account || (int) $account->user_id !== (int) $user->id) {
            return null;
        }

        return $account;
    }

    /**
     * @param  array{exchange: string, api_key: string, api_secret: string, passphrase?: string|null}  $data
     */
    private function fillDraftAccount(Account $account, User $user, array $data): void
    {
        $apiSystem = ApiSystem::where('canonical', $data['exchange'])->firstOrFail();
        $tradeConfiguration = TradeConfiguration::where('is_default', true)->first()
            ?? TradeConfiguration::orderBy('id')->firstOrFail();

        $account->forceFill([
            'uuid' => $account->uuid ?: (string) Str::uuid(),
            'user_id' => $user->id,
            'api_system_id' => $apiSystem->id,
            'trade_configuration_id' => $tradeConfiguration->id,
            'name' => 'Registration connectivity test',
            'portfolio_quote' => 'USDT',
            'trading_quote' => 'USDT',
            'margin' => 1000,
            'can_trade' => false,
            'is_active' => false,
            'profit_percentage' => '0.360',
            'stop_market_initial_percentage' => '2.50',
            'total_positions_long' => 4,
            'total_positions_short' => 4,
            'position_leverage_long' => 10,
            'position_leverage_short' => 10,
            'margin_percentage_long' => '4.00',
            'margin_percentage_short' => '4.00',
            'on_hedge_mode' => false,
            'allow_other_positions' => false,
            'allow_other_orders' => false,
        ]);

        $account->forceFill($this->emptyCredentialColumns());
        $account->all_credentials = $this->credentialColumns(
            exchange: (string) $data['exchange'],
            apiKey: (string) $data['api_key'],
            apiSecret: (string) $data['api_secret'],
            passphrase: $data['passphrase'] ?? null,
        );
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

    private function authorizeBlockForUser(User $user, string $blockUuid): void
    {
        if ($user->current_connectivity_test_uuid !== $blockUuid) {
            throw (new ModelNotFoundException)->setModel(Step::class, [$blockUuid]);
        }

        if (! $this->draftAccountForBlock($user, $blockUuid) instanceof Account) {
            throw (new ModelNotFoundException)->setModel(Step::class, [$blockUuid]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function augmentPayload(array $payload): array
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
            'can_continue' => $isComplete,
        ]);
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
}
