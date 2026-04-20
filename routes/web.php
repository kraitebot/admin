<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\System\CommandsController;
use App\Http\Controllers\System\HeartbeatController;
use App\Http\Controllers\System\SqlQueryController;
use App\Http\Controllers\System\StepDispatcherController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Dashboard
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');

    // Accounts
    Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts');
    Route::get('/accounts/data', [AccountsController::class, 'data'])->name('accounts.data');

    // System
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

    // Heartbeat
    Route::get('/system/heartbeat', [HeartbeatController::class, 'index'])->name('system.heartbeat');
    Route::get('/system/heartbeat/data', [HeartbeatController::class, 'data'])->name('system.heartbeat.data');
});

require __DIR__.'/auth.php';
