<?php

declare(strict_types=1);

use App\Models\User;
use BrunoCFalcao\AiBridge\Chat\ChatManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Engine page — dispatcher performance + failures triage
|--------------------------------------------------------------------------
|
| Step tables are core/step-dispatcher-owned (excluded from the SQLite
| suite), so the minimal shape the triage queries touch is stubbed. The
| MySQL-only gauge/grouping SQL degrades to placeholders here — pinned as
| behavior; its math is exercised against the real local MySQL manually.
| Resolve + troubleshoot run portable query-builder SQL, so their exact
| row-level effects ARE pinned here, with the AI chat mocked.
|
*/

const FAILED_STATE = 'StepDispatcher\\States\\Failed';
const COMPLETED_STATE = 'StepDispatcher\\States\\Completed';

function stubStepTables(): void
{
    foreach (['steps', 'steps_archive', 'trading_steps', 'trading_steps_archive'] as $table) {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('class')->nullable();
            $blueprint->string('state');
            $blueprint->string('type', 50)->default('default');
            $blueprint->char('workflow_id', 36)->nullable();
            $blueprint->char('child_block_uuid', 36)->nullable();
            $blueprint->text('error_message')->nullable();
            $blueprint->longText('error_stack_trace')->nullable();
            $blueprint->tinyInteger('exception_analysed')->default(0);
            $blueprint->longText('exception_verdict')->nullable();
            $blueprint->json('arguments')->nullable();
            $blueprint->string('canonical', 100)->nullable();
            $blueprint->string('queue', 50)->default('default');
            $blueprint->string('hostname', 100)->nullable();
            $blueprint->integer('retries')->default(0);
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->timestamps();
        });
    }
}

afterEach(function (): void {
    foreach (['steps', 'steps_archive', 'trading_steps', 'trading_steps_archive'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('gates the engine surfaces to sysadmins', function (): void {
    $this->get('https://admin.kraite.test/system/engine')->assertRedirect();

    $trader = User::factory()->create(['is_admin' => false]);
    $this->actingAs($trader)->get('https://admin.kraite.test/system/engine')->assertForbidden();
    $this->actingAs($trader)->get('https://admin.kraite.test/system/engine/data')->assertForbidden();
    $this->actingAs($trader)->post('https://admin.kraite.test/system/engine/failures/resolve', ['class' => 'X'])->assertForbidden();
});

it('renders the engine page for admins with the live state seeded', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-page@kraite.test']);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/engine')
        ->assertSuccessful();

    $response->assertSee('Engine', false);
    // Alpine bootstrap with the endpoint map (nested @js payloads render as
    // JSON.parse with unicode-escaped quotes, so match the function + labels).
    $response->assertSee('enginePage(JSON.parse(', false);
    $response->assertSee('Troubleshoot with AI', false);
    $response->assertSee('Mark resolved', false);
});

it('degrades the gauges to placeholders when the step tables are unavailable', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-degrade@kraite.test']);

    $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/engine/data')
        ->assertSuccessful()
        ->assertJsonPath('gauges.combined.pending', null)
        ->assertJsonPath('gauges.combined.failures', null)
        ->assertJsonPath('gauges.fleets', []);
});

it('resolves every unresolved failure of a class across all four tables and nothing else', function (): void {
    stubStepTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-resolve@kraite.test']);

    // Target class spread across live + archive, both fleets.
    DB::table('steps')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('steps_archive')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('trading_steps')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('trading_steps_archive')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'created_at' => now()]);
    // Must NOT be touched: already analysed, different class, non-failed state.
    DB::table('steps')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'exception_analysed' => 1, 'created_at' => now()]);
    DB::table('steps')->insert(['class' => 'App\\Jobs\\Other', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('steps')->insert(['class' => 'App\\Jobs\\Boom', 'state' => COMPLETED_STATE, 'created_at' => now()]);

    $this->actingAs($admin)
        ->post('https://admin.kraite.test/system/engine/failures/resolve', ['class' => 'App\\Jobs\\Boom'])
        ->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('resolved', 4);

    expect(DB::table('steps')->where('class', 'App\\Jobs\\Boom')->where('state', FAILED_STATE)->where('exception_analysed', 0)->count())->toBe(0)
        ->and((int) DB::table('trading_steps_archive')->value('exception_analysed'))->toBe(1)
        ->and((int) DB::table('steps')->where('class', 'App\\Jobs\\Other')->value('exception_analysed'))->toBe(0)
        ->and((int) DB::table('steps')->where('state', COMPLETED_STATE)->value('exception_analysed'))->toBe(0);
});

