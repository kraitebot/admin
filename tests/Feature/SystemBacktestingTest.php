<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The console backtesting index reads three kraitebot/core-owned tables
 * (exchange_symbols ⋈ symbols ⋈ api_systems) plus the accounts row for form
 * defaults. Core's real schema is MySQL-coupled and excluded from the SQLite
 * suite (see TestCase), so stub the minimum shape the listing query selects.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('api_systems')) {
        Schema::create('api_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical')->nullable();
        });
    }
    if (! Schema::hasTable('symbols')) {
        Schema::create('symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('token')->nullable();
            $table->integer('cmc_ranking')->nullable();
            $table->string('cmc_category')->nullable();
        });
    }
    if (! Schema::hasTable('exchange_symbols')) {
        Schema::create('exchange_symbols', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('symbol_id');
            $table->unsignedBigInteger('api_system_id');
            $table->string('quote')->nullable();
            $table->string('percentage_gap_long')->nullable();
            $table->string('percentage_gap_short')->nullable();
            $table->integer('total_limit_orders')->nullable();
            $table->text('limit_quantity_multipliers')->nullable();
            $table->boolean('was_backtesting_approved')->default(false);
            $table->string('backtesting_review_status')->nullable();
            $table->boolean('is_manually_enabled')->default(false);
        });
    }
    if (! Schema::hasTable('accounts')) {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('profit_percentage')->nullable();
            $table->string('stop_market_initial_percentage')->nullable();
            $table->softDeletes();
        });
    }
});

function seedBacktestableToken(): void
{
    $apiSystemId = DB::table('api_systems')->insertGetId(['canonical' => 'binance']);
    $symbolId = DB::table('symbols')->insertGetId([
        'token' => 'BTC',
        'cmc_ranking' => 1,
        'cmc_category' => 'Layer 1',
    ]);
    DB::table('exchange_symbols')->insert([
        'symbol_id' => $symbolId,
        'api_system_id' => $apiSystemId,
        'quote' => 'USDT',
        'percentage_gap_long' => '0.60',
        'percentage_gap_short' => '0.60',
        'total_limit_orders' => 4,
        'limit_quantity_multipliers' => '[2,2,2,2]',
        'was_backtesting_approved' => true,
        'backtesting_review_status' => 'approved',
        'is_manually_enabled' => true,
    ]);
}

it('redirects guests on the backtesting console page to login', function (): void {
    $this->get('https://admin.kraite.test/system/backtesting')
        ->assertRedirect();
});

it('forbids non-admin users from the backtesting console page', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/system/backtesting')
        ->assertForbidden();
});

it('renders the backtesting workspace for admins', function (): void {
    seedBacktestableToken();
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/backtesting');

    $response->assertSuccessful();
    // Page chrome + the Alpine workspace bootstrap are present.
    $response->assertSee('Backtesting', false);
    $response->assertSee('btConsole(', false);
    // The seeded token reached the client config.
    $response->assertSee('BTC', false);
    // The endpoints the workspace drives are wired into the bootstrap.
    // (@js escapes slashes, so assert the unique hyphenated path segments.)
    $response->assertSee('fetch-candles', false);
    $response->assertSee('verify-coverage', false);
    $response->assertSee('toggle-approval', false);
    $response->assertSee('ai-insights', false);
});
