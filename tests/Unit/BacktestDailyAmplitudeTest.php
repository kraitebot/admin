<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Kraite\Core\Models\Candle;
use Kraite\Core\Support\Backtest\BacktestDailyAmplitude;

function amplitudeCandles(array $rows): Collection
{
    return collect($rows)->map(function (array $row): Candle {
        $candle = new Candle;
        $candle->setRawAttributes($row);

        return $candle;
    });
}

it('finds the largest high-to-low amplitude within one UTC day', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-20 00:00:00', 'low' => '100', 'high' => '110'],
        ['candle_time_utc' => '2026-07-20 08:00:00', 'low' => '90', 'high' => '105'],
        ['candle_time_utc' => '2026-07-21 00:00:00', 'low' => '50', 'high' => '55'],
    ]));

    expect($result)->toBe([
        'percentage' => 22.222,
        'date' => '2026-07-20',
    ]);
});

it('does not combine extremes across UTC midnight', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-20 23:00:00', 'low' => '100', 'high' => '150'],
        ['candle_time_utc' => '2026-07-21 00:00:00', 'low' => '10', 'high' => '11'],
    ]));

    expect($result)->toBe([
        'percentage' => 50.0,
        'date' => '2026-07-20',
    ]);
});

it('ignores invalid ranges and returns an empty result without valid candles', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-20 00:00:00', 'low' => '0', 'high' => '10'],
        ['candle_time_utc' => '2026-07-20 01:00:00', 'low' => '12', 'high' => '11'],
        ['candle_time_utc' => null, 'low' => '10', 'high' => '12'],
    ]));

    expect($result)->toBe([
        'percentage' => 0.0,
        'date' => null,
    ]);
});
