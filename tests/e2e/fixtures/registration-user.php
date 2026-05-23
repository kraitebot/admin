<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Jobs\Lifecycles\Account\TestServerConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;

ModelLog::disable();

User::withoutEvents(function (): void {
    $user = User::updateOrCreate(
        ['email' => 'browser.registration@kraite.test'],
        [
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Browser Registration',
            'email_verified_at' => now(),
            'password' => 'temporary-password',
            'status' => 'confirmed',
            'is_active' => true,
            'can_trade' => false,
            'is_admin' => false,
            'subscription_id' => null,
            'active_account_id' => null,
            'current_connectivity_test_uuid' => '22222222-2222-4222-8222-222222222222',
            'notification_channels' => ['mail'],
        ],
    );

    $apiSystem = ApiSystem::where('canonical', 'binance')->firstOrFail();
    $tradeConfiguration = TradeConfiguration::where('is_default', true)->first()
        ?? TradeConfiguration::orderBy('id')->firstOrFail();
    $subscription = Subscription::where('canonical', 'basic')->firstOrFail();

    $user->forceFill(['subscription_id' => $subscription->id])->save();

    $account = Account::updateOrCreate(
        [
            'user_id' => $user->id,
            'name' => 'Registration connectivity test',
        ],
        [
            'uuid' => (string) Str::uuid(),
            'api_system_id' => $apiSystem->id,
            'trade_configuration_id' => $tradeConfiguration->id,
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
        ],
    );

    $account->all_credentials = [
        'binance_api_key' => 'binance-key',
        'binance_api_secret' => 'binance-secret',
    ];
    $account->save();

    Step::whereIn('block_uuid', [
        '22222222-2222-4222-8222-222222222222',
        '22222222-2222-4222-8222-333333333333',
    ])->delete();

    Step::create([
        'block_uuid' => '22222222-2222-4222-8222-222222222222',
        'child_block_uuid' => '22222222-2222-4222-8222-333333333333',
        'class' => TestExchangeConnectivityStep::class,
        'state' => Completed::class,
        'queue' => 'cronjobs',
        'relatable_type' => Account::class,
        'relatable_id' => $account->id,
        'arguments' => ['accountId' => $account->id],
        'index' => 1,
        'completed_at' => now(),
    ]);

    Server::where('is_apiable', true)
        ->where('needs_whitelisting', true)
        ->whereNotNull('ip_address')
        ->whereNotIn('type', ['database', 'admin', 'indicators'])
        ->whereNotIn('hostname', ['artemis'])
        ->orderBy('hostname')
        ->get()
        ->each(function (Server $server, int $index) use ($account): void {
            Step::create([
                'block_uuid' => '22222222-2222-4222-8222-333333333333',
                'class' => TestServerConnectivityStep::class,
                'state' => Completed::class,
                'queue' => $server->own_queue_name ?: 'default',
                'relatable_type' => Account::class,
                'relatable_id' => $account->id,
                'arguments' => [
                    'accountId' => $account->id,
                    'serverId' => $server->id,
                ],
                'index' => $index + 1,
                'completed_at' => now(),
            ]);
        });
});
