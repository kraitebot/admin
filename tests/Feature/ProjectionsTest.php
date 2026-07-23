<?php

declare(strict_types=1);

use App\Models\User;
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
