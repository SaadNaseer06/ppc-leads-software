<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PayController;
use Illuminate\Support\Facades\Route;

// What the client sees: an invoice and how to pay it. The token in the link is the credential.
Route::middleware('throttle:pay')->prefix('pay/{token}')->whereAlphaNumeric('token')->group(function () {
    Route::get('/', [PayController::class, 'show']);
    Route::post('/intent', [PayController::class, 'intent']);
    Route::get('/return', [PayController::class, 'returned']);
    Route::get('/pdf', [PayController::class, 'pdf']);
});
Route::get('/pay-domain-check', [PayController::class, 'domainProof'])->middleware('throttle:pay');

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
    Route::get('/payments', [PageController::class, 'payments']);
    Route::get('/reports', [PageController::class, 'reports']);
});
