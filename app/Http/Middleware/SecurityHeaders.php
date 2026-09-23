<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline security response headers on every request. Defensive hardening
 * against clickjacking, MIME sniffing, referrer leakage and mixed content.
 * HSTS is sent only over HTTPS so it can't lock out a plain-HTTP dev box.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set(
            config('security.csp.report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy',
            $this->contentSecurityPolicy(),
        );

        return $response;
    }

    /**
     * What this page is allowed to load, and from where.
     *
     * The panel renders HTML written by strangers — the body of an inbound
     * support email. It is put through an allow-list sanitizer first, and that
     * is the wall; this is what stands behind it if the wall ever has a gap.
     *
     * Every host below was found by reading what the application actually
     * loads, not by guessing:
     *
     * - `frame-src` carries Cardcom because the card-entry page is embedded in
     *   an iframe (signup, card replacement, manual charge). A policy without
     *   it would take down every card entry in the system — the single most
     *   expensive thing this header could break.
     * - `font-src`/`style-src` carry Google Fonts, the only external asset the
     *   panel loads.
     * - `img-src` carries ui-avatars.com, which Filament uses for a team member
     *   with no picture of their own.
     *
     * `script-src` and `style-src` keep `unsafe-inline`, and scripts also need
     * `unsafe-eval`, because Alpine evaluates its expressions at runtime and
     * Filament writes both inline throughout. So this does NOT stop injected
     * inline script; what it does stop is that script reaching anywhere — no
     * foreign origin to load code from, and `connect-src 'self'` leaves nowhere
     * to send what it stole. Closing the inline hole needs per-request nonces
     * through every Filament view, which is a change of its own and not one to
     * make quietly alongside a dependency bump.
     */
    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // A <base> tag from injected markup would silently re-point every
            // relative URL on the page, including the ones forms post to.
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            // Nothing in this application posts a form off-site; Cardcom is
            // reached by redirect and by the iframe below, never by a form.
            "form-action 'self'",
            "img-src 'self' data: blob: https://ui-avatars.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "connect-src 'self'",
            'frame-src '.implode(' ', ["'self'", ...config('security.csp.frame_src', [])]),
            // The web-push service worker is served from our own origin.
            "worker-src 'self' blob:",
        ]);
    }
}
