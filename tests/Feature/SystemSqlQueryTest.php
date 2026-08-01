<?php

declare(strict_types=1);

use App\Http\Controllers\System\SqlQueryController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const SQL_WORKBENCH_URL = 'https://admin.kraite.test/system/sql-query';

beforeEach(function (): void {
    Schema::dropIfExists('sql_inspection_records');
    Schema::create('sql_inspection_records', function (Blueprint $table): void {
        $table->id();
        $table->string('symbol');
        $table->string('desk');
        $table->string('note')->nullable();
    });

    $records = [];

    for ($id = 1; $id <= 23; $id++) {
        $records[] = [
            'id' => $id,
            'symbol' => "COIN-{$id}",
            'desk' => $id % 2 === 0 ? 'spot' : 'futures',
            'note' => "note-{$id}",
        ];
    }

    $records[0] = ['id' => 1, 'symbol' => 'BTC', 'desk' => 'spot', 'note' => null];
    $records[1] = ['id' => 2, 'symbol' => 'BTC-PERP', 'desk' => 'futures', 'note' => 'priority'];
    $records[2] = ['id' => 3, 'symbol' => 'ETH-BTC', 'desk' => 'spot', 'note' => 'secondary'];
    $records[3] = ['id' => 4, 'symbol' => 'ABTC', 'desk' => 'futures', 'note' => 'secondary'];
    $records[4] = ['id' => 5, 'symbol' => '100%_REAL', 'desk' => 'spot', 'note' => 'literal'];

    DB::table('sql_inspection_records')->insert($records);

    $this->admin = User::factory()->create(['is_admin' => true]);
});

it('keeps the SQL workbench behind sysadmin access', function (): void {
    $this->get(SQL_WORKBENCH_URL)->assertRedirect();

    $trader = User::factory()->create(['is_admin' => false]);

    $this->actingAs($trader)
        ->get(SQL_WORKBENCH_URL)
        ->assertForbidden();
});

it('renders the focused table picker, editor, and filters for admins', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(SQL_WORKBENCH_URL)
        ->assertSuccessful()
        ->assertSee('sql_inspection_records')
        ->assertSee('data-sql-workbench', false)
        ->assertSee('data-sql-table-search', false)
        ->assertSee('autofocus', false)
        ->assertSee('data-sql-editor', false)
        ->assertSee('data-sql-column-filter', false)
        ->assertSee('LIMIT 10')
        ->assertSee('Read only');

    $document = new DOMDocument;
    $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $editor = (new DOMXPath($document))->query('//*[@data-sql-editor]')->item(0);

    expect($editor)->not->toBeNull()
        ->and($editor->nodeName)->toBe('input');
});

it('returns the current table names', function (): void {
    $this->actingAs($this->admin)
        ->getJson(SQL_WORKBENCH_URL.'/tables')
        ->assertSuccessful()
        ->assertJsonPath('tables', fn (array $tables): bool => in_array('sql_inspection_records', $tables, true));
});

it('discovers tables only from the current database schema', function (): void {
    Schema::shouldReceive('getCurrentSchemaName')
        ->once()
        ->andReturn('kraite');
    Schema::shouldReceive('getTableListing')
        ->once()
        ->withArgs(fn (string $schema, bool $schemaQualified): bool => $schema === 'kraite' && ! $schemaQualified)
        ->andReturn(['accounts', 'users']);

    $response = app(SqlQueryController::class)->tables();

    expect($response->getData(true))->toBe([
        'tables' => ['accounts', 'users'],
    ]);
});

it('paginates SELECT results', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records ORDER BY id',
            'page' => 2,
            'per_page' => 10,
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 23)
        ->assertJsonPath('page', 2)
        ->assertJsonPath('last_page', 3)
        ->assertJsonPath('columns', ['id', 'symbol'])
        ->assertJsonCount(10, 'results')
        ->assertJsonPath('results.0.id', 11)
        ->assertJsonPath('results.9.id', 20);
});

it('uses exact matching for plain column filters', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records',
            'filters' => ['symbol' => 'BTC'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.id', 1);
});

it('supports star wildcards and escapes SQL wildcard characters', function (
    string $filter,
    int $expectedTotal,
): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records',
            'filters' => ['symbol' => $filter],
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', $expectedTotal);
})->with([
    'prefix' => ['BTC*', 2],
    'suffix' => ['*BTC', 3],
    'contains' => ['*BTC*', 4],
    'literal percent and underscore' => ['100%_*', 1],
]);

it('filters database nulls with the NULL keyword', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, note FROM sql_inspection_records',
            'filters' => ['note' => 'null'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.id', 1)
        ->assertJsonPath('results.0.note', null);
});

it('combines column filters with AND', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol, desk FROM sql_inspection_records',
            'filters' => [
                'symbol' => 'BTC*',
                'desk' => 'spot',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.id', 1);
});

it('accepts one SELECT with a trailing semicolon', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id FROM sql_inspection_records WHERE id = 1;',
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.id', 1);
});

it('allows semicolons and blocked words inside SELECT string values', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => "SELECT '; INTO OUTFILE FOR UPDATE' AS sample",
        ])
        ->assertSuccessful()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.sample', '; INTO OUTFILE FOR UPDATE');
});

it('rejects non-SELECT and multiple statements without changing data', function (
    string $query,
): void {
    $recordCount = DB::table('sql_inspection_records')->count();

    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, ['query' => $query])
        ->assertUnprocessable()
        ->assertJsonStructure(['error']);

    expect(DB::table('sql_inspection_records')->count())->toBe($recordCount);
})->with([
    'delete' => 'DELETE FROM sql_inspection_records',
    'show' => 'SHOW TABLES',
    'describe' => 'DESCRIBE sql_inspection_records',
    'explain' => 'EXPLAIN SELECT * FROM sql_inspection_records',
    'multiple' => 'SELECT * FROM sql_inspection_records; DELETE FROM sql_inspection_records',
    'outfile' => "SELECT * FROM sql_inspection_records INTO OUTFILE '/tmp/sql-workbench.txt'",
    'locking' => 'SELECT * FROM sql_inspection_records FOR UPDATE',
    'file read' => "SELECT LOAD_FILE('/tmp/example')",
    'server sleep' => 'SELECT SLEEP(5)',
]);

it('rejects invalid pagination and unsafe filter column names', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records',
            'per_page' => 15,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');

    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records',
            'filters' => ['symbol` OR 1=1 --' => '*'],
        ])
        ->assertUnprocessable()
        ->assertJsonStructure(['error']);
});

it('returns an empty result shape when a SELECT finds nothing', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL, [
            'query' => 'SELECT id, symbol FROM sql_inspection_records WHERE id < 0',
        ])
        ->assertSuccessful()
        ->assertJsonPath('results', [])
        ->assertJsonPath('columns', [])
        ->assertJsonPath('total', 0)
        ->assertJsonPath('page', 1)
        ->assertJsonPath('last_page', 1);
});

it('does not expose legacy mutation endpoints', function (): void {
    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL.'/truncate', ['table' => 'sql_inspection_records'])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->getJson(SQL_WORKBENCH_URL.'/primary-key?table=sql_inspection_records')
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson(SQL_WORKBENCH_URL.'/update', [])
        ->assertNotFound();
});
