<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\TaskObserver;
use App\Observers\TicketObserver;
use App\Services\Hosting\FlyWpHostingClient;
use App\Services\Hosting\HostingClient;
use App\Services\Hosting\LogHostingClient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The concrete hosting panel API is still an open decision (§13).
        // Swap the driver here once it lands; 'log' records intents only.
        $this->app->bind(HostingClient::class, function () {
            return match (config('billing.hosting.driver')) {
                'flywp' => new FlyWpHostingClient,
                default => new LogHostingClient,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS for all generated URLs (signed card-update links, assets,
        // redirects) whenever the app is served over TLS — behind Caddy/nginx
        // the app itself sees plain HTTP, so key off APP_URL.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Every date/time picker in the panel opens on a Sunday-first calendar
        // with Hebrew day/month names — the Israeli work week, not the Filament
        // default (Monday, English). native(false) is required: the native HTML
        // input ignores locale()/weekStartsOnSunday() and lets the browser decide,
        // so we force the JS picker that honours them. DatePicker extends
        // DateTimePicker but their configureUsing hooks are per-class → configure both.
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->native(false)->weekStartsOnSunday()->locale('he'));
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->native(false)->weekStartsOnSunday()->locale('he'));

        // Lifecycle notifications (e.g. "your ticket was resolved").
        Ticket::observe(TicketObserver::class);
        Task::observe(TaskObserver::class);

        // Team-action audit trail: attribute create/update/delete of these models
        // to the signed-in panel user (system/queue writes have no auth user and
        // are skipped). One observer, registered on each audited model.
        foreach ([Customer::class, Site::class, Subscription::class, Charge::class,
            Plan::class, Task::class, Ticket::class, User::class, NotificationTemplate::class] as $audited) {
            $audited::observe(AuditObserver::class);
        }

        // A fresh password login must earn a fresh one-time code: clear any
        // previous 2FA confirmation so the challenge middleware fires again.
        // Logout wipes it too, so a shared browser can't inherit confirmation.
        Event::listen(Login::class, function (Login $event): void {
            session()->forget('two_factor.confirmed');

            // A 2FA-required user is NOT fully signed in until the code is
            // verified — that login is audited by TwoFactorChallengeController on
            // confirmation. Audit here only users who finish at the password step,
            // so a failed/abandoned 2FA challenge is never logged as a login.
            $user = $event->user;

            if (method_exists($user, 'requiresTwoFactor') && $user->requiresTwoFactor()) {
                return;
            }

            AuditLog::record('login', 'התחברות למערכת', actor: $user);
        });
        Event::listen(Logout::class, function (Logout $event): void {
            session()->forget('two_factor.confirmed');
            AuditLog::record('logout', 'התנתקות מהמערכת', actor: $event->user);
        });
    }
}
