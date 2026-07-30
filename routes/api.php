<?php

declare(strict_types=1);

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PasskeyController;
use App\Http\Controllers\Api\V1\PositionsController;
use App\Http\Controllers\Api\V1\ProjectionsController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::domain(config('domains.api'))->group(function (): void {
    Route::get('/.well-known/apple-app-site-association', fn () => response()->json([
        'applinks' => (object) [],
        'webcredentials' => [
            'apps' => ['FWCW3LG29Y.com.kraite.app'],
        ],
        'appclips' => (object) [],
    ]));

    Route::prefix('v1')->group(function (): void {
        Route::post('/auth/token', [AuthController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::get('/auth/passkey/options', [PasskeyController::class, 'authenticationOptions'])
            ->middleware('throttle:10,1');
        Route::post('/auth/passkey/token', [PasskeyController::class, 'authenticate'])
            ->middleware('throttle:10,1');

        Route::middleware(['auth:sanctum', 'ability:dashboard:read'])->group(function (): void {
            Route::delete('/auth/token', [AuthController::class, 'destroy'])
                ->middleware('throttle:10,1');
            Route::get('/dashboard', DashboardController::class)
                ->middleware('throttle:30,1');
            Route::get('/positions', PositionsController::class)
                ->middleware('throttle:30,1');
            Route::get('/projections', ProjectionsController::class)
                ->middleware('throttle:30,1');
            Route::get('/notifications', NotificationController::class)
                ->middleware('throttle:30,1');
            Route::put('/push-devices/current', [PushDeviceController::class, 'store'])
                ->middleware('throttle:10,1');
            Route::get('/accounts', [AccountController::class, 'mobileData'])
                ->name('api.accounts.index')
                ->middleware('throttle:30,1');
            Route::get('/passkeys', [PasskeyController::class, 'index'])
                ->middleware('throttle:30,1');
            Route::get('/passkeys/register/options', [PasskeyController::class, 'registrationOptions'])
                ->middleware('throttle:10,1');
            // Answering the "you appear to be in <country>" offer. A read token
            // is enough: this only records a reporting preference, and refusing
            // it here would leave the phone unable to dismiss its own dialog.
            Route::post('/profile/day-basis-hint', [ProfileController::class, 'resolveDayBasisHint'])
                ->middleware('throttle:10,1');
            Route::post('/passkeys', [PasskeyController::class, 'store'])
                ->middleware('throttle:10,1');
            Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy'])
                ->middleware('throttle:10,1');

            // Anything that can change live trading configuration sits
            // behind a second ability, so a read-only token issued before
            // the app gained account editing can never mutate an account.
            Route::middleware('ability:accounts:write')->group(function (): void {
                Route::get('/accounts/quotes', [AccountController::class, 'quotes'])
                    ->name('api.accounts.quotes')
                    ->middleware('throttle:10,1');
                Route::patch('/accounts', [AccountController::class, 'update'])
                    ->name('api.accounts.update')
                    ->middleware('throttle:10,1');
                Route::patch('/accounts/trading/disable', [AccountController::class, 'disableTrading'])
                    ->name('api.accounts.trading.disable')
                    ->middleware('throttle:10,1');
            });
        });
    });
});
