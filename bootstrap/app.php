<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The app sits behind a reverse proxy (Caddy/nginx) in production;
        // trust its X-Forwarded-* headers so HTTPS is detected correctly.
        $middleware->trustProxies(at: '*');

        // Baseline security response headers on every request.
        $middleware->append(SecurityHeaders::class);

        // External providers can't send CSRF tokens; these endpoints verify a
        // shared secret instead (see the webhook controllers).
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
