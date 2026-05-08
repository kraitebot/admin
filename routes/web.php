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
use App\Http\Controllers\System\LifecycleController;
use App\Http\Controllers\System\SqlQueryController;
use App\Http\Controllers\System\StepDispatcherController;
use App\Http\Controllers\System\UiComponentsController;
use App\Http\Controllers\System\BillingCoinsController as SystemBillingCoinsController;
use App\Http\Controllers\System\BillingPlansController as SystemBillingPlansController;
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

        // Steps — two prefix-isolated dispatcher fleets share one
        // controller. `default` = `steps_*` tables (calculation churn);
        // `trading` = `trading_steps_*` (trade-critical workflow).
        // Cooling-down endpoint returns BOTH fleets' MaintenanceMode
        // pause state in one payload; toggle is per-fleet (independent,
        // no mutex). The cooling-down route is declared BEFORE the
        // catch-all `{prefix}` so the `whereIn` constraint is enough
        // to keep them disjoint.
        Route::get('/system/steps/cooling-down', [StepDispatcherController::class, 'coolingDown'])->name('system.steps.cooling-down');
        Route::post('/system/steps/{prefix}/toggle-cooling-down', [StepDispatcherController::class, 'toggleCoolingDown'])
            ->whereIn('prefix', ['default', 'trading'])
            ->name('system.steps.toggle-cooling-down');
        Route::get('/system/steps/{prefix}', [StepDispatcherController::class, 'index'])
            ->whereIn('prefix', ['default', 'trading'])
            ->name('system.steps');
        Route::get('/system/steps/{prefix}/data', [StepDispatcherController::class, 'data'])
            ->whereIn('prefix', ['default', 'trading'])
            ->name('system.steps.data');
        Route::get('/system/steps/{prefix}/blocks', [StepDispatcherController::class, 'blocks'])
            ->whereIn('prefix', ['default', 'trading'])
            ->name('system.steps.blocks');
        Route::get('/system/steps/{prefix}/block-steps', [StepDispatcherController::class, 'blockSteps'])
            ->whereIn('prefix', ['default', 'trading'])
            ->name('system.steps.block-steps');

        // Backtesting — historical-candle ladder backtester.
        Route::get('/system/backtesting', [BacktrackingController::class, 'index'])->name('system.backtesting');
        Route::post('/system/backtesting/fetch-candles', [BacktrackingController::class, 'fetchCandles'])->name('system.backtesting.fetch-candles');
        Route::post('/system/backtesting/verify-coverage', [BacktrackingController::class, 'verifyCoverage'])->name('system.backtesting.verify-coverage');
        Route::post('/system/backtesting/run', [BacktrackingController::class, 'run'])->name('system.backtesting.run');
        Route::post('/system/backtesting/toggle-approval', [BacktrackingController::class, 'toggleApproval'])->name('system.backtesting.toggle-approval');
        Route::post('/system/backtesting/ai-insights', [BacktrackingController::class, 'aiInsights'])
            ->middleware('throttle:10,1')
            ->name('system.backtesting.ai-insights');

        // Lifecycle — manual position-lifecycle configurator. Each
        // scenario is an Excel-style spreadsheet where columns are
        // T-frames and rows are token positions; the operator drives
        // the cascade event by event and the JS engine recomputes
        // WAP / TP / SL / PnL on every edit.
        Route::get('/system/lifecycle', [LifecycleController::class, 'index'])->name('system.lifecycle');
        Route::get('/system/lifecycle/create', [LifecycleController::class, 'create'])->name('system.lifecycle.create');
        Route::post('/system/lifecycle', [LifecycleController::class, 'store'])->name('system.lifecycle.store');
        Route::get('/system/lifecycle/{scenario}', [LifecycleController::class, 'show'])->name('system.lifecycle.show');
        Route::get('/system/lifecycle/{scenario}/data', [LifecycleController::class, 'data'])->name('system.lifecycle.data');
        Route::post('/system/lifecycle/{scenario}/frames', [LifecycleController::class, 'addFrame'])->name('system.lifecycle.frame.add');
        Route::delete('/system/lifecycle/{scenario}/frames/{frame}', [LifecycleController::class, 'deleteFrame'])->name('system.lifecycle.frame.delete');
        Route::put('/system/lifecycle/{scenario}/frames/{frame}/events', [LifecycleController::class, 'saveFrameEvents'])->name('system.lifecycle.frame.events');
        Route::post('/system/lifecycle/{scenario}/branch', [LifecycleController::class, 'branch'])->name('system.lifecycle.branch');
        Route::delete('/system/lifecycle/{scenario}', [LifecycleController::class, 'destroy'])->name('system.lifecycle.destroy');

        // Users admin — list every user, view billing state, apply manual
        // wallet credits/debits via the wallet_transactions ledger,
        // change a user's subscription tier.
        Route::get('/system/users/{user?}', [SystemUsersController::class, 'index'])->name('system.users');
        Route::post('/system/users/{user}/credit', [SystemUsersController::class, 'adjustCredit'])->name('system.users.credit');
        Route::post('/system/users/{user}/subscription', [SystemUsersController::class, 'changeSubscription'])->name('system.users.subscription');
        Route::post('/system/users/{user}/active-account', [SystemUsersController::class, 'changeActiveAccount'])->name('system.users.active-account');
        Route::post('/system/users/{user}/start-trial', [SystemUsersController::class, 'startTrial'])->name('system.users.start-trial');
        Route::post('/system/users/{user}/trial-days', [SystemUsersController::class, 'changeTrialDays'])->name('system.users.trial-days');

        // Billing plan management — sysadmin CRUD over subscription tiers.
        Route::get('/system/billing/plans', [SystemBillingPlansController::class, 'index'])->name('system.billing.plans');
        Route::post('/system/billing/plans', [SystemBillingPlansController::class, 'store'])->name('system.billing.plans.store');
        Route::post('/system/billing/plans/{subscription}', [SystemBillingPlansController::class, 'update'])->name('system.billing.plans.update');
        Route::post('/system/billing/plans/{subscription}/delete', [SystemBillingPlansController::class, 'destroy'])->name('system.billing.plans.delete');

        // Top-up coin curated list + engine knobs.
        Route::get('/system/billing/coins', [SystemBillingCoinsController::class, 'index'])->name('system.billing.coins');
        Route::post('/system/billing/coins', [SystemBillingCoinsController::class, 'store'])->name('system.billing.coins.store');
        Route::post('/system/billing/coins/engine', [SystemBillingCoinsController::class, 'updateEngine'])->name('system.billing.coins.engine');
        Route::post('/system/billing/coins/{coin}', [SystemBillingCoinsController::class, 'update'])->name('system.billing.coins.update');
        Route::post('/system/billing/coins/{coin}/delete', [SystemBillingCoinsController::class, 'destroy'])->name('system.billing.coins.delete');

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
    Route::post('/billing/pause', [BillingController::class, 'pause'])->name('billing.pause');
    Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::post('/billing/topup', [BillingController::class, 'topUp'])->name('billing.topup');
    Route::get('/billing/min-amount', [BillingController::class, 'minAmount'])->name('billing.min-amount');
    Route::get('/billing/wallet-status', [BillingController::class, 'walletStatus'])->name('billing.wallet-status');
});

// Signed shortcut from email — pre-fills amount + coin from the last
// payment so the user doesn't retype anything. Bypasses the auth gate
// because the signed URL itself is the authentication.
Route::get('/billing/quick-topup', [BillingController::class, 'quickTopUp'])
    ->middleware('signed')
    ->name('billing.quick-topup');

// NOWPayments IPN webhook — public, signature-verified, CSRF-exempt.
Route::post('/webhooks/payments', [\App\Http\Controllers\NowPaymentsWebhookController::class, 'handle'])
    ->middleware(\App\Http\Middleware\VerifyNowPaymentsSignature::class)
    ->name('webhooks.payments');

require __DIR__.'/auth.php';
