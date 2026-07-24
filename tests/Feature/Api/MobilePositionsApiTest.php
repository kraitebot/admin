<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
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
        $table->unsignedBigInteger('exchange_symbol_id')->nullable();
        $table->string('parsed_trading_pair')->nullable();
        $table->string('status');
        $table->string('direction')->nullable();
        $table->unsignedTinyInteger('leverage')->nullable();
        $table->dateTime('opened_at')->nullable();
        $table->dateTime('closed_at')->nullable();
        $table->decimal('opening_price', 20, 8)->nullable();
        $table->decimal('closing_price', 20, 8)->nullable();
        $table->decimal('margin', 20, 8)->nullable();
        $table->decimal('quantity', 20, 8)->nullable();
        $table->decimal('pnl', 20, 8)->nullable();
        $table->boolean('was_waped')->default(false);
        $table->boolean('was_fast_traded')->default(false);
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('positions');
    Schema::dropIfExists('accounts');
    Schema::dropIfExists('api_systems');
    Schema::dropIfExists('personal_access_tokens');
});

function createMobilePositionsAccount(User $owner, string $name): int
{
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);

    return DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('requires the read ability and validates positions history input', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['profile:read']);

    $this->getJson('https://api.kraite.com/v1/positions')
        ->assertForbidden();

    Sanctum::actingAs(User::factory()->create(), ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/positions?account_id=not-an-id')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');

    $this->getJson('https://api.kraite.com/v1/positions?cursor='.str_repeat('x', 513))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');

    $malformedCursor = rtrim(strtr(base64_encode(json_encode([
        '_pointsToNextItems' => true,
    ], flags: JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $this->getJson('https://api.kraite.com/v1/positions?cursor='.$malformedCursor)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('returns only the selected owners closed positions with exact realized results', function (): void {
    $now = CarbonImmutable::parse('2026-07-24 12:00:00');
    $this->travelTo($now);

    $owner = User::factory()->create([
        'name' => 'Mobile history owner',
        'email' => 'mobile-history-owner@kraite.test',
    ]);
    $intruder = User::factory()->create([
        'name' => 'Mobile history intruder',
        'email' => 'mobile-history-intruder@kraite.test',
    ]);
    $accountId = createMobilePositionsAccount($owner, 'Owner journal');
    $foreignAccountId = createMobilePositionsAccount($intruder, 'Foreign journal');

    $olderLongId = DB::table('positions')->insertGetId([
        'account_id' => $accountId,
        'parsed_trading_pair' => 'ETHUSDT',
        'status' => 'closed',
        'direction' => 'LONG',
        'leverage' => 10,
        'opened_at' => $now->subHours(3),
        'closed_at' => $now->subHours(2),
        'opening_price' => 100,
        'closing_price' => 105,
        'margin' => 50,
        'quantity' => 2,
        'pnl' => 12.5,
        'was_waped' => false,
        'was_fast_traded' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $newerShortId = DB::table('positions')->insertGetId([
        'account_id' => $accountId,
        'parsed_trading_pair' => 'SOLUSDT',
        'status' => 'closed',
        'direction' => 'SHORT',
        'leverage' => 8,
        'opened_at' => $now->subHour(),
        'closed_at' => $now->subMinutes(10),
        'opening_price' => 100,
        'closing_price' => 90,
        'margin' => 100,
        'quantity' => 2,
        'pnl' => null,
        'was_waped' => true,
        'was_fast_traded' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $openId = DB::table('positions')->insertGetId([
        'account_id' => $accountId,
        'parsed_trading_pair' => 'BTCUSDT',
        'status' => 'active',
        'direction' => 'LONG',
        'closed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $foreignId = DB::table('positions')->insertGetId([
        'account_id' => $foreignAccountId,
        'parsed_trading_pair' => 'XRPUSDT',
        'status' => 'closed',
        'direction' => 'SHORT',
        'closed_at' => $now,
        'pnl' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = DB::table('positions')->orderBy('id')->get()->all();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $response = $this->getJson('https://api.kraite.com/v1/positions?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.selected_account_id', $accountId)
        ->assertJsonPath('data.history.summary.count', 2)
        ->assertJsonPath('data.history.summary.long', 1)
        ->assertJsonPath('data.history.summary.short', 1)
        ->assertJsonPath('data.history.summary.wins', 2)
        ->assertJsonPath('data.history.summary.losses', 0)
        ->assertJsonPath('data.history.summary.realized_pnl', '32.5')
        ->assertJsonPath('data.history.positions.0.id', $newerShortId)
        ->assertJsonPath('data.history.positions.0.pnl', '20')
        ->assertJsonPath('data.history.positions.0.return_pct', 20)
        ->assertJsonPath('data.history.positions.0.duration_seconds', 3000)
        ->assertJsonPath('data.history.positions.0.was_waped', true)
        ->assertJsonPath('data.history.positions.0.was_fast_traded', true)
        ->assertJsonPath('data.history.positions.1.id', $olderLongId)
        ->assertJsonPath('data.history.positions.1.pnl', '12.5')
        ->assertJsonMissing(['id' => $openId])
        ->assertJsonMissing(['id' => $foreignId]);

    expect($response->json('data.history.next_cursor'))->toBeNull()
        ->and(DB::table('positions')->orderBy('id')->get()->all())->toEqual($before);

    $this->getJson('https://api.kraite.com/v1/positions?account_id='.$foreignAccountId)
        ->assertNotFound();
});

it('cursor-paginates stable closed history without duplicates', function (): void {
    $now = CarbonImmutable::parse('2026-07-24 12:00:00');
    $this->travelTo($now);

    $owner = User::factory()->create([
        'name' => 'Mobile cursor owner',
        'email' => 'mobile-cursor-owner@kraite.test',
    ]);
    $accountId = createMobilePositionsAccount($owner, 'Cursor journal');
    $rows = collect(range(1, 21))->map(fn (int $index): array => [
        'account_id' => $accountId,
        'parsed_trading_pair' => 'TOKEN'.$index.'USDT',
        'status' => 'closed',
        'direction' => $index % 2 === 0 ? 'LONG' : 'SHORT',
        'leverage' => 5,
        'opened_at' => $now->subMinutes($index + 10),
        'closed_at' => $now->subMinutes($index),
        'opening_price' => 100,
        'closing_price' => 101,
        'margin' => 100,
        'quantity' => 1,
        'pnl' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all();
    DB::table('positions')->insert($rows);
    $before = DB::table('positions')->orderBy('id')->pluck('id')->all();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $first = $this->getJson('https://api.kraite.com/v1/positions?account_id='.$accountId)
        ->assertOk()
        ->assertJsonCount(20, 'data.history.positions')
        ->assertJsonPath('data.history.summary.count', 21);
    $cursor = $first->json('data.history.next_cursor');

    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->getJson(
        'https://api.kraite.com/v1/positions?account_id='.$accountId.'&cursor='.urlencode($cursor),
    )
        ->assertOk()
        ->assertJsonCount(1, 'data.history.positions')
        ->assertJsonPath('data.history.next_cursor', null);

    $firstIds = collect($first->json('data.history.positions'))->pluck('id');
    $secondIds = collect($second->json('data.history.positions'))->pluck('id');

    expect($firstIds->intersect($secondIds))->toBeEmpty()
        ->and($firstIds->merge($secondIds)->sort()->values()->all())->toBe($before)
        ->and(DB::table('positions')->orderBy('id')->pluck('id')->all())->toBe($before);
});
