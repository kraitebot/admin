<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\FleetFinancials;
use Kraite\Core\Support\Financial\ReportingDay;
use Kraite\Core\Support\Financial\Window;

/**
 * Bruno's Binance account resets its day at UTC+2 (2026-07-29), so at 23:38
 * UTC the exchange had already been counting "today" for 1h38m while Kraite
 * was still totalling the whole UTC day — $4.72 on our screen against a
 * fraction of that on Binance's.
 *
 * Timestamps stay UTC in the database. Only the question "which day does
 * this trade belong to" follows the trader's basis.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-28 23:38:00'));

    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->decimal('pnl', 20, 8)->nullable();
        $table->decimal('profit_percentage', 8, 3)->nullable();
        $table->timestamp('closed_at')->nullable();
    });
    Schema::create('account_balance_history', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->decimal('total_wallet_balance', 20, 8)->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    foreach (['account_balance_history', 'positions'] as $table) {
        Schema::dropIfExists($table);
    }

    $this->travelBack();
});

/**
 * Two closes on the UTC 28th: one at midday (still "yesterday" for a UTC+2
 * trader) and one at 22:30 UTC (already the 29th on that basis).
 */
function seedDayBasisTimeline(int $accountId = 701): void
{
    DB::table('account_balance_history')->insert([
        ['account_id' => $accountId, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-27 21:30:00', 'updated_at' => '2026-07-27 21:30:00'],
        ['account_id' => $accountId, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-28 00:10:00', 'updated_at' => '2026-07-28 00:10:00'],
        ['account_id' => $accountId, 'total_wallet_balance' => 1040, 'created_at' => '2026-07-28 23:00:00', 'updated_at' => '2026-07-28 23:00:00'],
    ]);

    DB::table('positions')->insert([
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 30, 'profit_percentage' => 3, 'closed_at' => '2026-07-28 12:00:00'],
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 10, 'profit_percentage' => 1, 'closed_at' => '2026-07-28 22:30:00'],
    ]);
}

/** Bare account stub; the basis is passed to the calculator explicitly. */
function dayBasisAccount(int $accountId = 701): Account
{
    $account = new Account;
    $account->id = $accountId;

    return $account;
}

it('counts a whole UTC day for a trader on the UTC basis', function (): void {
    seedDayBasisTimeline();

    $financials = new AccountFinancials(dayBasisAccount(), new ReportingDay(0));
    $today = Window::today(null, new ReportingDay(0));

    expect($today->start->toDateTimeString())->toBe('2026-07-28 00:00:00')
        ->and($financials->realizedDelta($today))->toBe('40.00000000');
});

it('counts only the trades since the exchange rolled the day for a UTC+2 trader', function (): void {
    seedDayBasisTimeline();

    $basis = new ReportingDay(120);
    $financials = new AccountFinancials(dayBasisAccount(), $basis);
    $today = Window::today(null, $basis);

    // Their 29th opened at 22:00 UTC, so the midday +30 belongs to yesterday.
    expect($today->start->toDateTimeString())->toBe('2026-07-28 22:00:00')
        ->and($today->end->toDateTimeString())->toBe('2026-07-29 21:59:59')
        ->and($financials->realizedDelta($today))->toBe('10.00000000');
});

it('files each close under the trader calendar day rather than the UTC one', function (): void {
    seedDayBasisTimeline();

    $basis = new ReportingDay(120);
    $window = Window::between(
        CarbonImmutable::parse('2026-07-27 22:00:00'),
        CarbonImmutable::parse('2026-07-29 21:59:59'),
    );

    $revenues = (new AccountFinancials(dayBasisAccount(), $basis))->dailyRevenues($window);

    expect(array_keys($revenues))->toBe(['2026-07-28', '2026-07-29'])
        ->and($revenues['2026-07-28'])->toBe('30.00000000')
        ->and($revenues['2026-07-29'])->toBe('10.00000000');
});

