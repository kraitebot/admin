<?php

declare(strict_types=1);

use Carbon\Carbon;
use Kraite\Core\Support\Backtest\BacktestHistoryWindow;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-23 00:15:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires historical fetching for the five-candle ETH regression', function (): void {
    $coverage = ['earliest' => '2026-07-18 00:00:00'];

    expect(BacktestHistoryWindow::covers($coverage, '1d', 24))->toBeFalse();
});

it('accepts history reaching the default requested month boundary', function (string $earliest): void {
    $coverage = ['earliest' => $earliest];

    expect(BacktestHistoryWindow::covers($coverage, '1d', 24))->toBeTrue();
})->with([
    'exact boundary' => '2024-07-01 00:00:00',
    'older history' => '2024-06-30 00:00:00',
]);

it('rejects missing history and unsupported timeframes', function (array $coverage, string $timeframe): void {
    expect(BacktestHistoryWindow::covers($coverage, $timeframe, 24))->toBeFalse();
})->with([
    'no earliest candle' => [[], '1d'],
    'unsupported timeframe' => [['earliest' => '2024-01-01 00:00:00'], '15m'],
]);

it('aligns an explicit requested start to the next timeframe candle', function (): void {
    $requestedSince = Carbon::parse('2026-01-01 12:30:00', 'UTC')->getTimestamp();

    expect(BacktestHistoryWindow::covers(
        ['earliest' => '2026-01-02 00:00:00'],
        '1d',
        24,
        $requestedSince,
    ))->toBeTrue()
        ->and(BacktestHistoryWindow::covers(
            ['earliest' => '2026-01-03 00:00:00'],
            '1d',
            24,
            $requestedSince,
        ))->toBeFalse();
});
