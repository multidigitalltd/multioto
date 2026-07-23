<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Checks a domain against public spam/malware blocklists. URLhaus (abuse.ch) is
 * keyless and also reports the host's Spamhaus DBL / SURBL status; Google Safe
 * Browsing is consulted in addition when an API key is configured.
 *
 * Read-only and best-effort: a source that errors is simply reported as "not
 * run" — never as "clean" — so an outage can't hide a real listing.
 */
class DomainReputationClient
{
    /**
     * @return array{sources: array<string, bool>, listings: list<array{source: string, type: string, detail: string, link: ?string}>}
     */
    public function check(string $domain): array
    {
        $host = $this->normalizeHost($domain);

        $sources = [];
        $listings = [];

        if ($host === '') {
            return ['sources' => $sources, 'listings' => $listings];
        }

        [$ran, $found] = $this->urlhaus($host);
        $sources['urlhaus'] = $ran;
        $listings = array_merge($listings, $found);

        if (filled(config('security.reputation.safe_browsing_key'))) {
            [$ran, $found] = $this->safeBrowsing($host);
            $sources['safe_browsing'] = $ran;
            $listings = array_merge($listings, $found);
        }

        return ['sources' => $sources, 'listings' => $listings];
    }

    /**
     * URLhaus host lookup: known malware URLs on the host + its Spamhaus DBL /
     * SURBL status.
     *
     * @return array{0: bool, 1: list<array{source: string, type: string, detail: string, link: ?string}>}
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

        // A definite answer (listed or clean) means the source ran; anything else
        // (invalid host, rate limited) is "not run".
        if (! in_array($status, ['ok', 'no_results'], true)) {
            return [false, []];
        }

        if ($status === 'no_results') {
            return [true, []];
        }

        $listings = [];
        $reference = isset($body['urlhaus_reference']) ? (string) $body['urlhaus_reference'] : null;

        $online = collect((array) ($body['urls'] ?? []))
            ->filter(fn ($u): bool => is_array($u) && ($u['url_status'] ?? '') === 'online')
            ->count();
        $urlCount = (int) ($body['url_count'] ?? 0);

        if ($urlCount > 0) {
            $listings[] = [
                'source' => 'URLhaus',
                'type' => 'malware',
                'detail' => $online > 0 ? "{$online} כתובות זדוניות פעילות" : "{$urlCount} כתובות זדוניות ידועות",
                'link' => $reference,
            ];
        }

        // URLhaus surfaces the Spamhaus DBL / SURBL blocklist status for the host.
        foreach ((array) ($body['blacklists'] ?? []) as $name => $state) {
            if (is_string($state) && $state !== '' && ! in_array(strtolower($state), ['not listed', 'no_results'], true)) {
                $listings[] = [
                    'source' => $name === 'spamhaus_dbl' ? 'Spamhaus DBL' : Str::of((string) $name)->upper()->value(),
                    'type' => 'spam',
                    'detail' => (string) $state,
                    'link' => $reference,
                ];
            }
        }

        return [true, $listings];
    }

    /**
     * Google Safe Browsing lookup for malware / social-engineering / unwanted
     * software on the domain.
     *
     * @return array{0: bool, 1: list<array{source: string, type: string, detail: string, link: ?string}>}
     */
    private function safeBrowsing(string $host): array
    {
        $key = (string) config('security.reputation.safe_browsing_key');

        try {
            $response = Http::timeout(30)->acceptJson()->post(
                'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($key),
                [
                    'client' => ['clientId' => 'multioto', 'clientVersion' => '1.0'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [['url' => 'http://'.$host.'/']],
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
                'source' => 'Google Safe Browsing',
                'type' => 'malware',
                'detail' => (string) ($match['threatType'] ?? 'THREAT'),
                'link' => 'https://transparencyreport.google.com/safe-browsing/search?url='.urlencode($host),
            ];
        }

        return [true, $listings];
    }

    /** Reduce a URL/domain to a bare host (no scheme, path, port or trailing dot). */
    private function normalizeHost(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain); // strip scheme
        $domain = explode('/', $domain)[0];                            // strip path
        $domain = explode(':', $domain)[0];                            // strip port

        return trim($domain, '.');
    }
}
