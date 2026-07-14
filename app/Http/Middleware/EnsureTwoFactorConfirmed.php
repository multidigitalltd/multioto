<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the admin panel behind a confirmed one-time code for members who have
 * 2FA enabled. Sits after Authenticate: once logged in, a member who still owes
 * a code is bounced to the challenge screen until they confirm it. The logout
 * route is always allowed through so a member can never get stuck.
 */
class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Let a member log out even before confirming, so an unreachable 2FA
        // channel is never a dead end.
        if ($user === null || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        if ($user->requiresTwoFactor() && ! session()->get('two_factor.confirmed', false)) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