it('anchors each day on the wallet the trader opened that day with, on their basis', function (): void {
    seedDayBasisTimeline();

    $basis = new ReportingDay(120);
    $window = Window::between(
        CarbonImmutable::parse('2026-07-27 22:00:00'),
        CarbonImmutable::parse('2026-07-29 21:59:59'),
    );

    // The 1040 snapshot is stamped 23:00 UTC, which is already 01:00 on the
    // trader's 29th — an hour INTO that day, so it cannot be its opening.
    // Their 29th opens on what the 28th closed with.
    expect(array_map('floatval', (new AccountFinancials(dayBasisAccount(), $basis))->dailyStartWallets($window)))
        ->toBe([
            '2026-07-28' => 1000.0,
            '2026-07-29' => 1000.0,
        ]);
});

it('keeps the trader final day when UTC has not reached that date yet', function (): void {
    seedDayBasisTimeline(706);

    $basis = new ReportingDay(120);
    // Ends 23:00 UTC on the 28th — already 01:00 on the trader's 29th. Walking
    // UTC dates would stop at the 28th and lose the day the 22:30 close is on.
    $window = Window::between(
        CarbonImmutable::parse('2026-07-28 00:00:00'),
        CarbonImmutable::parse('2026-07-28 23:00:00'),
    );

    $financials = new AccountFinancials(dayBasisAccount(706), $basis);

    expect(array_keys($financials->dailyRevenues($window)))->toContain('2026-07-29')
        ->and(array_keys($financials->dailyStartWallets($window)))->toContain('2026-07-29')
        ->and($financials->dailyPercentages($window))->toHaveKey('2026-07-29');
});

it('keeps the month window on the trader basis when UTC has not turned the month yet', function (): void {
    $basis = new ReportingDay(120);
    $atMonthEdge = CarbonImmutable::parse('2026-07-31 22:30:00');

    $window = Window::thisMonth($atMonthEdge, $basis);

    expect($window->start->toDateTimeString())->toBe('2026-07-31 22:00:00')
        ->and($window->end->toDateTimeString())->toBe('2026-08-31 21:59:59');
});

it('defaults every window to UTC so nothing changes for a trader who never set a basis', function (): void {
    $at = CarbonImmutable::parse('2026-07-28 23:38:00');

    expect(Window::today($at)->start->toDateTimeString())->toBe('2026-07-28 00:00:00')
        ->and(Window::thisMonth($at)->start->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and(Window::lastDays(2, $at)->start->toDateTimeString())->toBe('2026-07-27 00:00:00');
});

it('reads the basis from the account owner when the caller does not name one', function (): void {
    seedDayBasisTimeline(702);

    $owner = User::factory()->create(['utc_offset_minutes' => 120]);
    $account = new Account;
    $account->id = 702;
    $account->user_id = $owner->id;

    $financials = new AccountFinancials($account);

    expect($financials->reportingDay()->offsetMinutes)->toBe(120)
        ->and($financials->realizedDelta(Window::today(null, $financials->reportingDay())))
        ->toBe('10.00000000');
});

it('falls back to UTC when an account has no owner on record', function (): void {
    seedDayBasisTimeline(703);

    $account = new Account;
    $account->id = 703;

    expect((new AccountFinancials($account))->reportingDay()->offsetMinutes)->toBe(0);
});

it('reports fleet-wide days on the basis the fleet was built with', function (): void {
    seedDayBasisTimeline(704);

    $account = new Account;
    $account->id = 704;
    $fleet = new FleetFinancials(collect([$account]), new ReportingDay(120));

    $window = Window::between(
        CarbonImmutable::parse('2026-07-27 22:00:00'),
        CarbonImmutable::parse('2026-07-29 21:59:59'),
    );

    expect(array_keys($fleet->dailyRevenues($window)))->toBe(['2026-07-28', '2026-07-29'])
        ->and(array_keys($fleet->dailyStartWallets($window)))->toBe(['2026-07-28', '2026-07-29'])
        ->and($fleet->daysInProfit($window))->toBe(['green' => 2, 'observed' => 2]);
});

it('leaves the fleet on UTC when no basis is given, for public stats with no trader behind them', function (): void {
    seedDayBasisTimeline(705);

    $account = new Account;
    $account->id = 705;
    $fleet = new FleetFinancials(collect([$account]));

    $window = Window::between(
        CarbonImmutable::parse('2026-07-27 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );

    // Both closes land on the UTC 28th when nobody's basis applies.
    expect(array_keys($fleet->dailyRevenues($window)))->toBe(['2026-07-28'])
        ->and($fleet->dailyRevenues($window)['2026-07-28'])->toBe('40.00000000');
});
