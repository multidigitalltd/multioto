<?php

use App\Http\Controllers\Agent\AgentPluginController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CsatController;
use App\Http\Controllers\CustomerCardPdfController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationKeysFallbackController;
use App\Http\Controllers\MarketingPreferencesController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SupportAttachmentController;
use App\Http\Controllers\SupportFormController;
use App\Http\Controllers\TasksPrintController;
use App\Http\Controllers\Webhooks\CardcomWebhookController;
use App\Http\Controllers\Webhooks\EmailDeliveryWebhookController;
use App\Http\Controllers\Webhooks\EmailWebhookController;
use App\Http\Controllers\Webhooks\WahaWebhookController;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\ThrottleHealthProbe;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Team-only app: the root just sends visitors to the admin panel.
Route::redirect('/', '/admin');

/*
 | Is the machinery running? Asked from OUTSIDE, because the scheduler cannot
 | report that the scheduler has stopped and a queue with no worker raises
 | nothing at all. 200 = running, 503 = a moving part has stopped; details only
 | with the health token. Point an uptime monitor here (docs/deployment.md).
 */
Route::get('/health', HealthController::class)
    // Rate limited without the database: the ordinary throttle counts hits in
    // the default cache store, which here IS the database (see the middleware).
    ->middleware(ThrottleHealthProbe::class.':60')
    // Stateless on purpose. The default session driver is the database, and
    // the session is opened by middleware BEFORE the controller runs — so the
    // one request whose whole job is to report a broken database would be the
    // request that hangs on it, and the monitor would get a gateway timeout
    // instead of the 503 that says which part stopped. Nothing here reads a
    // session, a cookie or a form.
    ->withoutMiddleware([
        AddQueuedCookiesToResponse::class,
        EncryptCookies::class,
        ValidateCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('health');

// Public business logo — a stable hosted URL for emails (which can't use
// data: URIs) and other public surfaces. Cached; 404 when no logo is set.
Route::get('/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

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
// Friendly alias matching the business site's terminology.
Route::redirect('/new-client', '/join');
Route::view('/join/thanks', 'signup.thanks')->name('signup.thanks');

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

// Payment-demand link: our own signed URL that redirects to the Cardcom page
// while payable, so a canceled demand can show "לא פעיל" instead of forwarding.
Route::get('/billing/pay/{charge}', [BillingController::class, 'pay'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('billing.pay');

// One-tap Bit variant of the payment link — same signed, cancelable gateway,
// forwarding to Cardcom's direct Bit URL.
Route::get('/billing/pay/{charge}/bit', [BillingController::class, 'payBit'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('billing.pay-bit');

// Customer satisfaction (CSAT) rating for a resolved ticket. The GET form and
// the POST both carry the same signature (the form posts back to the signed
// URL), so a rating can't be submitted for an unsigned/forged ticket link.
Route::get('/support/rate/{ticket}', [CsatController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('csat.show');
Route::post('/support/rate/{ticket}', [CsatController::class, 'store'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('csat.store');

/*
 | Marketing opt-out. Every advertising message carries the signed link below,
 | as חוק התקשורת ס' 30א requires; the signature stops the customer id being
 | swapped to unsubscribe somebody else. The confirmation page is unsigned —
 | it carries no customer data and no action.
 */
Route::get('/marketing/unsubscribe/{customer}', [MarketingPreferencesController::class, 'unsubscribe'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('marketing.unsubscribe');
// Restoring consent is a POST behind CSRF, unlike the unsubscribe above: a
// link previewer or mail-security crawler that follows every URL in the page
// would otherwise silently re-enrol a customer who just opted out. Unsubscribe
// stays a plain GET on purpose — a crawler following it errs on the safe side,
// and the law wants that link to work on a single click.
Route::post('/marketing/resubscribe/{customer}', [MarketingPreferencesController::class, 'resubscribe'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('marketing.resubscribe');
Route::get('/marketing/resubscribed', [MarketingPreferencesController::class, 'resubscribed'])
    ->middleware('throttle:60,1')
    ->name('marketing.resubscribed');

/*
 | Inbound support attachments — served only to a signed-in team member
 | (panel auth). Files live on a private disk; this is the sole read path.
 */
Route::get('/support/attachments/{message}/{index}', SupportAttachmentController::class)
    ->middleware(['web', 'auth'])
    ->whereNumber('index')
    ->name('support.attachment');

/*
 | Google sign-in. Guest-only by nature and rate limited, because the callback
 | is a public URL that anybody may hit. It signs in ONLY an address that is
 | already a user — the controller says why at length — and the 2FA gate that
 | follows is untouched: this establishes who you are, it does not waive the
 | second step.
 */
Route::middleware(['web'])->group(function () {
    Route::get('/auth/google', [GoogleLoginController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleLoginController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('auth.google.callback');
});

/*
 | One-time-code (2FA) challenge. Deliberately OUTSIDE the admin panel so the
 | panel's EnsureTwoFactorConfirmed middleware never applies here — otherwise a
 | member owing a code would be redirected in a loop. Auth-only: the member is
 | already logged in by password and now confirms the code.
 */
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/two-factor', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('two-factor.verify');
    Route::post('/two-factor/resend', [TwoFactorChallengeController::class, 'resend'])
        ->middleware('throttle:10,1')
        ->name('two-factor.resend');
});

// Print-friendly list of all open tasks — team-only (panel auth).
Route::get('/tasks/print', TasksPrintController::class)
    ->middleware(['web', 'auth'])
    ->name('tasks.print');

// Classic (non-Livewire) fallback save for the security API keys — admin-only
// (enforced in the controller), same 2FA gate as the panel. Exists because the
// Livewire save button can be broken client-side (extensions/blocked JS) and
// the operator still needs a dependable way to store the keys.
Route::post('/integrations/security-keys', [IntegrationKeysFallbackController::class, 'save'])
    ->middleware(['web', 'auth', EnsureTwoFactorConfirmed::class, 'throttle:20,1'])
    ->name('integrations.security-keys.fallback');
Route::post('/integrations/security-keys/test', [IntegrationKeysFallbackController::class, 'test'])
    ->middleware(['web', 'auth', EnsureTwoFactorConfirmed::class, 'throttle:10,1'])
    ->name('integrations.security-keys.test');

// Browser push subscription store/remove — team-only, scoped to the signed-in
// user by the controller. Gated by the same 2FA confirmation as the panel, so a
// session that passed the password but not the second factor can't register an
// endpoint and start receiving team-notification content.
Route::middleware(['web', 'auth', EnsureTwoFactorConfirmed::class])->group(function () {
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('push-subscriptions.store');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');
});

// Customer signup signature (consent record) — team-only, private disk.
Route::get('/customers/{customer}/signature', SignatureController::class)
    ->middleware(['web', 'auth'])
    ->name('customer.signature');

// Signed customer-card PDF (details + signature) — team-only, private disk.
Route::get('/customers/{customer}/card-pdf', CustomerCardPdfController::class)
    ->middleware(['web', 'auth'])
    ->name('customer.card-pdf');

/*
 | Customer self-service portal — a password-less area (magic-link sign-in) for
 | customers to view invoices, check ticket status and replace their card.
 | Sign-in and the signed magic link are public; everything else is gated by the
 | portal.customer session guard, which scopes every query to the signed-in
 | customer.
 */
Route::prefix('portal')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'show'])->name('portal.login');
    Route::post('/login', [PortalAuthController::class, 'sendLink'])
        ->middleware('throttle:6,1')
        ->name('portal.login.send');
    Route::get('/auth/{customer}', [PortalAuthController::class, 'authenticate'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('portal.auth');
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('portal.logout');

    Route::middleware('portal.customer')->group(function () {
        Route::get('/', [PortalController::class, 'dashboard'])->name('portal.dashboard');
        Route::get('/debt', [PortalController::class, 'debt'])->name('portal.debt');
        Route::get('/invoices', [PortalController::class, 'invoices'])->name('portal.invoices');
        Route::get('/tickets', [PortalController::class, 'tickets'])->name('portal.tickets');
        Route::post('/tickets', [PortalController::class, 'storeTicket'])
            ->middleware('throttle:10,1')->name('portal.tickets.store');
        Route::get('/card', [PortalController::class, 'updateCard'])->name('portal.card');
    });
});

/*
 | Companion-plugin remote-update channel. The site's plugin checks in with its
 | per-site bearer token to learn about a newer version and gets a short-lived
 | signed link to download it — so a new plugin version is shipped once and every
 | site updates itself. The download is authenticated by the signature alone.
 */
Route::prefix('agent')->group(function () {
    Route::get('/plugin/update', [AgentPluginController::class, 'update'])
        ->middleware(['agent.site', 'throttle:60,1'])
        ->name('agent.plugin.update');
    Route::get('/plugin/download/{version}', [AgentPluginController::class, 'download'])
        ->middleware(['signed', 'throttle:30,1'])
        ->where('version', '[0-9]+\.[0-9]+\.[0-9]+')
        ->name('agent.plugin.download');

    // Admin-only download of the current plugin build (the copy shipped in the
    // repo), so a manager can grab the ZIP straight from the panel to install on
    // a customer's site. Guarded by the panel login + an admin check.
    Route::get('/plugin/latest', [AgentPluginController::class, 'latest'])
        ->middleware(['web', 'auth', 'throttle:30,1'])
        ->name('agent.plugin.latest');
});

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
    // Delivery / open / bounce / spam-complaint events for mail WE sent.
    Route::post('/email/delivery', EmailDeliveryWebhookController::class)->name('webhooks.email.delivery');
});
