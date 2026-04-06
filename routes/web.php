<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\System\SqlQueryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // System
    Route::get('/system/sql-query', [SqlQueryController::class, 'index'])->name('system.sql-query');
    Route::post('/system/sql-query', [SqlQueryController::class, 'execute'])->name('system.sql-query.execute');
    Route::get('/system/sql-query/tables', [SqlQueryController::class, 'tables'])->name('system.sql-query.tables');
});

require __DIR__.'/auth.php';
