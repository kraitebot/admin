<?php

use App\Http\Controllers\BscsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectionsController;
use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Accounts\PositionsController;
use App\Http\Controllers\System\BacktrackingController;
use App\Http\Controllers\System\CommandsController;
use App\Http\Controllers\System\DashboardController as SystemDashboardController;
use App\Http\Controllers\System\SqlQueryController;
use App\Http\Controllers\System\StepDispatcherController;
use App\Http\Controllers\System\UiComponentsController;
use App\Http\Controllers\System\UsersController as SystemUsersController;
use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Dashboard data feed
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');

    // Projections
    Route::get('/projections', [ProjectionsController::class, 'index'])->name('projections');
    Route::get('/projections/data', [ProjectionsController::class, 'data'])->name('projections.data');

    // BSCS — Black Swan Composite Score: regime detection feature page.
    // Read surface is public (educational + status). Override controls
    // are operator-only and live behind the admin middleware below.
    Route::get('/bscs', [BscsController::class, 'index'])->name('bscs');
    Route::get('/bscs/data', [BscsController::class, 'data'])->name('bscs.data');

    // Accounts
    Route::get('/accounts/positions', [PositionsController::class, 'index'])->name('accounts.positions');
    Route::get('/accounts/positions/data', [PositionsController::class, 'data'])->name('accounts.positions.data');
    Route::get('/accounts/positions/history', [PositionsController::class, 'history'])->name('accounts.positions.history');

    Route::get('/accounts/edit', [AccountController::class, 'edit'])->name('accounts.edit');
    Route::get('/accounts/edit/quotes', [AccountController::class, 'quotes'])->name('accounts.quotes');
    Route::patch('/accounts/edit', [AccountController::class, 'update'])->name('accounts.update');

    // System — every surface under /system/* is sysadmin-only. Server-side
    // gate via the `admin` middleware so a non-admin can't reach any of
    // these by typing the URL even if the sidebar entry is hidden.
    Route::middleware('admin')->group(function () {
        Route::get('/system/dashboard', [SystemDashboardController::class, 'index'])->name('system.dashboard');
        Route::get('/system/dashboard/data', [SystemDashboardController::class, 'data'])->name('system.dashboard.data');
        Route::get('/system/dashboard/health', [SystemDashboardController::class, 'health'])->name('system.dashboard.health');

        // BSCS operator controls — manual override is sysadmin-only per spec.
        Route::post('/system/bscs/override/engage', [BscsController::class, 'engageOverride'])->name('system.bscs.override.engage');
        Route::post('/system/bscs/override/clear', [BscsController::class, 'clearOverride'])->name('system.bscs.override.clear');

        Route::get('/system/sql-query', [SqlQueryController::class, 'index'])->name('system.sql-query');
        Route::post('/system/sql-query', [SqlQueryController::class, 'execute'])->name('system.sql-query.execute');
        Route::get('/system/sql-query/tables', [SqlQueryController::class, 'tables'])->name('system.sql-query.tables');
        Route::post('/system/sql-query/truncate', [SqlQueryController::class, 'truncate'])->name('system.sql-query.truncate');
        Route::get('/system/sql-query/primary-key', [SqlQueryController::class, 'primaryKey'])->name('system.sql-query.primary-key');
        Route::post('/system/sql-query/update', [SqlQueryController::class, 'update'])->name('system.sql-query.update');

        // Commands
        Route::get('/system/commands', [CommandsController::class, 'index'])->name('system.commands');
        Route::get('/system/commands/details', [CommandsController::class, 'details'])->name('system.commands.details');
        Route::post('/system/commands/execute', [CommandsController::class, 'execute'])->name('system.commands.execute');

        // Step Dispatcher
        Route::get('/system/step-dispatcher', [StepDispatcherController::class, 'index'])->name('system.step-dispatcher');
        Route::get('/system/step-dispatcher/data', [StepDispatcherController::class, 'data'])->name('system.step-dispatcher.data');
        Route::get('/system/step-dispatcher/blocks', [StepDispatcherController::class, 'blocks'])->name('system.step-dispatcher.blocks');
        Route::get('/system/step-dispatcher/block-steps', [StepDispatcherController::class, 'blockSteps'])->name('system.step-dispatcher.block-steps');
        Route::get('/system/step-dispatcher/cooling-down', [StepDispatcherController::class, 'coolingDown'])->name('system.step-dispatcher.cooling-down');
        Route::post('/system/step-dispatcher/toggle-cooling-down', [StepDispatcherController::class, 'toggleCoolingDown'])->name('system.step-dispatcher.toggle-cooling-down');

        // Backtracking — historical-candle ladder backtester.
        Route::get('/system/backtracking', [BacktrackingController::class, 'index'])->name('system.backtracking');
        Route::post('/system/backtracking/fetch-candles', [BacktrackingController::class, 'fetchCandles'])->name('system.backtracking.fetch-candles');
        Route::post('/system/backtracking/verify-coverage', [BacktrackingController::class, 'verifyCoverage'])->name('system.backtracking.verify-coverage');
        Route::post('/system/backtracking/run', [BacktrackingController::class, 'run'])->name('system.backtracking.run');
        Route::post('/system/backtracking/toggle-approval', [BacktrackingController::class, 'toggleApproval'])->name('system.backtracking.toggle-approval');
        Route::post('/system/backtracking/ai-insights', [BacktrackingController::class, 'aiInsights'])
            ->middleware('throttle:10,1')
            ->name('system.backtracking.ai-insights');

        // Users admin — list every user, view billing state, apply manual
        // wallet credits/debits via the wallet_transactions ledger,
        // change a user's subscription tier.
        Route::get('/system/users/{user?}', [SystemUsersController::class, 'index'])->name('system.users');
        Route::post('/system/users/{user}/credit', [SystemUsersController::class, 'adjustCredit'])->name('system.users.credit');
        Route::post('/system/users/{user}/subscription', [SystemUsersController::class, 'changeSubscription'])->name('system.users.subscription');
        Route::post('/system/users/{user}/start-trial', [SystemUsersController::class, 'startTrial'])->name('system.users.start-trial');
        Route::post('/system/users/{user}/trial-days', [SystemUsersController::class, 'changeTrialDays'])->name('system.users.trial-days');

        // UI Components showcase — dev-only surface, never reachable in prod.
        if (! app()->isProduction()) {
            Route::get('/system/ui-components', [UiComponentsController::class, 'index'])->name('system.ui-components');
        }
    });

    // User-facing billing area — own balance, plan switcher, top-up
    // (mock pre-NOWPayments integration), wallet history, start-trading
    // trigger.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/start-trading', [BillingController::class, 'startTrading'])->name('billing.start-trading');
    Route::post('/billing/subscription', [BillingController::class, 'changeSubscription'])->name('billing.subscription');
});

require __DIR__.'/auth.php';
