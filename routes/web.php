<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SupportFormController;
use App\Http\Controllers\Webhooks\CardcomWebhookController;
use App\Http\Controllers\Webhooks\EmailWebhookController;
use App\Http\Controllers\Webhooks\WahaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

/*
 | Public support form — the web intake channel for tickets. CSRF-protected,
 | rate limited, honeypot-guarded.
 */
Route::get('/support', [SupportFormController::class, 'show'])->name('support.form');
Route::post('/support', [SupportFormController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('support.form.store');

/*
 | Public self-signup — the link the team sends to a prospect. The customer
 | picks a plan and enters their details, then is handed off to Cardcom's hosted
 | page to enter a card. CSRF-protected, rate limited, honeypot-guarded.
 */
Route::get('/join', [SignupController::class, 'show'])->name('signup');
Route::post('/join', [SignupController::class, 'store'])
    ->middleware('throttle:8,1')
    ->name('signup.store');

/*
 | Customer-facing billing links (embedded in dunning messages).
 | Signed URLs prevent customer-id enumeration; throttled against abuse.
 */
Route::get('/billing/update-card/{customer}', [BillingController::class, 'updateCard'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('billing.update-card');

Route::view('/billing/update-card/done/{result}', 'billing.update-card-done')
    ->where('result', 'success|failed')
    ->name('billing.update-card.done');

/*
 | Inbound webhooks. Secret verification happens inside each controller
 | (hash_equals against the configured shared secret); CSRF is excluded in
 | bootstrap/app.php. Everything is recorded in webhook_events and processed
 | on the queue.
 */
Route::middleware('throttle:120,1')->prefix('webhooks')->group(function () {
    Route::post('/cardcom', CardcomWebhookController::class)->name('webhooks.cardcom');
    Route::post('/waha', WahaWebhookController::class)->name('webhooks.waha');
    Route::post('/email', EmailWebhookController::class)->name('webhooks.email');
});
