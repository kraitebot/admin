<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;

/**
 * The trader dashboard's "Open positions" heading states how many positions are
 * active and how long ago the account last closed one. The timestamp comes from
 * the shared payload, so both the browser dashboard and the mobile app read the
 * same value.
 */
beforeEach(function (): void {
    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->dateTime('closed_at')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('positions');
});

function lastClosedFor(int $accountId): ?string
{
    $account = new Account;
    $account->id = $accountId;

    $method = new ReflectionMethod(DashboardController::class, 'lastPositionClosedAt');

    return $method->invoke(new DashboardController, $account);
}

it('reports the most recent close on the account', function (): void {
    DB::table('positions')->insert([
        ['id' => 1, 'account_id' => 7, 'status' => 'closed', 'closed_at' => '2026-07-25 09:00:00'],
        ['id' => 2, 'account_id' => 7, 'status' => 'closed', 'closed_at' => '2026-07-25 14:30:00'],
        ['id' => 3, 'account_id' => 7, 'status' => 'active', 'closed_at' => null],
        ['id' => 4, 'account_id' => 9, 'status' => 'closed', 'closed_at' => '2026-07-25 23:00:00'],
    ]);

    expect(lastClosedFor(7))->toStartWith('2026-07-25T14:30:00');
});

it('reports nothing when the account has never closed a position', function (): void {
    DB::table('positions')->insert([
        ['id' => 1, 'account_id' => 7, 'status' => 'active', 'closed_at' => null],
    ]);

    expect(lastClosedFor(7))->toBeNull();
});

it('ignores a closed position that never recorded when it closed', function (): void {
    DB::table('positions')->insert([
        ['id' => 1, 'account_id' => 7, 'status' => 'closed', 'closed_at' => null],
    ]);

    expect(lastClosedFor(7))->toBeNull();
});

it('serves the last close on the browser payload, not only the mobile one', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/DashboardController.php'));
    $webPayload = substr($controller, (int) strpos($controller, 'private function payload('), 900);

    expect($webPayload)->toContain("'last_position_closed_at' => \$this->lastPositionClosedAt(\$account)");
});

it('heads the open positions list with the active count and the last close', function (): void {
    $view = file_get_contents(resource_path('views/dashboard.blade.php'));

    expect($view)
        ->toContain('const closedAt = this.d?.last_position_closed_at;')
        ->toContain('` · Last closed ${closed}`')
        ->toContain('return `${total} active position${total === 1 ? \'\' : \'s\'}${closedSuffix}`;')
        ->not->toContain('max ${per} per page');
});
