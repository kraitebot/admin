<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Commands\Cronjobs\SyncAccountIncomesCommand;
use Kraite\Core\Models\Account;

/**
 * The income sync mirrors the exchange's own ledger. Beyond "it stores rows",
 * two properties matter: it must be idempotent, because it deliberately
 * re-reads an overlapping window, and it must ask for the account in one
 * paginated call rather than per symbol — the per-symbol shape is what tripped
 * Binance's shared IP rate limit on 2026-07-29, and that limit also governs
 * live trading.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-29 12:00:00'));

    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->dateTime('incomes_synced_from')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('account_incomes', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('tran_id');
        $table->string('income_type', 32);
        $table->string('symbol', 32)->nullable();
        $table->decimal('income', 20, 8);
        $table->string('asset', 16)->nullable();
        $table->dateTime('occurred_at');
        $table->timestamps();
        $table->unique(['account_id', 'tran_id', 'income_type', 'symbol']);
    });
});

afterEach(function (): void {
    foreach (['account_incomes', 'accounts'] as $table) {
        Schema::dropIfExists($table);
    }

    $this->travelBack();
});

/** A sync whose exchange pages are canned, recording what it asked for. */
function fakeIncomeSync(array $pages): SyncAccountIncomesCommand
{
    return new class($pages) extends SyncAccountIncomesCommand
    {
        /** @var array<int, array{start: int, end: int}> */
        public array $calls = [];

        /** @var array<int, array<int, array<string, mixed>>> */
        private array $pages;

        public function __construct(array $pages)
        {
            parent::__construct();
            $this->pages = $pages;
        }

        protected function fetchIncomePage(Account $account, int $startTime, int $endTime): array
        {
            $this->calls[] = ['start' => $startTime, 'end' => $endTime];

            return array_shift($this->pages) ?? [];
        }
    };
}

function incomeRecord(string $tranId, string $type, string $income, string $at, string $symbol = 'LINKUSDT'): array
{
    return [
        'tranId' => $tranId,
        'incomeType' => $type,
        'symbol' => $symbol,
        'income' => $income,
        'asset' => 'USDT',
        'time' => CarbonImmutable::parse($at)->getTimestampMs(),
    ];
}

function syncIncomes(SyncAccountIncomesCommand $command, ?string $syncedFrom = null): Account
{
    $id = DB::table('accounts')->insertGetId([
        'incomes_synced_from' => $syncedFrom,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $account = Account::findOrFail($id);
    (new ReflectionMethod($command, 'syncAccount'))->invoke($command, $account);

    return $account->fresh();
}

it('stores each exchange record with the moment the exchange booked it', function (): void {
    syncIncomes(fakeIncomeSync([[
        incomeRecord('1001', 'COMMISSION', '-1.00000000', '2026-07-28 18:00:00'),
        incomeRecord('1002', 'FUNDING_FEE', '-0.50000000', '2026-07-29 00:00:00'),
        incomeRecord('1003', 'REALIZED_PNL', '10.00000000', '2026-07-29 09:00:00'),
    ]]));

    $stored = DB::table('account_incomes')->orderBy('occurred_at')->get();

    expect($stored)->toHaveCount(3)
        ->and($stored[0]->income_type)->toBe('COMMISSION')
        ->and($stored[0]->occurred_at)->toStartWith('2026-07-28 18:00:00')
        ->and((float) $stored[2]->income)->toBe(10.0);
});

it('asks for the whole account at once rather than symbol by symbol', function (): void {
    $command = fakeIncomeSync([[
        incomeRecord('1001', 'COMMISSION', '-1.00000000', '2026-07-29 09:00:00', 'LINKUSDT'),
        incomeRecord('1002', 'COMMISSION', '-1.00000000', '2026-07-29 09:05:00', 'SOLUSDT'),
        incomeRecord('1003', 'COMMISSION', '-1.00000000', '2026-07-29 09:10:00', 'BTCUSDT'),
    ]]);

    syncIncomes($command);

    // Three symbols, one request. The per-symbol shape would be three requests
    // per income type, against a limit the trading engine shares.
    expect($command->calls)->toHaveCount(1)
        ->and(DB::table('account_incomes')->count())->toBe(3);
});

it('re-reading an overlapping window corrects rather than duplicates', function (): void {
    $record = incomeRecord('1001', 'REALIZED_PNL', '10.00000000', '2026-07-29 09:00:00');

    syncIncomes(fakeIncomeSync([[$record]]));
    syncIncomes(fakeIncomeSync([[$record]]));

    expect(DB::table('account_incomes')->count())->toBe(2, 'one row per account, two accounts seeded');

    // Same account, same booking, corrected amount: the row updates in place.
    $account = Account::firstOrFail();
    $corrected = array_merge($record, ['income' => '9.50000000']);
    (new ReflectionMethod(SyncAccountIncomesCommand::class, 'syncAccount'))
        ->invoke(fakeIncomeSync([[$corrected]]), $account);

    expect(DB::table('account_incomes')->where('account_id', $account->id)->count())->toBe(1)
        ->and((float) DB::table('account_incomes')->where('account_id', $account->id)->value('income'))->toBe(9.5);
});

it('backfills three months on a first run and records how far back it reached', function (): void {
    $command = fakeIncomeSync([[incomeRecord('1001', 'REALIZED_PNL', '1.00000000', '2026-07-29 09:00:00')]]);

    $account = syncIncomes($command);
    $askedFrom = CarbonImmutable::createFromTimestampMs($command->calls[0]['start']);

    expect($askedFrom->toDateString())->toBe('2026-04-29')
        ->and($account->incomes_synced_from->toDateString())->toBe('2026-04-29');
});

it('walks forward through pages when the exchange fills one', function (): void {
    $first = [];
    for ($i = 0; $i < 1000; $i++) {
        $first[] = incomeRecord('t'.$i, 'REALIZED_PNL', '0.01000000', '2026-07-20 10:00:00');
    }

    $command = fakeIncomeSync([
        $first,
        [incomeRecord('later', 'REALIZED_PNL', '2.00000000', '2026-07-25 10:00:00')],
    ]);

    syncIncomes($command);

    expect($command->calls)->toHaveCount(2)
        // The second page resumes just after the newest record of the first.
        ->and($command->calls[1]['start'])->toBeGreaterThan($command->calls[0]['start'])
        ->and(DB::table('account_incomes')->count())->toBe(1001);
});

it('never narrows the window it has already declared authoritative', function (): void {
    $account = syncIncomes(
        fakeIncomeSync([[incomeRecord('1001', 'REALIZED_PNL', '1.00000000', '2026-07-29 09:00:00')]]),
        syncedFrom: '2026-01-01 00:00:00',
    );

    // A later run resuming from July must not strand January through April.
    expect($account->incomes_synced_from->toDateString())->toBe('2026-01-01');
});

it('skips malformed records rather than storing junk', function (): void {
    syncIncomes(fakeIncomeSync([[
        incomeRecord('1001', 'REALIZED_PNL', '1.00000000', '2026-07-29 09:00:00'),
        ['tranId' => '', 'incomeType' => 'REALIZED_PNL', 'income' => '5', 'time' => 0],
        ['incomeType' => 'COMMISSION', 'income' => '-1'],
    ]]));

    expect(DB::table('account_incomes')->count())->toBe(1);
});
