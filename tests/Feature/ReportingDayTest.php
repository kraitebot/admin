<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Support\Financial\ReportingDay;

/**
 * A trading day starts when the trader's exchange says it starts. Binance
 * calls it "Change Basis" and stores a fixed UTC offset; Kraite mirrors that
 * so "today" means the same span of hours on both screens.
 *
 * Stored timestamps stay UTC everywhere — this only decides which calendar
 * day a UTC instant is reported under.
 */
it('keeps UTC as the day basis when no offset is configured', function (): void {
    $day = new ReportingDay(0);

    expect($day->offsetMinutes)->toBe(0)
        ->and($day->label())->toBe('UTC+00:00')
        ->and($day->dateOf(CarbonImmutable::parse('2026-07-28 23:38:00')))->toBe('2026-07-28');
});

it('reports a late-evening UTC instant as the next day for an ahead-of-UTC trader', function (): void {
    // 23:38 UTC is already 01:38 the next morning in Zurich's summer basis,
    // which is exactly why Binance and the dashboard disagreed on "today".
    expect((new ReportingDay(120))->dateOf(CarbonImmutable::parse('2026-07-28 23:38:00')))
        ->toBe('2026-07-29');
});

it('reports an early-morning UTC instant as the previous day for a behind-UTC trader', function (): void {
    expect((new ReportingDay(-300))->dateOf(CarbonImmutable::parse('2026-07-29 02:15:00')))
        ->toBe('2026-07-28');
});

it('carries half-hour and three-quarter-hour bases', function (): void {
    expect((new ReportingDay(330))->label())->toBe('UTC+05:30')
        ->and((new ReportingDay(345))->label())->toBe('UTC+05:45')
        ->and((new ReportingDay(-210))->label())->toBe('UTC-03:30')
        ->and((new ReportingDay(330))->dateOf(CarbonImmutable::parse('2026-07-28 19:00:00')))->toBe('2026-07-29');
});

it('refuses a basis outside the range real exchanges offer', function (int $minutes): void {
    expect(fn () => new ReportingDay($minutes))->toThrow(InvalidArgumentException::class);
})->with([
    'further behind than UTC-12' => -721,
    'further ahead than UTC+14' => 841,
    'not a whole quarter hour' => 37,
]);

it('turns a UTC instant into the trader day it belongs to and back', function (): void {
    $day = new ReportingDay(120);
    $at = CarbonImmutable::parse('2026-07-28 23:38:00');

    // The trader's 29th began at 22:00 UTC on the 28th and ends a whole day later.
    expect($day->startOfDayUtc($at)->toDateTimeString())->toBe('2026-07-28 22:00:00')
        ->and($day->endOfDayUtc($at)->toDateTimeString())->toBe('2026-07-29 21:59:59');
});

it('turns a UTC instant into the trader month it belongs to', function (): void {
    $day = new ReportingDay(120);
    // 31 July 22:30 UTC is already 1 August for this trader — a month boundary
    // the UTC clock has not reached yet.
    $at = CarbonImmutable::parse('2026-07-31 22:30:00');

    expect($day->startOfMonthUtc($at)->toDateTimeString())->toBe('2026-07-31 22:00:00')
        ->and($day->endOfMonthUtc($at)->toDateTimeString())->toBe('2026-08-31 21:59:59');
});

it('shifts the grouping column by the basis on sqlite', function (): void {
    expect((new ReportingDay(120))->dateExpression('closed_at', 'sqlite'))
        ->toBe("DATE(datetime(closed_at, '+120 minutes'))")
        ->and((new ReportingDay(-300))->dateExpression('closed_at', 'sqlite'))
        ->toBe("DATE(datetime(closed_at, '-300 minutes'))");
});

it('shifts the grouping column by the basis on mysql', function (): void {
    expect((new ReportingDay(120))->dateExpression('closed_at', 'mysql'))
        ->toBe('DATE(DATE_ADD(closed_at, INTERVAL 120 MINUTE))')
        ->and((new ReportingDay(-300))->dateExpression('closed_at', 'mysql'))
        ->toBe('DATE(DATE_ADD(closed_at, INTERVAL -300 MINUTE))');
});

it('leaves the column untouched when the basis is UTC', function (string $driver): void {
    expect((new ReportingDay(0))->dateExpression('closed_at', $driver))->toBe('DATE(closed_at)');
})->with(['sqlite', 'mysql']);

it('offers every basis a real exchange exposes, west to east', function (): void {
    $offsets = ReportingDay::selectableOffsets();

    expect(array_keys($offsets))->toBe(array_values(array_unique(array_keys($offsets))))
        ->and(array_keys($offsets))->toBe(collect(array_keys($offsets))->sort()->values()->all())
        ->and($offsets[0])->toBe('UTC+00:00')
        ->and($offsets[120])->toBe('UTC+02:00')
        ->and($offsets[330])->toBe('UTC+05:30')
        ->and($offsets[345])->toBe('UTC+05:45')
        ->and($offsets)->toHaveKey(-720)
        ->and($offsets)->toHaveKey(840);

    // Anything the picker offers must be a basis the value object accepts.
    foreach (array_keys($offsets) as $minutes) {
        expect((new ReportingDay($minutes))->offsetMinutes)->toBe($minutes);
    }
});

it('never lets a caller smuggle SQL through the column name', function (): void {
    expect(fn () => (new ReportingDay(120))->dateExpression('closed_at); DROP TABLE positions; --', 'mysql'))
        ->toThrow(InvalidArgumentException::class);
});
