<?php

declare(strict_types=1);

namespace App\Support\Registration;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;

final class RegistrationCompleter
{
    /**
     * @param  array{name: string, password: string, exchange: string, api_key: string, api_secret: string, passphrase?: string|null, subscription_id: int|string}  $data
     */
    public function complete(User $user, array $data): void
    {
        DB::transaction(function () use ($data, $user): void {
            $subscription = Subscription::whereKey((int) $data['subscription_id'])->firstOrFail();
            $apiSystem = ApiSystem::where('canonical', $data['exchange'])->firstOrFail();
            $tradeConfiguration = TradeConfiguration::where('is_default', true)->first()
                ?? TradeConfiguration::orderBy('id')->firstOrFail();

            $user->forceFill([
                'name' => $data['name'],
                'password' => $data['password'],
                'subscription_id' => $subscription->id,
                'trial_started_at' => now(),
                'status' => 'active',
                'is_active' => true,
                'can_trade' => true,
            ])->save();

            $account = new Account([
                'user_id' => $user->id,
                'api_system_id' => $apiSystem->id,
                'trade_configuration_id' => $tradeConfiguration->id,
                'name' => "{$apiSystem->name} Account",
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'margin' => 1000,
                'can_trade' => true,
                'is_active' => true,
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

            $account->all_credentials = $this->credentialColumns(
                exchange: (string) $data['exchange'],
                apiKey: (string) $data['api_key'],
                apiSecret: (string) $data['api_secret'],
                passphrase: $data['passphrase'] ?? null,
            );
            $account->save();

            if ($subscription->max_accounts === 1) {
                $user->active_account_id = $account->id;
                $user->save();
            }
        });
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
