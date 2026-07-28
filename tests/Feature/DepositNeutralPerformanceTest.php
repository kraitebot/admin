<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Enums\ProjectionFormat;
use Kraite\Core\Enums\ProjectionScenario;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\ProjectionCompounder;
use Kraite\Core\Support\Financial\Window;

/**
 * Transferred money is not performance. Every percentage the products
 * publish — daily rates, scenario bands, window ROI, the dashboard chip —
 * must divide by the capital that actually did the trading, so paying
 * money in makes the euros bigger and leaves the rates untouched.
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

function depositAccount(int $accountId = 501): Account
{
    $account = new Account;
    $account->id = $accountId;

    return $account;
}

/**
 * Two flat trading days on 1,000, then 1,000 of personal money paid in
 * overnight and a day that earns double the euros on double the capital.
 */
function seedDepositTimeline(int $accountId = 501): void
{
    DB::table('account_balance_history')->insert([
        ['account_id' => $accountId, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-27 00:05:00', 'updated_at' => '2026-07-27 00:05:00'],
        ['account_id' => $accountId, 'total_wallet_balance' => 1010, 'created_at' => '2026-07-27 23:55:00', 'updated_at' => '2026-07-27 23:55:00'],
        ['account_id' => $accountId, 'total_wallet_balance' => 1010, 'created_at' => '2026-07-28 00:05:00', 'updated_at' => '2026-07-28 00:05:00'],
        // +10 traded, +1,000 transferred in.
        ['account_id' => $accountId, 'total_wallet_balance' => 2020, 'created_at' => '2026-07-28 23:55:00', 'updated_at' => '2026-07-28 23:55:00'],
        ['account_id' => $accountId, 'total_wallet_balance' => 2040, 'created_at' => '2026-07-29 11:00:00', 'updated_at' => '2026-07-29 11:00:00'],
    ]);

    DB::table('positions')->insert([
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 10, 'closed_at' => '2026-07-27 15:00:00'],
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 10, 'closed_at' => '2026-07-28 15:00:00'],
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 20, 'closed_at' => '2026-07-29 10:00:00'],
    ]);
}

function depositWindow(): Window
{
    return Window::between(
        CarbonImmutable::parse('2026-07-27 00:00:00'),
        CarbonImmutable::parse('2026-07-29 23:59:59'),
    );
}

it('anchors every day on the wallet that day opened with', function (): void {
    seedDepositTimeline();

    $anchors = (new AccountFinancials(depositAccount()))->dailyStartWallets(depositWindow());

    // Driver-agnostic: sqlite hands back '1000' where MySQL says '1000.00000000'.
    expect(array_map('floatval', $anchors))
        ->toBe([
            // First day in scope has nothing earlier to lean on.
            '2026-07-27' => 1000.0,
            // Yesterday's close, not the month's opening balance.
            '2026-07-28' => 1010.0,
            // Carries the transferred capital the moment it lands.
            '2026-07-29' => 2020.0,
        ]);
});

it('keeps the daily rate flat when doubled capital earns doubled euros', function (): void {
    seedDepositTimeline();

    $rates = (new AccountFinancials(depositAccount()))->dailyPercentages(depositWindow());

    // 20 on 2,020 is the same day's work as 10 on 1,010 — a stale
    // pre-transfer denominator would have printed 2% for the 29th.
    expect($rates['2026-07-29'])->toBe($rates['2026-07-28'])
        ->and((float) $rates['2026-07-29'])->toEqualWithDelta(0.00990099, 0.0000001)
        ->and((float) $rates['2026-07-27'])->toEqualWithDelta(0.01, 0.0000001);
});

it('never lets transferred money widen the optimistic scenario band', function (): void {
    seedDepositTimeline();

    $scenarios = (new AccountFinancials(depositAccount()))->scenarios(depositWindow());

    expect((float) $scenarios['optimistic_pct'])->toEqualWithDelta(0.01, 0.0000001)
        ->and((float) $scenarios['pessimistic_pct'])->toEqualWithDelta(0.00990099, 0.0000001)
        ->and($scenarios['days_observed'])->toBe(3);
});

