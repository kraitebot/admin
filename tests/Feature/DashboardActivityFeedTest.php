<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;

/**
 * The dashboard "Recent bot activity" feed must surface EVERY currently-open
 * position's lifecycle events, even when hundreds of closed positions are
 * newer. The original implementation capped each source query (and the merged
 * stream) at 30 rows before the client-side "active only" filter ran, so a
 * heavily-churned account hid active positions whose opens were older than the
 * 30 most recent events.
 */
beforeEach(function (): void {
    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->string('parsed_trading_pair')->nullable();
        $table->string('direction')->nullable();
        $table->string('quantity')->nullable();
        $table->string('opening_price')->nullable();
        $table->string('closing_price')->nullable();
        $table->boolean('was_waped')->default(false);
        $table->dateTime('opened_at')->nullable();
        $table->dateTime('closed_at')->nullable();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('position_id');
        $table->string('type');
        $table->string('status');
        $table->string('price')->nullable();
        $table->string('quantity')->nullable();
        $table->dateTime('filled_at')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('orders');
    Schema::dropIfExists('positions');
});

/**
 * Invoke the private activityFeed() builder against a bare Account stub.
 *
 * @return array<int, array<string, mixed>>
 */
function buildActivityFeed(int $accountId): array
{
    $account = new Account;
    $account->id = $accountId;

    $method = new ReflectionMethod(DashboardController::class, 'activityFeed');

    return $method->invoke(new DashboardController, $account);
}

it('keeps every active position in the feed despite newer closed-position churn', function (): void {
    $accountId = 7;
    $base = now()->subDays(5);

    // One active position, opened first (the oldest event on the account).
    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'active',
        'parsed_trading_pair' => 'KASUSDT',
        'direction' => 'LONG',
        'quantity' => '100',
        'opening_price' => '0.03',
        'opened_at' => $base,
    ]);

    // 40 closed positions, each opened AND closed strictly after the active
    // one — enough to bury it past every 30-row recency window.
    for ($i = 1; $i <= 40; $i++) {
        DB::table('positions')->insert([
            'account_id' => $accountId,
            'status' => 'closed',
            'parsed_trading_pair' => "OLD{$i}USDT",
            'direction' => 'LONG',
            'quantity' => '10',
            'opening_price' => '1',
            'closing_price' => '2',
            'opened_at' => $base->copy()->addMinutes($i * 10),
            'closed_at' => $base->copy()->addMinutes($i * 10 + 5),
        ]);
    }

    $feed = collect(buildActivityFeed($accountId));
    $activeSymbols = $feed->where('active', true)->pluck('symbol');

    // The active position's OPEN event must be present even though 40 newer
    // opens and 40 newer closes exist.
    expect($activeSymbols)->toContain('KASUSDT');
});

it('includes a WAP event from an active position buried under churn', function (): void {
    $accountId = 9;
    $base = now()->subDays(5);

    $positionId = DB::table('positions')->insertGetId([
        'account_id' => $accountId,
        'status' => 'waping',
        'parsed_trading_pair' => 'RUNEUSDT',
        'direction' => 'SHORT',
        'quantity' => '174',
        'opening_price' => '0.35',
        'opened_at' => $base,
    ]);

    // A filled ladder rung — the WAP event — also stamped older than the churn.
    DB::table('orders')->insert([
        'position_id' => $positionId,
        'type' => 'LIMIT',
        'status' => 'FILLED',
        'price' => '0.3604',
        'quantity' => '174',
        'filled_at' => $base->copy()->addMinute(),
    ]);

    // Bury it under 40 newer closed positions.
    for ($i = 1; $i <= 40; $i++) {
        DB::table('positions')->insert([
            'account_id' => $accountId,
            'status' => 'closed',
            'parsed_trading_pair' => "OLD{$i}USDT",
            'direction' => 'LONG',
            'quantity' => '10',
            'opening_price' => '1',
            'closing_price' => '2',
            'opened_at' => $base->copy()->addMinutes($i * 10),
            'closed_at' => $base->copy()->addMinutes($i * 10 + 5),
        ]);
    }

    $feed = collect(buildActivityFeed($accountId));
    $wap = $feed->firstWhere('kind', 'WAP');

    expect($wap)->not->toBeNull()
        ->and($wap['symbol'])->toBe('RUNEUSDT')
        ->and($wap['active'])->toBeTrue();
});
