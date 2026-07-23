<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\YearlyProjectionPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;

/**
 * Projections feed — realized daily revenue + observed daily-rate scenarios
 * per account, owner-scoped. Pins the payload shape the calendar consumes
 * and the ownership gate.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-23 12:00:00'));

    Schema::create('api_systems', function (Blueprint $t): void {
        $t->id();
        $t->string('name')->nullable();
    });
    Schema::create('accounts', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('api_system_id');
        $t->string('name')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('positions', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->string('status');
        $t->decimal('pnl', 20, 8)->nullable();
        $t->timestamp('closed_at')->nullable();
        $t->timestamps();
    });
    Schema::create('account_balance_history', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->decimal('total_wallet_balance', 20, 5)->default(0);
        $t->timestamps();
    });
});

afterEach(function (): void {
    foreach (['account_balance_history', 'positions', 'accounts', 'api_systems'] as $table) {
        Schema::dropIfExists($table);
    }

    $this->travelBack();
});

function seedProjectionAccount(): array
{
    $owner = User::factory()->create();
    $api = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id, 'api_system_id' => $api, 'name' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Wallet snapshots: month open 1000, latest 1010.
    DB::table('account_balance_history')->insert([
        ['account_id' => $accountId, 'total_wallet_balance' => 1000, 'created_at' => now()->startOfMonth()->addHour(), 'updated_at' => now()],
        ['account_id' => $accountId, 'total_wallet_balance' => 1010, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // One clean close today: +10 realized.
    DB::table('positions')->insert([
        'account_id' => $accountId, 'status' => 'closed', 'pnl' => 10,
        'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$owner->id, $accountId];
}

function seedAdditionalProjectionAccount(
    int $userId,
    string $startWallet,
    string $currentWallet,
    string $pnl,
): int {
    $api = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'api_system_id' => $api,
        'name' => 'Additional',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('account_balance_history')->insert([
        [
            'account_id' => $accountId,
            'total_wallet_balance' => $startWallet,
            'created_at' => now()->startOfMonth()->addHour(),
            'updated_at' => now(),
        ],
        [
            'account_id' => $accountId,
            'total_wallet_balance' => $currentWallet,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => $pnl,
        'closed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $accountId;
}

it('serves the month feed with realized actuals and observed scenarios', function (): void {
    [$userId, $accountId] = seedProjectionAccount();

    $response = $this->actingAs(User::findOrFail($userId))
        ->get('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful();

    $response->assertJsonPath('account_id', $accountId)
        ->assertJsonPath('today', now()->toDateString())
        ->assertJsonPath('actuals.'.now()->toDateString(), '10.0000')
        ->assertJsonPath('scenarios.days_observed', 1)
        ->assertJsonPath('investment_basis.amount', '1000.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '10.0000')
        ->assertJsonPath('investment_basis.closed_positions', 1)
        ->assertJsonPath('investment_basis.missing_pnl_positions', 0)
        ->assertJsonPath('investment_basis.is_complete', true);

    // One observed day → all three scenarios collapse onto its daily rate (1%).
    expect((float) $response->json('scenarios.neutral_pct'))->toEqualWithDelta(0.01, 0.0001)
        ->and((float) $response->json('current_wallet'))->toBe(1010.0)
        ->and((float) $response->json('month_start_wallet'))->toBe(1000.0);
});

it('automatically adjusts the investment basis for money movements not explained by trading pnl', function (): void {
    [$userId, $accountId] = seedProjectionAccount();
    $user = User::findOrFail($userId);

    $initialResponse = $this->actingAs($user)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '1000.0000');

    expect($initialResponse->json('investment_basis.amount'))->toBe('1000.0000');

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => 100,
        'closed_at' => now()->addMinute(),
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);
    DB::table('account_balance_history')->insert([
        'account_id' => $accountId,
        'total_wallet_balance' => 1310,
        'created_at' => now()->addMinutes(2),
        'updated_at' => now()->addMinutes(2),
    ]);

    $afterDeposit = $this->actingAs($user)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '1200.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '110.0000');

    expect($afterDeposit->json('investment_basis.amount'))->toBe('1200.0000');

    DB::table('account_balance_history')->insert([
        'account_id' => $accountId,
        'total_wallet_balance' => 1110,
        'created_at' => now()->addMinutes(3),
        'updated_at' => now()->addMinutes(3),
    ]);

    $afterWithdrawal = $this->actingAs($user)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '1000.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '110.0000');

    expect($afterWithdrawal->json('investment_basis.amount'))->toBe('1000.0000');
});

it('marks the automatic investment basis as incomplete when a tracked close has no exchange pnl', function (): void {
    [$userId, $accountId] = seedProjectionAccount();

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => null,
        'closed_at' => now()->addMinute(),
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);
    DB::table('account_balance_history')->insert([
        'account_id' => $accountId,
        'total_wallet_balance' => 1010,
        'created_at' => now()->addMinutes(2),
        'updated_at' => now()->addMinutes(2),
    ]);

    $this->actingAs(User::findOrFail($userId))
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '1000.0000')
        ->assertJsonPath('investment_basis.closed_positions', 2)
        ->assertJsonPath('investment_basis.missing_pnl_positions', 1)
        ->assertJsonPath('investment_basis.is_complete', false);
});

it('waits for a wallet snapshot before including newer closed-position pnl', function (): void {
    [$userId, $accountId] = seedProjectionAccount();

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => 100,
        'closed_at' => now()->addMinute(),
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $this->actingAs(User::findOrFail($userId))
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('current_wallet', '1010')
        ->assertJsonPath('investment_basis.amount', '1000.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '10.0000')
        ->assertJsonPath('investment_basis.closed_positions', 1);
});

it('uses snapshot time rather than insertion order for the current wallet', function (): void {
    [, $accountId] = seedProjectionAccount();

    DB::table('account_balance_history')->insert([
        'account_id' => $accountId,
        'total_wallet_balance' => 900,
        'created_at' => now()->subMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $financials = new AccountFinancials(Account::findOrFail($accountId));

    expect($financials->currentWallet())->toBe('1010');
});

it('ignores pnl before wallet tracking and clamps fully recovered personal capital to zero', function (): void {
    [$userId, $accountId] = seedProjectionAccount();
    $user = User::findOrFail($userId);

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => 50,
        'closed_at' => now()->startOfMonth(),
        'created_at' => now()->startOfMonth(),
        'updated_at' => now()->startOfMonth(),
    ]);

    $this->actingAs($user)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '1000.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '10.0000')
        ->assertJsonPath('investment_basis.closed_positions', 1);

    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'closed',
        'pnl' => 200,
        'closed_at' => now()->addMinute(),
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);
    DB::table('account_balance_history')->insert([
        'account_id' => $accountId,
        'total_wallet_balance' => 100,
        'created_at' => now()->addMinutes(2),
        'updated_at' => now()->addMinutes(2),
    ]);

    $this->actingAs($user)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('investment_basis.amount', '0.0000')
        ->assertJsonPath('investment_basis.known_realized_pnl', '210.0000')
        ->assertJsonPath('investment_basis.closed_positions', 2);
});

it('returns no automatic investment basis when the account has no wallet history', function (): void {
    $owner = User::factory()->create();
    $api = DB::table('api_systems')->insertGetId(['name' => 'Bitget']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $api,
        'name' => 'No Wallet History',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($owner)
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year='.now()->year.'&month='.now()->month)
        ->assertSuccessful()
        ->assertJsonPath('current_wallet', null)
        ->assertJsonPath('investment_basis.amount', null)
        ->assertJsonPath('investment_basis.closed_positions', 0)
        ->assertJsonPath('investment_basis.is_complete', false);
});

it('renders the automatic milestone and additional investment simulation', function (): void {
    [$userId] = seedProjectionAccount();

    $this->actingAs(User::findOrFail($userId))
        ->get('https://admin.kraite.test/projections')
        ->assertSuccessful()
        ->assertSeeText('Profit-funded milestone')
        ->assertSeeText('Net personal investment')
        ->assertSeeText('Additional investment · what-if')
        ->assertSeeText('Profit-funded target')
        ->assertSee('x-model="additionalInvestment"', false)
        ->assertSee('milestoneRows()', false);
});

it('scopes the feed to the account owner', function (): void {
    [, $accountId] = seedProjectionAccount();
    $intruder = User::factory()->create(['is_admin' => false, 'email' => 'proj-intruder@kraite.test']);

    $this->actingAs($intruder)
        ->get('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year=2026&month=7')
        ->assertNotFound();
});

it('rejects an out-of-range month', function (): void {
    [$userId, $accountId] = seedProjectionAccount();

    $this->actingAs(User::findOrFail($userId))
        ->getJson('https://admin.kraite.test/projections/data?account_id='.$accountId.'&year=2026&month=13')
        ->assertStatus(422);
});

it('serves a five-year global outlook from the owners combined portfolio', function (): void {
    [$userId] = seedProjectionAccount();
    seedAdditionalProjectionAccount(
        userId: $userId,
        startWallet: '2000',
        currentWallet: '2020',
        pnl: '20',
    );

    $response = $this->actingAs(User::findOrFail($userId))
        ->getJson('https://admin.kraite.test/projections/yearly/data')
        ->assertSuccessful()
        ->assertJsonPath('account_count', 2)
        ->assertJsonPath('current_wallet', '3030.00000000')
        ->assertJsonPath('days_observed', 1)
        ->assertJsonPath('today', '2026-07-23')
        ->assertJsonPath('outlook.years', 5)
        ->assertJsonPath('outlook.scenarios.pessimistic.daily_pct', '0.01000000')
        ->assertJsonPath('outlook.scenarios.neutral.daily_pct', '0.01000000')
        ->assertJsonPath('outlook.scenarios.optimistic.daily_pct', '0.01000000')
        ->assertJsonPath('outlook.scenarios.neutral.available', true)
        ->assertJsonPath('outlook.scenarios.neutral.milestones.0.label', 'Until end of year')
        ->assertJsonPath('outlook.scenarios.neutral.milestones.0.end_date', '2026-12-31')
        ->assertJsonPath('outlook.scenarios.neutral.milestones.4.label', '2030 end')
        ->assertJsonPath('outlook.scenarios.neutral.milestones.4.end_date', '2030-12-31');

    expect($response->json('outlook.scenarios.neutral.milestones'))
        ->toHaveCount(5)
        ->and(array_column($response->json('outlook.scenarios.neutral.milestones'), 'year'))
        ->toBe([2026, 2027, 2028, 2029, 2030]);
});

it('compounds every yearly scenario exactly without floating point drift', function (): void {
    $outlook = app(YearlyProjectionPlanner::class)->plan(
        currentWallet: '1000',
        scenarios: [
            'pessimistic_pct' => '-0.01',
            'neutral_pct' => '0',
            'optimistic_pct' => '0.01',
            'days_observed' => 2,
        ],
        now: CarbonImmutable::parse('2026-12-29 12:00:00'),
        years: 1,
    );

    expect($outlook['scenarios']['pessimistic']['milestones'][0])
        ->toMatchArray([
            'end_wallet' => '980.10000000',
            'projected_profit' => '-19.90000000',
            'growth_pct' => '-1.99000000',
            'multiple' => '0.98010000',
        ])
        ->and($outlook['scenarios']['neutral']['milestones'][0])
        ->toMatchArray([
            'end_wallet' => '1000.00000000',
            'projected_profit' => '0.00000000',
            'growth_pct' => '0.00000000',
            'multiple' => '1.00000000',
        ])
        ->and($outlook['scenarios']['optimistic']['milestones'][0])
        ->toMatchArray([
            'end_wallet' => '1020.10000000',
            'projected_profit' => '20.10000000',
            'growth_pct' => '2.01000000',
            'multiple' => '1.02010000',
        ]);
});

it('does not include another traders accounts in the yearly outlook', function (): void {
    [$userId] = seedProjectionAccount();
    $otherTrader = User::factory()->create();
    seedAdditionalProjectionAccount(
        userId: $otherTrader->id,
        startWallet: '9000',
        currentWallet: '9900',
        pnl: '900',
    );

    $this->actingAs(User::findOrFail($userId))
        ->getJson('https://admin.kraite.test/projections/yearly/data')
        ->assertSuccessful()
        ->assertJsonPath('account_count', 1)
        ->assertJsonPath('current_wallet', '1010.00000000')
        ->assertJsonPath('outlook.scenarios.neutral.daily_pct', '0.01000000');
});

it('marks yearly scenarios unavailable when their inputs cannot be projected', function (): void {
    $planner = app(YearlyProjectionPlanner::class);
    $now = CarbonImmutable::parse('2026-07-23 12:00:00');

    $withoutWallet = $planner->plan(
        currentWallet: null,
        scenarios: [
            'pessimistic_pct' => '0.01',
            'neutral_pct' => '0.01',
            'optimistic_pct' => '0.01',
            'days_observed' => 1,
        ],
        now: $now,
    );
    $withoutObservations = $planner->plan(
        currentWallet: '1000',
        scenarios: [
            'pessimistic_pct' => null,
            'neutral_pct' => null,
            'optimistic_pct' => null,
            'days_observed' => 0,
        ],
        now: $now,
    );
    $invalidRate = $planner->plan(
        currentWallet: '1000',
        scenarios: [
            'pessimistic_pct' => '-1',
            'neutral_pct' => '-1',
            'optimistic_pct' => '-1',
            'days_observed' => 1,
        ],
        now: $now,
    );

    expect($withoutWallet['scenarios']['neutral'])
        ->toMatchArray(['available' => false, 'reason' => 'no_wallet'])
        ->and($withoutObservations['scenarios']['neutral'])
        ->toMatchArray(['available' => false, 'reason' => 'no_observations'])
        ->and($invalidRate['scenarios']['neutral'])
        ->toMatchArray(['available' => false, 'reason' => 'invalid_rate'])
        ->and($invalidRate['scenarios']['neutral']['milestones'][0]['end_wallet'])
        ->toBeNull();
});

it('renders the yearly outlook and nested monthly and yearly navigation', function (): void {
    [$userId] = seedProjectionAccount();

    $this->actingAs(User::findOrFail($userId))
        ->get('https://admin.kraite.test/projections/yearly')
        ->assertSuccessful()
        ->assertSeeText('Yearly projections')
        ->assertSeeText('Five-year capital horizon')
        ->assertSeeText('Monthly')
        ->assertSeeText('Yearly')
        ->assertSee('data-id="projections-monthly"', false)
        ->assertSee('data-id="projections-yearly"', false)
        ->assertSee(route('projections.yearly.data'), false);
});