it('reports window roi as the chained daily rates rather than a frozen denominator', function (): void {
    seedDepositTimeline();

    // 1.01 × 1.00990099 × 1.00990099 − 1. Dividing the 40 of realized PnL
    // by the 1,000 opening wallet would claim 4%, a third of which is the
    // transfer doing the work.
    expect((new AccountFinancials(depositAccount()))->realizedRoiPct(depositWindow()))
        ->toEqualWithDelta(3.0099, 0.001);
});

it('keeps the projected window return free of the money paid in', function (): void {
    seedDepositTimeline();

    // Window ends today, so there is nothing left to project and the answer
    // is the realized return alone. Measuring the grown wallet against the
    // window's opening balance would have claimed ~104% — almost all of it
    // the 1,000 that was simply transferred in.
    expect((new AccountFinancials(depositAccount()))->projected(
        scenario: ProjectionScenario::Neutral,
        window: depositWindow(),
    ))->toEqualWithDelta(3.0099, 0.001);
});

it('counts only traded euros in the projected window amount', function (): void {
    seedDepositTimeline();

    expect((new AccountFinancials(depositAccount()))->projected(
        scenario: ProjectionScenario::Neutral,
        window: depositWindow(),
        format: ProjectionFormat::Amount,
    ))->toBe('40.00000000');
});

it('survives a realized return small enough that PHP writes it in scientific notation', function (): void {
    // 0.000001% renders as "1.0E-6", which bcmath rejects — the projections
    // feed and the public stats endpoint would both 500 on a dust-sized day.
    expect(ProjectionCompounder::compute(
        scenarios: ['pessimistic_pct' => '0', 'neutral_pct' => '0', 'optimistic_pct' => '0', 'days_observed' => 1],
        currentWallet: '1000',
        realizedPct: 0.000001,
        realizedAmount: '0.00001000',
        scenario: ProjectionScenario::Neutral,
        window: depositWindow(),
        format: ProjectionFormat::Percentage,
    ))->toEqualWithDelta(0.000001, 0.0000001);
});

it('has no window roi to report when the window closed no positions', function (): void {
    seedDepositTimeline();

    expect((new AccountFinancials(depositAccount()))->realizedRoiPct(Window::between(
        CarbonImmutable::parse('2026-07-30 00:00:00'),
        CarbonImmutable::parse('2026-07-30 23:59:59'),
    )))->toBeNull();
});

it('reads the portfolio chip as trading performance, not as money paid in', function (): void {
    DB::table('account_balance_history')->insert([
        ['account_id' => 501, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-28 10:00:00', 'updated_at' => '2026-07-28 10:00:00'],
        // 1,000 transferred in plus 10 traded.
        ['account_id' => 501, 'total_wallet_balance' => 2010, 'created_at' => '2026-07-29 11:00:00', 'updated_at' => '2026-07-29 11:00:00'],
    ]);
    DB::table('positions')->insert([
        ['account_id' => 501, 'status' => 'closed', 'pnl' => 10, 'closed_at' => '2026-07-29 10:00:00'],
    ]);

    $kpis = dashboardKpis(depositAccount());

    expect($kpis['balance'])->toBe('2010.00')
        // Wallet drift would have shouted +101%. Only the traded 10 counts.
        ->and($kpis['balance_delta_24h_pct'])->toBe(1.0);
});

it('shows no portfolio chip on a day that only moved money around', function (): void {
    DB::table('account_balance_history')->insert([
        ['account_id' => 501, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-28 10:00:00', 'updated_at' => '2026-07-28 10:00:00'],
        ['account_id' => 501, 'total_wallet_balance' => 2000, 'created_at' => '2026-07-29 11:00:00', 'updated_at' => '2026-07-29 11:00:00'],
    ]);

    expect(dashboardKpis(depositAccount())['balance_delta_24h_pct'])->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function dashboardKpis(Account $account): array
{
    $method = new ReflectionMethod(DashboardController::class, 'kpis');

    return $method->invoke(new DashboardController, $account, []);
}
