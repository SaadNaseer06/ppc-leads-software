<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\NativeChatController;
use App\Http\Controllers\Api\TawkWebhookController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Support\Facades\Route;

// --- Public -------------------------------------------------------------------

// Lead capture from client websites (embed script, form webhooks, Zapier...)
Route::post('/intake/{key}', [IntakeController::class, 'store'])->middleware('throttle:intake');
// Tawk.to webhooks (HMAC-signed)
Route::post('/webhooks/tawk', TawkWebhookController::class);
// Dashboard sign-in: email + password -> API token
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Native live chat widget (throttled; auth via visitor_token)
Route::middleware('throttle:chat')->prefix('chat')->group(function () {
    Route::post('/session', [NativeChatController::class, 'session']);
    Route::post('/visitor', [NativeChatController::class, 'visitor']);
    Route::post('/messages', [NativeChatController::class, 'storeMessage']);
    Route::get('/messages', [NativeChatController::class, 'messages']);
    Route::post('/auth', [NativeChatController::class, 'auth']);
});

// --- Signed-in dashboard users -------------------------------------------------

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::get('/live', [DashboardController::class, 'live']);
    Route::get('/lookups', [DashboardController::class, 'lookups']);

    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::post('/leads/bulk', [LeadController::class, 'bulk']);
    Route::get('/leads/export', [LeadController::class, 'export']);
    Route::get('/leads/{id}', [LeadController::class, 'show'])->whereNumber('id');
    Route::patch('/leads/{id}', [LeadController::class, 'update'])->whereNumber('id');
    Route::post('/leads/{id}/notes', [LeadController::class, 'addNote'])->whereNumber('id');

    Route::get('/chats', [ChatController::class, 'index']);
    Route::post('/chats/{id}/messages', [ChatController::class, 'storeMessage'])->whereNumber('id');
    Route::post('/chats/{id}/end', [ChatController::class, 'end'])->whereNumber('id');

    Route::get('/websites', [WebsiteController::class, 'index']);
    Route::post('/websites', [WebsiteController::class, 'store']);
    Route::get('/websites/{id}', [WebsiteController::class, 'show'])->whereNumber('id');
    Route::patch('/websites/{id}', [WebsiteController::class, 'update'])->whereNumber('id');
    Route::delete('/websites/{id}', [WebsiteController::class, 'destroy'])->whereNumber('id');

    Route::get('/admin/users', [UserController::class, 'index']);
    Route::post('/admin/users', [UserController::class, 'store']);
    Route::patch('/admin/users/{id}', [UserController::class, 'update'])->whereNumber('id');

    Route::get('/admin/brands', [BrandController::class, 'index']);
    Route::post('/admin/brands', [BrandController::class, 'store']);
    Route::patch('/admin/brands', [BrandController::class, 'update']);
    Route::delete('/admin/brands/{id}', [BrandController::class, 'destroy'])->whereNumber('id');

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id');
    Route::get('/finance', [InvoiceController::class, 'finance']);
    Route::post('/finance/bulk-pdf', [InvoiceController::class, 'bulkPdf']);
    Route::get('/reports/sales-by-brand', [InvoiceController::class, 'salesByBrand']);
});
