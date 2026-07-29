<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The offer has to reach the trader and their answer has to stick — on the
 * web dashboard and on the phone, both reading the country the CDN edge saw.
 */
beforeEach(function (): void {
    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id');
        $table->string('name')->nullable();
        $table->dateTime('incomes_synced_from')->nullable();
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
});

function travellingTrader(int $offsetMinutes = 120, ?string $hintedCountry = null): User
{
    $user = User::factory()->create([
        'utc_offset_minutes' => $offsetMinutes,
        'basis_hint_country' => $hintedCountry,
    ]);

    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    DB::table('accounts')->insert([
        'user_id' => $user->id, 'api_system_id' => $apiSystemId, 'name' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
}

it('offers the switch when the edge sees a new country', function (): void {
    $user = travellingTrader();
    $this->actingAs($user);
    request()->headers->set('CF-IPCountry', 'PT');

    $hint = (new ReflectionMethod(DashboardController::class, 'dayBasisHint'))
        ->invoke(new DashboardController);

    expect($hint)->toMatchArray([
        'country_code' => 'PT',
        'country_name' => 'Portugal',
        'suggested_offset_minutes' => 60,
        'suggested_label' => 'UTC+01:00',
        'current_label' => 'UTC+02:00',
    ]);
});

it('says nothing once that country has been answered', function (): void {
    $this->actingAs(travellingTrader(hintedCountry: 'PT'));
    request()->headers->set('CF-IPCountry', 'PT');

    expect((new ReflectionMethod(DashboardController::class, 'dayBasisHint'))->invoke(new DashboardController))
        ->toBeNull();
});

it('says nothing when the request never passed through the edge', function (): void {
    $this->actingAs(travellingTrader());

    expect((new ReflectionMethod(DashboardController::class, 'dayBasisHint'))->invoke(new DashboardController))
        ->toBeNull();
});

it('carries the offer on both the browser and the phone payloads', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/DashboardController.php'));
    $browser = substr($controller, (int) strpos($controller, 'private function payload('), 900);
    $phone = substr($controller, (int) strpos($controller, 'public function mobilePayload('), 900);

    expect($browser)->toContain("'day_basis_hint' => \$this->dayBasisHint()")
        ->and($phone)->toContain("'day_basis_hint' => \$this->dayBasisHint()");
});

it('switches the basis when the trader accepts', function (): void {
    $user = travellingTrader();

    $this->actingAs($user)
        ->postJson('https://admin.kraite.test/profile/day-basis-hint', [
            'country_code' => 'PT',
            'accept' => true,
            'utc_offset_minutes' => 60,
        ])
        ->assertSuccessful()
        ->assertJsonPath('utc_offset_minutes', 60)
        ->assertJsonPath('day_basis_label', 'UTC+01:00');

    expect($user->fresh()->utc_offset_minutes)->toBe(60)
        ->and($user->fresh()->basis_hint_country)->toBe('PT');
});

it('leaves the basis alone when the trader keeps it', function (): void {
    $user = travellingTrader();

    $this->actingAs($user)
        ->postJson('https://admin.kraite.test/profile/day-basis-hint', [
            'country_code' => 'PT',
            'accept' => false,
        ])
        ->assertSuccessful()
        ->assertJsonPath('utc_offset_minutes', 120);

    // Declined is still answered: it must not ask again for Portugal.
    expect($user->fresh()->utc_offset_minutes)->toBe(120)
        ->and($user->fresh()->basis_hint_country)->toBe('PT');
});

it('refuses an accepted basis that is not on the offer list', function (): void {
    $user = travellingTrader();

    $this->actingAs($user)
        ->postJson('https://admin.kraite.test/profile/day-basis-hint', [
            'country_code' => 'PT',
            'accept' => true,
            'utc_offset_minutes' => 37,
        ])
        ->assertStatus(422);

    expect($user->fresh()->utc_offset_minutes)->toBe(120);
});
