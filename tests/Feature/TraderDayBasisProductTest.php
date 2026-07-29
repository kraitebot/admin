<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;
use Laravel\Sanctum\Sanctum;

/**
 * The day basis has to reach the screens, not just the calculator: the P&L
 * tile, the projections calendar, the mobile feed and the profile form all
 * have to agree on when the trader's day starts.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-28 23:38:00'));

    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id');
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->decimal('pnl', 20, 8)->nullable();
        $table->timestamp('closed_at')->nullable();
        $table->timestamps();
    });
    Schema::create('account_balance_history', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->decimal('total_wallet_balance', 20, 8)->default(0);
        $table->timestamps();
    });
});

afterEach(function (): void {
    foreach (['account_balance_history', 'positions', 'accounts', 'api_systems'] as $table) {
        Schema::dropIfExists($table);
    }

    $this->travelBack();
});

/** @return array{0: User, 1: int} */
function seedBasisTrader(int $utcOffsetMinutes): array
{
    $owner = User::factory()->create(['utc_offset_minutes' => $utcOffsetMinutes]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id, 'api_system_id' => $apiSystemId, 'name' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('account_balance_history')->insert([
        ['account_id' => $accountId, 'total_wallet_balance' => 1000, 'created_at' => '2026-07-01 00:30:00', 'updated_at' => now()],
        ['account_id' => $accountId, 'total_wallet_balance' => 1040, 'created_at' => '2026-07-28 23:30:00', 'updated_at' => now()],
    ]);

    // Midday close belongs to the UTC 28th but to a UTC+2 trader's 28th too;
    // the 22:30 close is the 28th in UTC and the 29th on a UTC+2 basis.
    DB::table('positions')->insert([
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 30, 'closed_at' => '2026-07-28 12:00:00', 'created_at' => now(), 'updated_at' => now()],
        ['account_id' => $accountId, 'status' => 'closed', 'pnl' => 10, 'closed_at' => '2026-07-28 22:30:00', 'created_at' => now(), 'updated_at' => now()],
    ]);

    return [$owner, $accountId];
}

function kpisForAccount(int $accountId): array
{
    $account = Account::findOrFail($accountId);
    $method = new ReflectionMethod(DashboardController::class, 'kpis');

    return $method->invoke(new DashboardController, $account, []);
}

it('totals the whole UTC day for a trader who never left the UTC basis', function (): void {
    [, $accountId] = seedBasisTrader(0);

    expect(kpisForAccount($accountId)['pnl_today'])->toBe('40.00');
});

it('totals only the exchange day for a trader on the UTC+2 basis', function (): void {
    [, $accountId] = seedBasisTrader(120);

    // Their day rolled at 22:00 UTC, so only the 22:30 close counts — the
    // same span Binance is showing under "Today".
    expect(kpisForAccount($accountId)['pnl_today'])->toBe('10.00');
});

it('serves the projections calendar on the trader day, not the UTC one', function (): void {
    [$owner, $accountId] = seedBasisTrader(120);

    $response = $this->actingAs($owner)
        ->getJson("https://admin.kraite.test/projections/data?account_id={$accountId}&year=2026&month=7")
        ->assertSuccessful();

    // It is already the 29th where this trader trades.
    $response->assertJsonPath('today', '2026-07-29')
        ->assertJsonPath('utc_offset_minutes', 120)
        ->assertJsonPath('day_basis_label', 'UTC+02:00')
        ->assertJsonPath('actuals.2026-07-29', '10.0000')
        ->assertJsonPath('actuals.2026-07-28', '30.0000');
});

it('keeps the calendar on UTC days for a trader who never set a basis', function (): void {
    [$owner, $accountId] = seedBasisTrader(0);

    $this->actingAs($owner)
        ->getJson("https://admin.kraite.test/projections/data?account_id={$accountId}&year=2026&month=7")
        ->assertSuccessful()
        ->assertJsonPath('today', '2026-07-28')
        ->assertJsonPath('day_basis_label', 'UTC+00:00')
        ->assertJsonPath('actuals.2026-07-28', '40.0000');
});

it('tells the mobile app which basis its numbers are on', function (): void {
    [$owner, $accountId] = seedBasisTrader(120);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson("https://api.kraite.com/v1/projections?account_id={$accountId}&year=2026&month=7")
        ->assertSuccessful()
        ->assertJsonPath('data.calendar.today', '2026-07-29')
        ->assertJsonPath('data.calendar.utc_offset_minutes', 120)
        ->assertJsonPath('data.calendar.day_basis_label', 'UTC+02:00');
});

it('serves the requested month itself to a trader west of UTC', function (): void {
    [$owner, $accountId] = seedBasisTrader(-300);

    // A midnight anchor shifted -5h lands on 30 June, which would have served
    // June's calendar to someone who asked for July.
    $this->actingAs($owner)
        ->getJson("https://admin.kraite.test/projections/data?account_id={$accountId}&year=2026&month=7")
        ->assertSuccessful()
        ->assertJsonPath('month', 7)
        ->assertJsonPath('actuals.2026-07-28', '40.0000');
});

it('reports on UTC when the stored basis is not one we can honour', function (): void {
    [, $accountId] = seedBasisTrader(0);
    // Simulates a value written outside the form — the dashboard must still
    // render rather than fail the trader's whole page.
    DB::table('users')->where('id', DB::table('accounts')->where('id', $accountId)->value('user_id'))
        ->update(['utc_offset_minutes' => 37]);

    expect(kpisForAccount($accountId)['pnl_today'])->toBe('40.00');
});

it('lets a trader choose the basis their exchange uses', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 0]);

    $this->actingAs($user)
        ->patch('https://admin.kraite.test/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'utc_offset_minutes' => 120,
        ])
        ->assertRedirect();

    expect($user->fresh()->utc_offset_minutes)->toBe(120);
});

it('refuses a basis no exchange offers', function (int|string $offset): void {
    $user = User::factory()->create(['utc_offset_minutes' => 0]);

    $this->actingAs($user)
        ->patch('https://admin.kraite.test/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'utc_offset_minutes' => $offset,
        ])
        ->assertSessionHasErrors('utc_offset_minutes');

    expect($user->fresh()->utc_offset_minutes)->toBe(0);
})->with([
    'past the eastern edge' => 900,
    'past the western edge' => -800,
    'not a quarter hour' => 37,
    'not a number at all' => 'Europe/Zurich',
]);

it('offers the basis on the profile page with the exchange wording', function (): void {
    $user = User::factory()->create(['utc_offset_minutes' => 120]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/profile')
        ->assertSuccessful()
        ->assertSeeText('Trading day basis')
        ->assertSee('name="utc_offset_minutes"', false)
        ->assertSee('value="120" selected', false)
        ->assertSee('UTC+02:00', false);
});
