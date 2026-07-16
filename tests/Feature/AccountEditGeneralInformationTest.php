<?php

it('explains every trading setting and renders the leverage heading correctly', function (): void {
    $html = view('accounts.edit', [
        'accounts' => [[
            'id' => 42,
            'exchange' => 'Bitget',
            'exchange_canonical' => 'bitget',
            'owner' => 'Account Owner',
            'is_active' => true,
            'disabled_reason' => null,
            'disabled_at' => null,
            'has_credentials' => true,
            'requires_passphrase' => false,
            'name' => 'Primary Account',
            'portfolio_quote' => 'USDT',
            'trading_quote' => 'USDT',
            'can_trade' => true,
            'profit_percentage' => 0.36,
            'stop_market_initial_percentage' => 3,
            'total_positions_long' => 6,
            'total_positions_short' => 6,
            'position_leverage_long' => 20,
            'position_leverage_short' => 15,
            'margin_percentage_long' => 5,
            'margin_percentage_short' => 5,
        ]],
        'connectivityServers' => [],
        'isAdmin' => false,
    ])->render();
    $javascript = file_get_contents(resource_path('js/app.js'));

    expect($html)
        ->toContain('x-text="tradingHelper()"')
        ->toContain('Open positions keep running')
        ->toContain('Stop opening new positions')
        ->toContain('Subscription inactive')
        ->toContain("@click=\"cfg.pq = 'USDC'; open = false\"")
        ->toContain("@click=\"cfg.tq = 'USDC'; open = false\"")
        ->toContain('quotesLocked()')
        ->toContain('Closes a position after the price moves this percentage in your favor.')
        ->toContain('Closes a position after the price moves this percentage against you, limiting the loss.')
        ->toContain('Maximum long positions that can be open at the same time.')
        ->toContain('Maximum short positions that can be open at the same time.')
        ->toContain('Multiplies the size of each long position. A higher number means larger gains and losses.')
        ->toContain('Multiplies the size of each short position. A higher number means larger gains and losses.')
        ->toContain('Uses up to this percentage of the trading balance as margin for each new long position.')
        ->toContain('Uses up to this percentage of the trading balance as margin for each new short position.')
        ->toContain('Leverage &amp; margin')
        ->not->toContain('Leverage &amp;amp; margin');

    expect($javascript)
        ->toContain('Bot will continue managing open positions but will not open new ones.')
        ->toContain("return this.testedMode === 'replacement' ? 'Save connection' : 'Apply connection result';")
        ->toContain('quotesLocked()')
        ->toContain('Turn off Can trade to change this quote.');

    $dashboard = file_get_contents(resource_path('views/dashboard.blade.php'));
    expect($dashboard)
        ->toContain('account()?.is_trading')
        ->toContain('a.is_trading')
        ->not->toContain('account()?.can_trade')
        ->not->toContain('a.can_trade');
});
