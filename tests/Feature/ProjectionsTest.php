<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projections feed — realized daily revenue + observed daily-rate scenarios
 * per account, owner-scoped. Pins the payload shape the calendar consumes
 * and the ownership gate.
 */
beforeEach(function (): void {
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
        ->assertJsonPath('scenarios.days_observed', 1);

    // One observed day → all three scenarios collapse onto its daily rate (1%).
    expect((float) $response->json('scenarios.neutral_pct'))->toEqualWithDelta(0.01, 0.0001)
        ->and((float) $response->json('current_wallet'))->toBe(1010.0)
        ->and((float) $response->json('month_start_wallet'))->toBe(1000.0);
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
