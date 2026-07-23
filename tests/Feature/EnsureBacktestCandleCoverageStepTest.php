<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Enums\BacktestTimeframe;
use Kraite\Core\Jobs\Backtest\EnsureBacktestCandleCoverageStep;
use Kraite\Core\Jobs\Backtest\FetchRestCandlesStep;
use Kraite\Core\Jobs\Backtest\FetchTaapiCandlesStep;
use Kraite\Core\Jobs\Backtest\FetchVisionCandlesStep;
use Kraite\Core\Jobs\Backtest\VerifyCoverageResultStep;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

beforeEach(function (): void {
    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->string('canonical');
    });

    Schema::create('exchange_symbols', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('api_system_id');
        $table->string('token');
        $table->string('quote');
    });

    Schema::create('candles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('exchange_symbol_id');
        $table->string('timeframe');
        $table->unsignedBigInteger('timestamp');
    });

    Schema::create('steps', function (Blueprint $table): void {
        $table->id();
        $table->char('block_uuid', 36)->index();
        $table->string('type', 50)->default('default');
        $table->string('group', 50)->nullable();
        $table->string('state');
        $table->string('class')->nullable();
        $table->integer('index')->nullable();
        $table->longText('response')->nullable();
        $table->text('error_message')->nullable();
        $table->longText('error_stack_trace')->nullable();
        $table->text('step_log')->nullable();
        $table->string('relatable_type')->nullable();
        $table->unsignedBigInteger('relatable_id')->nullable();
        $table->char('child_block_uuid', 36)->nullable();
        $table->string('execution_mode', 50)->nullable();
        $table->tinyInteger('double_check')->default(0);
        $table->unsignedBigInteger('tick_id')->nullable();
        $table->char('workflow_id', 36)->nullable();
        $table->string('canonical', 100)->nullable();
        $table->string('queue', 50)->default('default');
        $table->json('arguments')->nullable();
        $table->integer('retries')->default(0);
        $table->tinyInteger('was_throttled')->default(0);
        $table->tinyInteger('is_throttled')->default(0);
        $table->string('priority', 20)->nullable();
        $table->timestamp('dispatch_after')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->bigInteger('duration')->default(0);
        $table->string('hostname', 100)->nullable();
        $table->tinyInteger('was_notified')->default(0);
        $table->timestamps();
    });

    Schema::create('steps_dispatcher', function (Blueprint $table): void {
        $table->id();
        $table->string('group', 50)->nullable()->unique();
        $table->tinyInteger('can_dispatch')->default(1);
        $table->unsignedBigInteger('current_tick_id')->nullable();
        $table->timestamp('last_tick_completed')->nullable();
        $table->timestamp('last_selected_at')->nullable();
        $table->timestamps();
    });
});

function seedCoverageSymbol(): int
{
    $apiSystemId = DB::table('api_systems')->insertGetId(['canonical' => 'binance']);

    return (int) DB::table('exchange_symbols')->insertGetId([
        'api_system_id' => $apiSystemId,
        'token' => 'ETH',
        'quote' => 'USDT',
    ]);
}

function seedCoverageCandles(int $exchangeSymbolId, int $firstTimestamp, int $lastTimestamp): void
{
    $interval = BacktestTimeframe::OneDay->seconds();
    $rows = [];

    for ($timestamp = $firstTimestamp; $timestamp <= $lastTimestamp; $timestamp += $interval) {
        $rows[] = [
            'exchange_symbol_id' => $exchangeSymbolId,
            'timeframe' => '1d',
            'timestamp' => $timestamp,
        ];
    }

    DB::table('candles')->insert($rows);
}

function makeCoverageParent(): Step
{
    return Step::create([
        'class' => EnsureBacktestCandleCoverageStep::class,
        'state' => Pending::class,
        'queue' => 'indicators',
    ]);
}

it('fetches the requested history when ETH only has five fresh candles', function (): void {
    $exchangeSymbolId = seedCoverageSymbol();
    $interval = BacktestTimeframe::OneDay->seconds();
    $lastClosedTimestamp = intdiv(time(), $interval) * $interval - $interval;
    seedCoverageCandles($exchangeSymbolId, $lastClosedTimestamp - (4 * $interval), $lastClosedTimestamp);

    $job = new EnsureBacktestCandleCoverageStep($exchangeSymbolId, '1d');
    $job->step = makeCoverageParent();

    $result = $job->compute();
    $children = Step::query()
        ->where('block_uuid', $job->step->fresh()->child_block_uuid)
        ->orderBy('index')
        ->get();

    expect($result['spawned'])->toBeTrue()
        ->and($children->pluck('class')->all())->toBe([
            FetchVisionCandlesStep::class,
            FetchRestCandlesStep::class,
            FetchTaapiCandlesStep::class,
            VerifyCoverageResultStep::class,
        ])
        ->and($children->last()->arguments)->toMatchArray([
            'maxMonths' => 24,
            'requestedSinceTimestamp' => null,
        ]);

    $verifier = new VerifyCoverageResultStep($exchangeSymbolId, '1d');
    $verdict = $verifier->compute();

    expect($verdict['ready'])->toBeFalse()
        ->and($verdict['reason'])->toContain('thin history');
});

it('skips fetching when the full requested history window is already present', function (): void {
    $exchangeSymbolId = seedCoverageSymbol();
    $interval = BacktestTimeframe::OneDay->seconds();
    $lastClosedTimestamp = intdiv(time(), $interval) * $interval - $interval;
    $requestedBoundary = Carbon::now('UTC')->startOfMonth()->subMonth()->getTimestamp();
    seedCoverageCandles($exchangeSymbolId, $requestedBoundary, $lastClosedTimestamp);

    $job = new EnsureBacktestCandleCoverageStep($exchangeSymbolId, '1d', 1);
    $job->step = makeCoverageParent();

    $result = $job->compute();

    expect($result['spawned'])->toBeFalse()
        ->and($result['ready'])->toBeTrue()
        ->and($job->step->fresh()->child_block_uuid)->toBeNull();

    $verifier = new VerifyCoverageResultStep($exchangeSymbolId, '1d', 1);

    expect($verifier->compute()['ready'])->toBeTrue();
});
