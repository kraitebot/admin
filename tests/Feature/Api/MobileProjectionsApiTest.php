<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-24 12:00:00'));

    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->morphs('tokenable');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
        $table->timestamps();
    });
    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id');
        $table->string('name');
        $table->boolean('is_active')->default(false);
        $table->boolean('can_trade')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->decimal('pnl', 20, 8)->nullable();
        $table->dateTime('closed_at')->nullable();
        $table->timestamps();
    });
    Schema::create('account_balance_history', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->decimal('total_wallet_balance', 20, 8);
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('account_balance_history');
    Schema::dropIfExists('positions');
    Schema::dropIfExists('accounts');
    Schema::dropIfExists('api_systems');
    Schema::dropIfExists('personal_access_tokens');
    $this->travelBack();
});

function createMobileProjectionAccount(
    User $owner,
    string $name,
    string $startWallet,
    string $currentWallet,
    string $pnl,
): int {
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => $name,
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

it('requires the read ability and validates projection input', function (): void {
    Sanctum::actingAs(User::factory()->create([
        'name' => 'Projection ability trader',
        'email' => 'projection-ability@kraite.test',
    ]), ['profile:read']);

    $this->getJson('https://api.kraite.com/v1/projections')
        ->assertForbidden();

    Sanctum::actingAs(User::factory()->create([
        'name' => 'Projection validation trader',
        'email' => 'projection-validation@kraite.test',
    ]), ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/projections?month=13&year=1999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month', 'year']);

    $this->getJson('https://api.kraite.com/v1/projections?year=2026')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
});

it('returns owner-scoped calendar and exact five-year scenario projections', function (): void {
    $owner = User::factory()->create([
        'name' => 'Projection portfolio owner',
        'email' => 'projection-owner@kraite.test',
    ]);
    $intruder = User::factory()->create([
        'name' => 'Projection portfolio intruder',
        'email' => 'projection-intruder@kraite.test',
    ]);
    $selectedAccountId = createMobileProjectionAccount($owner, 'Primary', '1000', '1010', '10');
    createMobileProjectionAccount($owner, 'Secondary', '2000', '2020', '20');
    $foreignAccountId = createMobileProjectionAccount($intruder, 'Foreign', '9000', '9900', '900');
    $positionsBefore = DB::table('positions')->orderBy('id')->get()->all();
    $balancesBefore = DB::table('account_balance_history')->orderBy('id')->get()->all();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $response = $this->getJson(
        'https://api.kraite.com/v1/projections?account_id='.$selectedAccountId.'&year=2026&month=7',
    )
        ->assertOk()
        ->assertJsonCount(2, 'data.accounts')
        ->assertJsonPath('data.selected_account_id', $selectedAccountId)
        ->assertJsonPath('data.calendar.account_id', $selectedAccountId)
        ->assertJsonPath('data.calendar.actuals.2026-07-24', '10.0000')
        ->assertJsonPath('data.calendar.current_wallet', '1010')
        ->assertJsonPath('data.calendar.month_start_wallet', '1000')
        ->assertJsonPath('data.calendar.scenarios.pessimistic_pct', '0.010000')
        ->assertJsonPath('data.calendar.scenarios.neutral_pct', '0.010000')
        ->assertJsonPath('data.calendar.scenarios.optimistic_pct', '0.010000')
        ->assertJsonPath('data.yearly.account_count', 2)
        ->assertJsonPath('data.yearly.current_wallet', '3030.00000000')
        ->assertJsonPath('data.yearly.days_observed', 1)
        ->assertJsonPath('data.yearly.outlook.years', 5)
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.available', true)
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.daily_pct', '0.01000000')
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.milestones.0.end_date', '2026-12-31')
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.milestones.4.end_date', '2030-12-31');

    expect($response->json('data.accounts.*.id'))
        ->not->toContain($foreignAccountId)
        ->and(DB::table('positions')->orderBy('id')->get()->all())->toEqual($positionsBefore)
        ->and(DB::table('account_balance_history')->orderBy('id')->get()->all())->toEqual($balancesBefore);

    $this->getJson('https://api.kraite.com/v1/projections?account_id='.$foreignAccountId)
        ->assertNotFound();
});

it('returns an empty calendar and unavailable yearly outlook without accounts', function (): void {
    $owner = User::factory()->create([
        'name' => 'Projection empty owner',
        'email' => 'projection-empty@kraite.test',
    ]);
    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/projections')
        ->assertOk()
        ->assertJsonCount(0, 'data.accounts')
        ->assertJsonPath('data.selected_account_id', null)
        ->assertJsonPath('data.calendar', null)
        ->assertJsonPath('data.yearly.account_count', 0)
        ->assertJsonPath('data.yearly.current_wallet', null)
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.available', false)
        ->assertJsonPath('data.yearly.outlook.scenarios.neutral.reason', 'no_wallet');
});
