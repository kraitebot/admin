<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('position_id');
        $table->string('type');
        $table->string('status');
        $table->string('exchange_order_id')->nullable();
        $table->decimal('price', 20, 8)->nullable();
        $table->decimal('quantity', 20, 8)->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('orders');
});

it('excludes a cancelled ladder order after its replacement is created', function (): void {
    $positionId = 588;

    DB::table('orders')->insert([
        ['id' => 500, 'position_id' => $positionId, 'type' => 'MARKET', 'status' => 'FILLED', 'exchange_order_id' => '18449679820', 'price' => '0.33172000', 'quantity' => '115'],
        ['id' => 501, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => '18449680175', 'price' => '0.30352000', 'quantity' => '230'],
        ['id' => 502, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'CANCELLED', 'exchange_order_id' => '18449680314', 'price' => '0.27532000', 'quantity' => '460'],
        ['id' => 503, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => '18449680386', 'price' => '0.24713000', 'quantity' => '920'],
        ['id' => 504, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => '18449680473', 'price' => '0.21893000', 'quantity' => '1840'],
        ['id' => 506, 'position_id' => $positionId, 'type' => 'PROFIT-LIMIT', 'status' => 'NEW', 'exchange_order_id' => '18449680882', 'price' => '0.33291000', 'quantity' => '115'],
        ['id' => 962, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => '18467746905', 'price' => '0.27532000', 'quantity' => '460'],
    ]);

    $exchangeSymbol = new ExchangeSymbol;
    $exchangeSymbol->setRawAttributes([
        'id' => 6,
        'token' => 'TRX',
        'quote' => 'USDT',
        'mark_price' => '0.32308000',
        'price_precision' => 5,
        'quantity_precision' => 0,
        'tick_size' => '0.00001000',
    ]);
    $exchangeSymbol->setRelation('priceRow', null);
    $exchangeSymbol->setRelation('symbol', new Symbol([
        'token' => 'TRX',
        'name' => 'TRON',
    ]));

    $position = new Position;
    $position->setRawAttributes([
        'id' => $positionId,
        'exchange_symbol_id' => 6,
        'parsed_trading_pair' => 'TRXUSDT',
        'status' => 'active',
        'direction' => 'LONG',
        'total_limit_orders' => 4,
        'opening_price' => '0.33172000',
        'quantity' => '115',
        'first_profit_price' => '0.33291000',
        'leverage' => 20,
        'was_waped' => false,
        'opened_at' => now()->subDays(3),
    ]);
    $position->setRelation('exchangeSymbol', $exchangeSymbol);
    $position->setRelation('orders', Order::query()->orderBy('id')->get());

    $method = new ReflectionMethod(DashboardController::class, 'serializePosition');
    $serialized = $method->invoke(new DashboardController, $position, []);

    expect($position->orders->where('type', 'LIMIT'))->toHaveCount(5)
        ->and($serialized['total_limits'])->toBe(4)
        ->and($serialized['filled_count'])->toBe(0)
        ->and($serialized['limits'])->toHaveCount(4)
        ->and(array_column($serialized['limits'], 'price'))->toBe([
            '0.30352',
            '0.27532',
            '0.24713',
            '0.21893',
        ])
        ->and(array_column($serialized['limits'], 'status'))->not->toContain('CANCELLED');
});
