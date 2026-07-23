<?php

declare(strict_types=1);

use App\Services\BacktestStopLossRecency;

it('returns the newest stopped-out candle for each direction', function (): void {
    $latest = (new BacktestStopLossRecency)->latestByDirection([
        [
            'status' => 'stopped_out',
            'direction' => 'LONG',
            'sl_candle' => '2025-02-01 12:00:00',
        ],
        [
            'status' => 'stopped_out',
            'direction' => 'LONG',
            'sl_candle' => '2026-07-22 08:30:00',
        ],
        [
            'status' => 'stopped_out',
            'direction' => 'SHORT',
            'sl_candle' => '2026-06-15 20:45:00',
        ],
        [
            'status' => 'inconclusive',
            'direction' => 'SHORT',
            'sl_candle' => '2026-07-23 10:00:00',
        ],
        [
            'status' => 'stopped_out',
            'direction' => 'SHORT',
            'sl_candle' => 'not-a-date',
        ],
    ]);

    expect($latest)->toBe([
        'LONG' => '2026-07-22T08:30:00+00:00',
        'SHORT' => '2026-06-15T20:45:00+00:00',
    ]);
});

it('returns null when a direction has no stopped-out conclusion', function (): void {
    $latest = (new BacktestStopLossRecency)->latestByDirection([
        [
            'status' => 'inconclusive',
            'direction' => 'LONG',
            'sl_candle' => '2026-07-22 08:30:00',
        ],
    ]);

    expect($latest)->toBe([
        'LONG' => null,
        'SHORT' => null,
    ]);
});
