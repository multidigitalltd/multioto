<?php

namespace App\Providers;

use App\Services\Hosting\FlyWpHostingClient;
use App\Services\Hosting\HostingClient;
use App\Services\Hosting\LogHostingClient;
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
    }
}
