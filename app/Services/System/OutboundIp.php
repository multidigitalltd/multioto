<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The public (egress) IP address our server uses for outbound requests — the
 * address a customer's Cloudflare sees when the agent connects. Shown to the
 * operator (to whitelist) and used by the one-click Cloudflare whitelist.
 * Cached for a day; it rarely changes.
 */
class OutboundIp
{
    private const CACHE_KEY = 'system.outbound_ip';

    /** @var list<string> IP-echo endpoints tried in order. */
    private const ECHO_URLS = ['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'];

    /** Our public egress IP, or null if it couldn't be determined. */
    public function current(): ?string
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function (): ?string {
            foreach (self::ECHO_URLS as $url) {
                try {
                    $ip = trim((string) Http::timeout(5)->get($url)->body());

                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                } catch (\Throwable) {
                    // Try the next echo service.
                }
            }

            return null;
        });
    }

    /** Drop the cached value (e.g. after a server migration). */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
