<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Look up a domain's registration expiry date and registrant (owner). Uses
 * RDAP (the HTTPS/JSON successor to WHOIS) via the rdap.org bootstrap, with a
 * dedicated fallback to ISOC-IL's RDAP service for .il domains — the Israeli
 * registry is often missing from the public bootstrap, which used to leave
 * every .co.il site with no expiry data at all. Best-effort: returns null when
 * no service answered — the caller then leaves the cached value untouched.
 */
class DomainExpiry
{
    /**
     * Expiry + registrant for a domain, or null when no RDAP service answered.
     *
     * @return array{expires_at: ?Carbon, registrant: ?string}|null
     */
    public function lookup(string $domain): ?array
    {
        $registrable = $this->registrableDomain($domain);

        if ($registrable === '') {
            return null;
        }

        foreach ($this->endpoints($registrable) as $url) {
            $data = $this->fetch($url);

            if ($data === null) {
                continue;
            }

            return [
                'expires_at' => $this->expiryFrom($data),
                'registrant' => $this->registrantFrom($data),
            ];
        }

        return null;
    }

    /** Back-compat convenience: just the expiry date. */
    public function expiresAt(string $domain): ?Carbon
    {
        return $this->lookup($domain)['expires_at'] ?? null;
    }

    /**
     * RDAP endpoints to try, in order. .il domains get ISOC-IL's own RDAP as a
     * fallback — the registry's data lives there even when the rdap.org
     * bootstrap can't route the TLD.
     *
     * @return list<string>
     */
    private function endpoints(string $registrable): array
    {
        $endpoints = ['https://rdap.org/domain/'.$registrable];

        if (str_ends_with($registrable, '.il')) {
            $endpoints[] = 'https://rdap.isoc.org.il/domain/'.$registrable;
        }

        return $endpoints;
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->acceptJson()
                ->get($url);

            return $response->ok() ? (array) $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function expiryFrom(array $data): ?Carbon
    {
        foreach ((array) ($data['events'] ?? []) as $event) {
            if (($event['eventAction'] ?? null) === 'expiration' && filled($event['eventDate'] ?? null)) {
                // An absolute timestamp — Carbon::parse doesn't need "now".
                return Carbon::parse($event['eventDate']);
            }
        }

        return null;
    }

    /**
     * The registrant's display name from the RDAP entities: the entity with the
     * "registrant" role, reading its vCard fn (falling back to org).
     *
     * @param  array<string, mixed>  $data
     */
    private function registrantFrom(array $data): ?string
    {
        foreach ((array) ($data['entities'] ?? []) as $entity) {
            if (! in_array('registrant', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            $name = null;
            foreach ((array) data_get($entity, 'vcardArray.1', []) as $property) {
                if (in_array($property[0] ?? '', ['fn', 'org'], true) && filled($property[3] ?? null)) {
                    $name = is_array($property[3]) ? implode(' ', array_filter($property[3])) : (string) $property[3];

                    if (($property[0] ?? '') === 'fn') {
                        break; // fn wins over org
                    }
                }
            }

            if (filled($name)) {
                return mb_substr(trim((string) $name), 0, 190);
            }
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
