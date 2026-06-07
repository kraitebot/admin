<?php

use App\Http\Controllers\BscsController;
use App\Http\Controllers\System\BacktrackingController;
use App\Http\Controllers\System\BillingCoinsController as SystemBillingCoinsController;
use App\Http\Controllers\System\BillingPlansController as SystemBillingPlansController;
use App\Http\Controllers\System\CommandsController;
use App\Http\Controllers\System\DashboardController as SystemDashboardController;
use App\Http\Controllers\System\LifecycleController;
use App\Http\Controllers\System\SqlQueryController;
use App\Http\Controllers\System\StepDispatcherController;
use App\Http\Controllers\System\UiComponentsController;
use App\Http\Controllers\System\UsersController as SystemUsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console surface — sysadmin domain
|--------------------------------------------------------------------------
| Same Laravel project, same UI library, different host. Every route here
| binds to the console domain and sits behind `auth` + `admin`, so a
| non-admin can't reach any of it even by typing the URL. Route names keep
| the `system.` prefix (controllers and redirects reference them); the
| `/system` URL prefix is gone — the host IS the system context.
*/

Route::domain(config('domains.console'))->middleware(['auth', 'admin'])->group(function () {

    Route::get('/dashboard', [SystemDashboardController::class, 'index'])->name('system.dashboard');
    Route::get('/dashboard/data', [SystemDashboardController::class, 'data'])->name('system.dashboard.data');
    Route::get('/dashboard/health', [SystemDashboardController::class, 'health'])->name('system.dashboard.health');

    // Sysadmin rail surfaces whose pages aren't built yet. Honest
    // placeholders keep the design's nav fully navigable; each gets its
    // real page in a later phase. (Dispatch → system.steps and SQL →
    // system.sql-query already have real pages below.)
    Route::view('/positions', 'system.positions')->name('system.positions');
    Route::view('/engine', 'system.engine')->name('system.engine');
    Route::view('/infra', 'system.infra')->name('system.infra');
    Route::view('/exchanges', 'system.exchanges')->name('system.exchanges');
    Route::view('/revenue', 'system.revenue')->name('system.revenue');
    Route::view('/settings', 'system.settings')->name('system.settings');

    // BSCS operator controls — manual override is sysadmin-only per spec.
    Route::post('/bscs/override/engage', [BscsController::class, 'engageOverride'])->name('system.bscs.override.engage');
    Route::post('/bscs/override/clear', [BscsController::class, 'clearOverride'])->name('system.bscs.override.clear');

    Route::get('/sql-query', [SqlQueryController::class, 'index'])->name('system.sql-query');
    Route::post('/sql-query', [SqlQueryController::class, 'execute'])->name('system.sql-query.execute');
    Route::get('/sql-query/tables', [SqlQueryController::class, 'tables'])->name('system.sql-query.tables');
    Route::post('/sql-query/truncate', [SqlQueryController::class, 'truncate'])->middleware('throttle:3,1')->name('system.sql-query.truncate');
    Route::get('/sql-query/primary-key', [SqlQueryController::class, 'primaryKey'])->name('system.sql-query.primary-key');
    Route::post('/sql-query/update', [SqlQueryController::class, 'update'])->name('system.sql-query.update');

    // Commands
    Route::get('/commands', [CommandsController::class, 'index'])->name('system.commands');
    Route::get('/commands/details', [CommandsController::class, 'details'])->name('system.commands.details');
    Route::post('/commands/execute', [CommandsController::class, 'execute'])->middleware('throttle:10,1')->name('system.commands.execute');

    // Steps — two prefix-isolated dispatcher fleets share one
    // controller. `default` = `steps_*` tables (calculation churn);
    // `trading` = `trading_steps_*` (trade-critical workflow).
    // Cooling-down endpoint returns BOTH fleets' MaintenanceMode
    // pause state in one payload; toggle is per-fleet (independent,
    // no mutex). The cooling-down route is declared BEFORE the
    // catch-all `{prefix}` so the `whereIn` constraint is enough
    // to keep them disjoint.
    Route::get('/steps/cooling-down', [StepDispatcherController::class, 'coolingDown'])->name('system.steps.cooling-down');
    Route::post('/steps/{prefix}/toggle-cooling-down', [StepDispatcherController::class, 'toggleCoolingDown'])
        ->whereIn('prefix', ['default', 'trading'])
        ->name('system.steps.toggle-cooling-down');
    Route::get('/steps/{prefix}', [StepDispatcherController::class, 'index'])
        ->whereIn('prefix', ['default', 'trading'])
        ->name('system.steps');
    Route::get('/steps/{prefix}/data', [StepDispatcherController::class, 'data'])
        ->whereIn('prefix', ['default', 'trading'])
        ->name('system.steps.data');
    Route::get('/steps/{prefix}/blocks', [StepDispatcherController::class, 'blocks'])
        ->whereIn('prefix', ['default', 'trading'])
        ->name('system.steps.blocks');
    Route::get('/steps/{prefix}/block-steps', [StepDispatcherController::class, 'blockSteps'])
        ->whereIn('prefix', ['default', 'trading'])
        ->name('system.steps.block-steps');

    // Backtesting — historical-candle ladder backtester.
    Route::get('/backtesting', [BacktrackingController::class, 'index'])->name('system.backtesting');
    Route::post('/backtesting/fetch-candles', [BacktrackingController::class, 'fetchCandles'])->name('system.backtesting.fetch-candles');
    Route::post('/backtesting/verify-coverage', [BacktrackingController::class, 'verifyCoverage'])->name('system.backtesting.verify-coverage');
    Route::post('/backtesting/run', [BacktrackingController::class, 'run'])->name('system.backtesting.run');
    Route::post('/backtesting/toggle-approval', [BacktrackingController::class, 'toggleApproval'])->name('system.backtesting.toggle-approval');
    Route::post('/backtesting/ai-insights', [BacktrackingController::class, 'aiInsights'])
        ->middleware('throttle:10,1')
        ->name('system.backtesting.ai-insights');

    // Lifecycle — manual position-lifecycle configurator. Each
    // scenario is an Excel-style spreadsheet where columns are
    // T-frames and rows are token positions; the operator drives
    // the cascade event by event and the JS engine recomputes
    // WAP / TP / SL / PnL on every edit.
    Route::get('/lifecycle', [LifecycleController::class, 'index'])->name('system.lifecycle');
    Route::get('/lifecycle/create', [LifecycleController::class, 'create'])->name('system.lifecycle.create');
    Route::post('/lifecycle', [LifecycleController::class, 'store'])->name('system.lifecycle.store');
    Route::get('/lifecycle/{scenario}', [LifecycleController::class, 'show'])->name('system.lifecycle.show');
    Route::get('/lifecycle/{scenario}/data', [LifecycleController::class, 'data'])->name('system.lifecycle.data');
    Route::post('/lifecycle/{scenario}/frames', [LifecycleController::class, 'addFrame'])->name('system.lifecycle.frame.add');
    Route::delete('/lifecycle/{scenario}/frames/{frame}', [LifecycleController::class, 'deleteFrame'])->name('system.lifecycle.frame.delete');
    Route::put('/lifecycle/{scenario}/frames/{frame}/events', [LifecycleController::class, 'saveFrameEvents'])->name('system.lifecycle.frame.events');
    Route::post('/lifecycle/{scenario}/branch', [LifecycleController::class, 'branch'])->name('system.lifecycle.branch');
    Route::delete('/lifecycle/{scenario}', [LifecycleController::class, 'destroy'])->name('system.lifecycle.destroy');

    // Users admin — list every user, view billing state, apply manual
    // wallet credits/debits via the wallet_transactions ledger,
    // change a user's subscription tier.
    Route::get('/users/{user?}', [SystemUsersController::class, 'index'])->name('system.users');
    Route::post('/users/{user}/credit', [SystemUsersController::class, 'adjustCredit'])->name('system.users.credit');
    Route::post('/users/{user}/subscription', [SystemUsersController::class, 'changeSubscription'])->name('system.users.subscription');
    Route::post('/users/{user}/active-account', [SystemUsersController::class, 'changeActiveAccount'])->name('system.users.active-account');
    Route::post('/users/{user}/start-trial', [SystemUsersController::class, 'startTrial'])->name('system.users.start-trial');
    Route::post('/users/{user}/trial-days', [SystemUsersController::class, 'changeTrialDays'])->name('system.users.trial-days');
    Route::post('/users/{user}/password-reset', [SystemUsersController::class, 'sendPasswordResetLink'])->name('system.users.password-reset');

    // Billing plan management — sysadmin CRUD over subscription tiers.
    Route::get('/billing/plans', [SystemBillingPlansController::class, 'index'])->name('system.billing.plans');
    Route::post('/billing/plans', [SystemBillingPlansController::class, 'store'])->name('system.billing.plans.store');
    Route::post('/billing/plans/{subscription}', [SystemBillingPlansController::class, 'update'])->name('system.billing.plans.update');
    Route::post('/billing/plans/{subscription}/delete', [SystemBillingPlansController::class, 'destroy'])->name('system.billing.plans.delete');

    // Top-up coin curated list + engine knobs.
    Route::get('/billing/coins', [SystemBillingCoinsController::class, 'index'])->name('system.billing.coins');
    Route::post('/billing/coins', [SystemBillingCoinsController::class, 'store'])->name('system.billing.coins.store');
    Route::post('/billing/coins/engine', [SystemBillingCoinsController::class, 'updateEngine'])->name('system.billing.coins.engine');
    Route::post('/billing/coins/{coin}', [SystemBillingCoinsController::class, 'update'])->name('system.billing.coins.update');
    Route::post('/billing/coins/{coin}/delete', [SystemBillingCoinsController::class, 'destroy'])->name('system.billing.coins.delete');

    // UI Components showcase — dev-only surface, never reachable in prod.
    if (! app()->isProduction()) {
        Route::get('/ui-components', [UiComponentsController::class, 'index'])->name('system.ui-components');
    }
});
