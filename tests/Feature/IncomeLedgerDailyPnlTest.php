<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\ReportingDay;
use Kraite\Core\Support\Financial\Window;

/**
 * `positions.pnl` files a trade's whole result under the day it closed. The
 * exchange books each piece when it charged it — the opening commission on
 * the evening the position opened, funding overnight, the realized result on
 * close. For a position that spans midnight those are different days, which
 * is why Kraite read +11.51 against Binance's +9.83 on 2026-07-29.
 *
 * Daily figures are therefore built from the income ledger wherever it
 * reaches, and fall back to close-day grouping for older windows it does not
 * cover — so historical months keep the only figures we have for them.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-29 12:00:00'));

    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->decimal('pnl', 20, 8)->nullable();
        $table->timestamp('closed_at')->nullable();
    });
    Schema::create('account_incomes', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('tran_id');
        $table->string('income_type', 32);
        $table->string('symbol', 32)->nullable();
        $table->decimal('income', 20, 8);
        $table->string('asset', 16)->nullable();
        $table->dateTime('occurred_at');
        $table->timestamps();
    });
    Schema::create('account_balance_history', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->decimal('total_wallet_balance', 20, 8)->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    foreach (['account_balance_history', 'account_incomes', 'positions'] as $table) {
        Schema::dropIfExists($table);
    }

    $this->travelBack();
});

/**
 * An account whose income ledger the sync has declared complete since the
 * given instant. Null means no ledger yet, so every window falls back.
 */
function ledgerAccount(int $accountId = 801, ?string $syncedFrom = '2026-07-01 00:00:00'): Account
{
    $account = new Account;
    $account->id = $accountId;
    $account->incomes_synced_from = $syncedFrom;

    return $account;
}

function seedIncome(int $accountId, string $tranId, string $type, string $income, string $occurredAt): void
{
    DB::table('account_incomes')->insert([
        'account_id' => $accountId,
        'tran_id' => $tranId,
        'income_type' => $type,
        'symbol' => 'LINKUSDT',
        'income' => $income,
        'asset' => 'USDT',
        'occurred_at' => $occurredAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * One position opened on the 28th and closed on the 29th. Its commission was
 * charged on opening, its funding overnight, and its result on closing —
 * three days' worth of bookings that close-day grouping would pile onto the
 * 29th.
 */
function seedSpanningTrade(int $accountId = 801): void
{
    DB::table('positions')->insert([
        'account_id' => $accountId, 'status' => 'closed',
        'pnl' => 8, 'closed_at' => '2026-07-29 09:00:00',
    ]);

    seedIncome($accountId, '1001', 'COMMISSION', '-1.00000000', '2026-07-28 18:00:00');
    seedIncome($accountId, '1002', 'FUNDING_FEE', '-1.00000000', '2026-07-29 00:00:00');
    seedIncome($accountId, '1003', 'REALIZED_PNL', '10.00000000', '2026-07-29 09:00:00');
    seedIncome($accountId, '1004', 'COMMISSION', '-1.00000000', '2026-07-29 09:00:00');
}

it('books each fee and fill on the day the exchange charged it', function (): void {
    seedSpanningTrade();

    $window = Window::between(
        CarbonImmutable::parse('2026-07-28 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );

    // Close-day grouping would have put the whole +8 on the 29th. The opening
    // commission belongs to the 28th, where the exchange charged it.
    expect((new AccountFinancials(ledgerAccount()))->dailyRevenues($window))
        ->toBe([
            '2026-07-28' => '-1.00000000',
            '2026-07-29' => '8.00000000',
        ]);
});

it('counts a spanning trade under the trader day the exchange used', function (): void {
    seedSpanningTrade();

    $basis = new ReportingDay(120);
    $window = Window::between(
        CarbonImmutable::parse('2026-07-27 22:00:00'),
        CarbonImmutable::parse('2026-07-29 21:59:59'),
    );

    // On a UTC+2 basis the 00:00 UTC funding is already the 29th's 02:00.
    expect((new AccountFinancials(ledgerAccount(), $basis))->dailyRevenues($window))
        ->toBe([
            '2026-07-28' => '-1.00000000',
            '2026-07-29' => '8.00000000',
        ]);
});

it('ignores wallet movements that are not trading performance', function (): void {
    seedSpanningTrade();
    // A 1,000 deposit lands in the same ledger. It is not profit.
    seedIncome(801, '2001', 'TRANSFER', '1000.00000000', '2026-07-29 10:00:00');

    $window = Window::between(
        CarbonImmutable::parse('2026-07-29 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );

    expect((new AccountFinancials(ledgerAccount()))->realizedDelta($window))->toBe('8.00000000');
});

it('falls back to close-day grouping for windows the ledger never reached', function (): void {
    seedSpanningTrade();

    // June predates the ledger entirely; the only figure we have for it is
    // the position's own PnL, filed under its close date.
    DB::table('positions')->insert([
        'account_id' => 801, 'status' => 'closed',
        'pnl' => 5, 'closed_at' => '2026-06-15 10:00:00',
    ]);

    $june = Window::between(
        CarbonImmutable::parse('2026-06-01 00:00:00'),
        CarbonImmutable::parse('2026-06-30 23:59:59'),
    );

    expect((new AccountFinancials(ledgerAccount()))->dailyRevenues($june))
        ->toBe(['2026-06-15' => '5.00000000']);
});

it('uses the ledger for an account with no closed positions yet', function (): void {
    // Funding on a position still open is real money moved today, and the
    // exchange counts it today. Close-day grouping could not see it at all.
    seedIncome(802, '3001', 'FUNDING_FEE', '-0.25000000', '2026-07-29 08:00:00');

    $window = Window::between(
        CarbonImmutable::parse('2026-07-29 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );

    expect((new AccountFinancials(ledgerAccount(802)))->dailyRevenues($window))
        ->toBe(['2026-07-29' => '-0.25000000']);
});

it('keeps the percentage honest by dividing the ledger day by that day capital', function (): void {
    seedSpanningTrade();
    DB::table('account_balance_history')->insert([
        ['account_id' => 801, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-28 00:05:00', 'updated_at' => now()],
        ['account_id' => 801, 'total_wallet_balance' => 999, 'created_at' => '2026-07-28 23:55:00', 'updated_at' => now()],
    ]);

    $window = Window::between(
        CarbonImmutable::parse('2026-07-28 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );

    $rates = (new AccountFinancials(ledgerAccount()))->dailyPercentages($window);

    expect((float) $rates['2026-07-28'])->toEqualWithDelta(-0.001, 0.0000001)
        ->and((float) $rates['2026-07-29'])->toEqualWithDelta(8 / 999, 0.0000001);
});
