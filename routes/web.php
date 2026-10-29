<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', [PageController::class, 'dashboard'])->name('dashboard');
    Route::get('/leads', [PageController::class, 'leads']);
    Route::get('/leads/{id}', [PageController::class, 'lead'])->whereNumber('id');
    Route::get('/chats', [PageController::class, 'chats']);
    Route::get('/websites', [PageController::class, 'websites']);
    Route::get('/websites/{id}', [PageController::class, 'website'])->whereNumber('id');
    Route::get('/team', [PageController::class, 'team']);
    Route::get('/brands', [PageController::class, 'brands']);
    Route::get('/invoices', [PageController::class, 'invoices']);
    Route::get('/finance', [PageController::class, 'finance']);
    Route::get('/reports', [PageController::class, 'reports']);
});
