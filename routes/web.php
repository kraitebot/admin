<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Accounts\PositionsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BscsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NowPaymentsWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectionsController;
use App\Http\Controllers\RegistrationController;
use App\Http\Middleware\VerifyNowPaymentsSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared routes (any host)
|--------------------------------------------------------------------------
| Root redirect, auth (required at the bottom), and the NOWPayments
| webhook stay host-agnostic: login must work on both surfaces, and the
| webhook URL must keep answering regardless of which host the provider
| was configured with.
*/

Route::get('/', function () {
    return redirect()->route('login');
});

// NOWPayments IPN webhook — public, signature-verified, CSRF-exempt.
Route::post('/webhooks/payments', [NowPaymentsWebhookController::class, 'handle'])
    ->middleware(VerifyNowPaymentsSignature::class)
    ->name('webhooks.payments');

/*
|--------------------------------------------------------------------------
| Trader surface — admin domain
|--------------------------------------------------------------------------
| The client product. Everything a trader can see lives here; the
| sysadmin surface is a separate host (see routes/console-web.php).
*/

Route::domain(config('domains.admin'))->group(function () {

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/register/{uuid}', [RegistrationController::class, 'show'])
            ->whereUuid('uuid')
            ->name('register.show');
        Route::post('/register/{uuid}/connectivity', [RegistrationController::class, 'testConnectivity'])
            ->whereUuid('uuid')
            ->name('register.connectivity');
        Route::post('/register/{uuid}', [RegistrationController::class, 'store'])
            ->whereUuid('uuid')
            ->name('register.store');
    });

    Route::get('/register/{uuid}/connectivity/{blockUuid}', [RegistrationController::class, 'connectivityStatus'])
        ->middleware('throttle:60,1')
        ->whereUuid('uuid')
        ->whereUuid('blockUuid')
        ->name('register.connectivity.status');

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

    Route::middleware('auth')->group(function () {
        Route::view('/2', 'prototypes.dashboard-v2')->name('dashboard.prototype');

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
        // are operator-only and live on the console surface.
        Route::get('/bscs', [BscsController::class, 'index'])->name('bscs');
        Route::get('/bscs/data', [BscsController::class, 'data'])->name('bscs.data');

        // Accounts
        Route::get('/accounts/positions', [PositionsController::class, 'index'])->name('accounts.positions');
        Route::get('/accounts/positions/data', [PositionsController::class, 'data'])->name('accounts.positions.data');
        Route::get('/accounts/positions/history', [PositionsController::class, 'history'])->name('accounts.positions.history');

        Route::get('/accounts/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::get('/accounts/edit/quotes', [AccountController::class, 'quotes'])->name('accounts.quotes');
        Route::patch('/accounts/edit', [AccountController::class, 'update'])->name('accounts.update');
        Route::patch('/accounts/connectivity/credentials', [AccountController::class, 'saveCredentials'])->name('accounts.connectivity.credentials');
        Route::post('/accounts/connectivity/test', [AccountController::class, 'testConnectivity'])->middleware('throttle:10,1')->name('accounts.connectivity.test');
        Route::get('/accounts/connectivity/{blockUuid}', [AccountController::class, 'connectivityStatus'])
            ->middleware('throttle:60,1')
            ->whereUuid('blockUuid')
            ->name('accounts.connectivity.status');

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
});

require __DIR__.'/console-web.php';
require __DIR__.'/auth.php';
