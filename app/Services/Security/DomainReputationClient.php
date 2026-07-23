<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;

/**
 * Checks a domain against public spam/malware blocklists:
 *  - URLhaus (abuse.ch, keyless) — known malware URLs on the host.
 *  - Spamhaus DBL (keyless, over DNS) — the domain-block spam list, queried
 *    independently so a spam-only domain is caught even when URLhaus is clean.
 *  - Google Safe Browsing (optional, API key) — Google's malware/phishing lists.
 *
 * Read-only and best-effort. Each listing carries the `provider` that produced
 * it, and `check()` reports which providers actually ran — so a source that
 * errors is reported as "not run", never as "clean", and the caller can keep the
 * last known findings for a provider that failed this run.
 */
class DomainReputationClient
{
    /**
     * @return array{sources: array<string, bool>, listings: list<array{provider: string, source: string, type: string, detail: string, link: ?string}>}
     */
    public function check(string $domain): array
    {
        $host = $this->normalizeHost($domain);

        if ($host === '') {
            return ['sources' => [], 'listings' => []];
        }

        $sources = [];
        $listings = [];

        foreach ($this->providers() as $provider => $runner) {
            [$ran, $found] = $runner($host);
            $sources[$provider] = $ran;
            $listings = array_merge($listings, $found);
        }

        return ['sources' => $sources, 'listings' => $listings];
    }

    /**
     * @return array<string, callable(string): array{0: bool, 1: list<array<string, mixed>>}>
     */
    private function providers(): array
    {
        $providers = [
            'urlhaus' => fn (string $host): array => $this->urlhaus($host),
            'spamhaus' => fn (string $host): array => $this->spamhaus($host),
        ];

        if (filled(config('security.reputation.safe_browsing_key'))) {
            $providers['safe_browsing'] = fn (string $host): array => $this->safeBrowsing($host);
        }

        return $providers;
    }

    /**
     * URLhaus host lookup — known malware URLs on the host.
     *
     * @return array{0: bool, 1: list<array<string, mixed>>}
     */
    private function urlhaus(string $host): array
    {
        $url = (string) config('security.reputation.urlhaus_host_url');

        if ($url === '') {
            return [false, []];
        }

        try {
            $response = Http::asForm()->timeout(30)->acceptJson()->post($url, ['host' => $host]);
        } catch (\Throwable) {
            return [false, []];
        }

        if (! $response->successful()) {
            return [false, []];
        }

        $body = (array) $response->json();
        $status = (string) ($body['query_status'] ?? '');

        // Only a definite answer (listed or clean) counts as "ran".
        if (! in_array($status, ['ok', 'no_results'], true)) {
            return [false, []];
        }

        if ($status === 'no_results') {
            return [true, []];
        }

        $urlCount = (int) ($body['url_count'] ?? 0);

        if ($urlCount === 0) {
            return [true, []];
        }

        $online = collect((array) ($body['urls'] ?? []))
            ->filter(fn ($u): bool => is_array($u) && ($u['url_status'] ?? '') === 'online')
            ->count();

        return [true, [[
            'provider' => 'urlhaus',
            'source' => 'URLhaus',
            'type' => 'malware',
            'detail' => $online > 0 ? "{$online} כתובות זדוניות פעילות" : "{$urlCount} כתובות זדוניות ידועות",
            'link' => isset($body['urlhaus_reference']) ? (string) $body['urlhaus_reference'] : null,
        ]]];
    }

    /**
     * Spamhaus DBL over DNS — the authoritative domain spam/abuse list, checked
     * independently of URLhaus. A reliability probe against Spamhaus's documented
     * test point guards against a resolver that can't query the DBL (public
     * resolvers are blocked): if the probe fails we report "not run", never clean.
     *
     * @return array{0: bool, 1: list<array<string, mixed>>}
     */
    private function spamhaus(string $host): array
    {
        // The resolver must see the known-listed test point, or it cannot query DBL.
        if (! $this->dblListed('test')) {
            return [false, []];
        }

        if (! $this->dblListed($host)) {
            return [true, []];
        }

        return [true, [[
            'provider' => 'spamhaus',
            'source' => 'Spamhaus DBL',
            'type' => 'spam',
            'detail' => 'הדומיין רשום ב-Spamhaus DBL',
            'link' => 'https://check.spamhaus.org/results?query='.$host,
        ]]];
    }

    /** Whether "<name>.dbl.spamhaus.org" resolves to a DBL listing code (127.0.1.x). */
    private function dblListed(string $name): bool
    {
        foreach ($this->dblRecords($name.'.dbl.spamhaus.org') as $record) {
            if (str_starts_with((string) ($record['ip'] ?? ''), '127.0.1.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * DNS A records for a DBL query — isolated so it can be stubbed in tests
     * (real DNS is neither available nor desirable there).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function dblRecords(string $query): array
    {
        return @dns_get_record($query, DNS_A) ?: [];
    }

    /**
     * Google Safe Browsing lookup. Submits the scheme/host variants of the home
     * page (Safe Browsing evaluates the exact URL entries it is given); this is a
     * domain-level check, not a full crawl of every path on the site.
     *
     * @return array{0: bool, 1: list<array<string, mixed>>}
     */
    private function safeBrowsing(string $host): array
    {
        $key = (string) config('security.reputation.safe_browsing_key');

        $entries = collect([
            "http://{$host}/",
            "https://{$host}/",
            "http://www.{$host}/",
            "https://www.{$host}/",
        ])->unique()->map(fn (string $u): array => ['url' => $u])->values()->all();

        try {
            $response = Http::timeout(30)->acceptJson()->post(
                'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($key),
                [
                    'client' => ['clientId' => 'multioto', 'clientVersion' => '1.0'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => $entries,
                    ],
                ],
            );
        } catch (\Throwable) {
            return [false, []];
        }

        if (! $response->successful()) {
            return [false, []];
        }

        $listings = [];

        foreach ((array) ($response->json('matches') ?? []) as $match) {
            $listings[] = [
                'provider' => 'safe_browsing',
                'source' => 'Google Safe Browsing',
                'type' => 'malware',
                'detail' => (string) ($match['threatType'] ?? 'THREAT'),
                'link' => 'https://transparencyreport.google.com/safe-browsing/search?url='.urlencode($host),
            ];
        }

        return [true, $listings];
    }

    /** Reduce a URL/domain to a bare host (no scheme, path, port, www or trailing dot). */
    private function normalizeHost(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain); // strip scheme
        $domain = explode('/', $domain)[0];                            // strip path
        $domain = explode(':', $domain)[0];                            // strip port
        $domain = trim($domain, '.');

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
