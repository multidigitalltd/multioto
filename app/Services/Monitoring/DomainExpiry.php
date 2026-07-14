<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Look up a domain's registration expiry date. Uses RDAP (the HTTPS/JSON
 * successor to WHOIS) via the rdap.org bootstrap, since raw WHOIS (TCP/43)
 * isn't reachable through an HTTPS-only egress. Best-effort: returns null when
 * the TLD has no RDAP service or the lookup fails — the caller then leaves the
 * cached value untouched (exactly like the SSL check).
 */
class DomainExpiry
{
    public function expiresAt(string $domain): ?Carbon
    {
        $registrable = $this->registrableDomain($domain);

        if ($registrable === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->acceptJson()
                ->get('https://rdap.org/domain/'.$registrable);

            if (! $response->ok()) {
                return null;
            }

            foreach ($response->json('events') ?? [] as $event) {
                if (($event['eventAction'] ?? null) === 'expiration' && filled($event['eventDate'] ?? null)) {
                    // An absolute timestamp — Carbon::parse doesn't need "now".
                    return Carbon::parse($event['eventDate']);
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Reduce a host to its registrable domain for the RDAP query: drop scheme,
     * path and a leading "www.", then keep the last two labels — or three when
     * the second-level is a public SLD (example.co.il → example.co.il).
     */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0];
        $host = (string) preg_replace('/^www\./', '', $host);
        $host = trim($host, '.');

        if ($host === '') {
            return '';
        }

        $labels = explode('.', $host);
        $n = count($labels);

        if ($n <= 2) {
            return $host;
        }

        $secondLevel = ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu', 'muni', 'idf', 'k12'];

        return in_array($labels[$n - 2], $secondLevel, true)
            ? implode('.', array_slice($labels, $n - 3))
            : implode('.', array_slice($labels, $n - 2));
    }
}