it('persists the AI verdict on the newest unresolved occurrence only', function (): void {
    stubStepTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-verdict@kraite.test']);

    $oldId = DB::table('steps')->insertGetId([
        'class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE,
        'error_message' => 'older boom', 'created_at' => now()->subHours(3),
    ]);
    $newId = DB::table('trading_steps')->insertGetId([
        'class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE,
        'error_message' => 'newest boom', 'created_at' => now()->subMinutes(5),
    ]);

    $this->mock(ChatManager::class)
        ->shouldReceive('send')
        ->once()
        ->andReturn('Root cause: canned verdict for the test.');

    $this->actingAs($admin)
        ->post('https://admin.kraite.test/system/engine/failures/troubleshoot', ['class' => 'App\\Jobs\\Boom'])
        ->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('verdict', 'Root cause: canned verdict for the test.');

    expect(DB::table('trading_steps')->where('id', $newId)->value('exception_verdict'))
        ->toBe('Root cause: canned verdict for the test.')
        ->and(DB::table('steps')->where('id', $oldId)->value('exception_verdict'))->toBeNull();
});

it('resolve-all sweeps every unresolved failure across all tables and nothing else', function (): void {
    stubStepTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-resall@kraite.test']);

    DB::table('steps')->insert(['class' => 'App\\Jobs\\A', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('steps_archive')->insert(['class' => 'App\\Jobs\\B', 'state' => FAILED_STATE, 'created_at' => now()->subDays(20)]);
    DB::table('trading_steps')->insert(['class' => 'App\\Jobs\\C', 'state' => FAILED_STATE, 'created_at' => now()]);
    DB::table('trading_steps_archive')->insert(['class' => 'App\\Jobs\\D', 'state' => FAILED_STATE, 'created_at' => now()]);
    // Untouched: already analysed, and a non-failed step.
    DB::table('steps')->insert(['class' => 'App\\Jobs\\A', 'state' => FAILED_STATE, 'exception_analysed' => 1, 'created_at' => now()]);
    DB::table('steps')->insert(['class' => 'App\\Jobs\\E', 'state' => COMPLETED_STATE, 'created_at' => now()]);

    $this->actingAs($admin)
        ->post('https://admin.kraite.test/system/engine/failures/resolve-all')
        ->assertSuccessful()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('resolved', 4);

    // Every failed row analysed (incl. the 20-day-old archive one — no window),
    // the completed row untouched.
    expect(DB::table('steps')->where('state', FAILED_STATE)->where('exception_analysed', 0)->count())->toBe(0)
        ->and((int) DB::table('steps_archive')->value('exception_analysed'))->toBe(1)
        ->and((int) DB::table('trading_steps_archive')->value('exception_analysed'))->toBe(1)
        ->and((int) DB::table('steps')->where('state', COMPLETED_STATE)->value('exception_analysed'))->toBe(0);
});

// NOTE: the "total processing" count (running leaf steps — own
// child_block_uuid null; parents + waiting steps excluded) lives inside
// gauges() next to MySQL-only DATE_SUB/DATE_FORMAT windows that throw on
// sqlite, so the whole method degrades to null here — the same reason the
// rest of the gauge math isn't sqlite-tested. Verified against the real
// local MySQL: a seeded running leaf counts 1 while a running parent
// (child_block_uuid set) is excluded.

it('answers 404 when troubleshooting a class with no unresolved failure', function (): void {
    stubStepTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'eng-404@kraite.test']);

    DB::table('steps')->insert(['class' => 'App\\Jobs\\Boom', 'state' => FAILED_STATE, 'exception_analysed' => 1, 'created_at' => now()]);

    $this->mock(ChatManager::class)->shouldNotReceive('send');

    $this->actingAs($admin)
        ->post('https://admin.kraite.test/system/engine/failures/troubleshoot', ['class' => 'App\\Jobs\\Boom'])
        ->assertNotFound()
        ->assertJsonPath('ok', false);
});
