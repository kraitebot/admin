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

it('matches the Binance daily range percentage using the previous daily close', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        [
            'candle_time_utc' => '2025-11-12 00:00:00',
            'low' => '0.075180',
            'high' => '0.085340',
            'close' => '0.078530',
        ],
        [
            'candle_time_utc' => '2025-11-13 00:00:00',
            'low' => '0.049000',
            'high' => '0.156790',
            'close' => '0.054290',
        ],
    ]));

    expect($result)->toBe([
        'percentage' => 137.25,
        'date' => '2025-11-13',
    ]);
});

it('uses the final close from the previous UTC day', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-20 23:00:00', 'low' => '90', 'high' => '210', 'close' => '100'],
        ['candle_time_utc' => '2026-07-20 00:00:00', 'low' => '190', 'high' => '210', 'close' => '200'],
        ['candle_time_utc' => '2026-07-21 00:00:00', 'low' => '100', 'high' => '150', 'close' => '120'],
    ]));

    expect($result)->toBe([
        'percentage' => 50.0,
        'date' => '2026-07-21',
    ]);
});

it('finds the largest high-to-low amplitude within one UTC day', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-19 00:00:00', 'low' => '99', 'high' => '101', 'close' => '100'],
        ['candle_time_utc' => '2026-07-20 00:00:00', 'low' => '100', 'high' => '110', 'close' => '105'],
        ['candle_time_utc' => '2026-07-20 08:00:00', 'low' => '90', 'high' => '105', 'close' => '100'],
        ['candle_time_utc' => '2026-07-21 00:00:00', 'low' => '50', 'high' => '55', 'close' => '52'],
    ]));

    expect($result)->toBe([
        'percentage' => 20.0,
        'date' => '2026-07-20',
    ]);
});

it('does not combine extremes across UTC midnight', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-19 23:00:00', 'low' => '99', 'high' => '101', 'close' => '100'],
        ['candle_time_utc' => '2026-07-20 23:00:00', 'low' => '100', 'high' => '150', 'close' => '100'],
        ['candle_time_utc' => '2026-07-21 00:00:00', 'low' => '10', 'high' => '11', 'close' => '10'],
    ]));

    expect($result)->toBe([
        'percentage' => 50.0,
        'date' => '2026-07-20',
    ]);
});

it('ignores invalid ranges and returns an empty result without valid candles', function (): void {
    $result = (new BacktestDailyAmplitude)->calculate(amplitudeCandles([
        ['candle_time_utc' => '2026-07-20 00:00:00', 'low' => '0', 'high' => '10', 'close' => '5'],
        ['candle_time_utc' => '2026-07-20 01:00:00', 'low' => '12', 'high' => '11', 'close' => '11'],
        ['candle_time_utc' => null, 'low' => '10', 'high' => '12', 'close' => '11'],
    ]));

    expect($result)->toBe([
        'percentage' => 0.0,
        'date' => null,
    ]);
});
