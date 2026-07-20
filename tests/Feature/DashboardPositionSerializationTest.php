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
        ['id' => 507, 'position_id' => $positionId, 'type' => 'STOP-MARKET', 'status' => 'NEW', 'exchange_order_id' => '18449680911', 'price' => '0.20000000', 'quantity' => '3565'],
        ['id' => 508, 'position_id' => $positionId, 'type' => 'STOP-MARKET', 'status' => 'CANCELLED', 'exchange_order_id' => '18449680942', 'price' => '0.19000000', 'quantity' => '3565'],
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
        ->and(array_column($serialized['limits'], 'status'))->not->toContain('CANCELLED')
        ->and($serialized['stop_loss_price'])->toBe('0.2')
        ->and($serialized['track']['sl_pct'])->toBe(100.0);
});

it('keeps TP and PX on their lifecycle stages after WAP and reserves the final stage for SL', function (): void {
    $method = new ReflectionMethod(DashboardController::class, 'trackGeometry');
    $controller = new DashboardController;

    $fresh = $method->invoke($controller, 0, 4, 50.0);
    $waped = $method->invoke($controller, 1, 4, 50.0);
    $fullyFilled = $method->invoke($controller, 4, 4, 50.0);

    expect($fresh)->toBe([
        'tp_pct' => 0.0,
        'px_pct' => 13.0,
        'sl_pct' => 100.0,
        'gain_left' => 0.0,
        'gain_width' => 13.0,
        'rungs' => [
            ['index' => 1, 'pct' => 26.0],
            ['index' => 2, 'pct' => 44.0],
            ['index' => 3, 'pct' => 62.0],
            ['index' => 4, 'pct' => 80.0],
        ],
    ])->and($waped)->toBe([
        'tp_pct' => 26.0,
        'px_pct' => 35.0,
        'sl_pct' => 100.0,
        'gain_left' => 26.0,
        'gain_width' => 9.0,
        'rungs' => [
            ['index' => 2, 'pct' => 44.0],
            ['index' => 3, 'pct' => 62.0],
            ['index' => 4, 'pct' => 80.0],
        ],
    ])->and($fullyFilled)->toBe([
        'tp_pct' => 80.0,
        'px_pct' => 90.0,
        'sl_pct' => 100.0,
        'gain_left' => 80.0,
        'gain_width' => 10.0,
        'rungs' => [],
    ]);
});

it('uses SL as the live-price destination after every limit has filled', function (): void {
    $positionId = 901;

    DB::table('orders')->insert([
        ['id' => 600, 'position_id' => $positionId, 'type' => 'MARKET', 'status' => 'FILLED', 'exchange_order_id' => 'market-901', 'price' => '100.00000000', 'quantity' => '1'],
        ['id' => 601, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'limit-901-1', 'price' => '90.00000000', 'quantity' => '2'],
        ['id' => 602, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'limit-901-2', 'price' => '80.00000000', 'quantity' => '4'],
        ['id' => 603, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'limit-901-3', 'price' => '70.00000000', 'quantity' => '8'],
        ['id' => 604, 'position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'limit-901-4', 'price' => '60.00000000', 'quantity' => '16'],
        ['id' => 605, 'position_id' => $positionId, 'type' => 'PROFIT-LIMIT', 'status' => 'NEW', 'exchange_order_id' => 'profit-901', 'price' => '75.00000000', 'quantity' => '31'],
        ['id' => 606, 'position_id' => $positionId, 'type' => 'STOP-MARKET', 'status' => 'NEW', 'exchange_order_id' => 'stop-901', 'price' => '50.00000000', 'quantity' => '31'],
    ]);

    $exchangeSymbol = new ExchangeSymbol;
    $exchangeSymbol->setRawAttributes([
        'id' => 19,
        'token' => 'RAIL',
        'quote' => 'USDT',
        'mark_price' => '62.50000000',
        'price_precision' => 2,
        'quantity_precision' => 0,
        'tick_size' => '0.01000000',
    ]);
    $exchangeSymbol->setRelation('priceRow', null);
    $exchangeSymbol->setRelation('symbol', new Symbol([
        'token' => 'RAIL',
        'name' => 'Rail fixture',
    ]));

    $position = new Position;
    $position->setRawAttributes([
        'id' => $positionId,
        'exchange_symbol_id' => 19,
        'parsed_trading_pair' => 'RAILUSDT',
        'status' => 'active',
        'direction' => 'LONG',
        'total_limit_orders' => 4,
        'opening_price' => '100.00000000',
        'quantity' => '31',
        'first_profit_price' => '110.00000000',
        'leverage' => 20,
        'was_waped' => true,
        'opened_at' => now()->subDay(),
    ]);
    $position->setRelation('exchangeSymbol', $exchangeSymbol);
    $position->setRelation('orders', Order::query()->where('position_id', $positionId)->orderBy('id')->get());

    $method = new ReflectionMethod(DashboardController::class, 'serializePosition');
    $serialized = $method->invoke(new DashboardController, $position, []);

    expect($serialized['filled_count'])->toBe(4)
        ->and($serialized['alpha_limit_pct'])->toBe('0.0')
        ->and($serialized['stop_loss_price'])->toBe('50')
        ->and($serialized['track'])->toBe([
            'tp_pct' => 80.0,
            'px_pct' => 90.0,
            'sl_pct' => 100.0,
            'gain_left' => 80.0,
            'gain_width' => 10.0,
            'rungs' => [],
        ]);
});
