<?php

namespace App\Providers;

use App\Models\Task;
use App\Models\Ticket;
use App\Observers\TaskObserver;
use App\Observers\TicketObserver;
use App\Services\Hosting\FlyWpHostingClient;
use App\Services\Hosting\HostingClient;
use App\Services\Hosting\LogHostingClient;
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

        // Lifecycle notifications (e.g. "your ticket was resolved").
        Ticket::observe(TicketObserver::class);
        Task::observe(TaskObserver::class);

        // A fresh password login must earn a fresh one-time code: clear any
        // previous 2FA confirmation so the challenge middleware fires again.
        // Logout wipes it too, so a shared browser can't inherit confirmation.
        Event::listen(Login::class, fn () => session()->forget('two_factor.confirmed'));
        Event::listen(Logout::class, fn () => session()->forget('two_factor.confirmed'));
    }
}
