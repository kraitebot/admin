<?php

it('explains every trading setting and renders the leverage heading correctly', function (): void {
    $html = view('accounts.edit', [
        'accounts' => [[
            'id' => 42,
            'exchange' => 'Binance',
            'exchange_canonical' => 'binance',
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

    expect($html)
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
});
