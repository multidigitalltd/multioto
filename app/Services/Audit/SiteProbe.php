<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\Http;

/**
 * One fetch of one address, and nothing else.
 *
 * Every check reads from a probe rather than reaching out on its own, so the
 * report describes ONE visit to the site: a page fetched twice can answer two
 * checks differently — a cache warmed by the first request, a redirect that
 * only fires for the first visitor — and a report that contradicts itself is
 * worse than a shorter one.
 */
class SiteProbe
{
    /** Long enough for a slow shared host, short enough to fail an audit fast. */
    private const TIMEOUT = 15;

    public function __construct(
        public readonly string $url,
        public readonly ?int $status,
        public readonly int $ms,
        /** @var array<string, list<string>> */
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error,
        public readonly string $finalUrl,
        /** @var list<string> */
        public readonly array $redirects,
    ) {}

    /**
     * Fetch an address, following redirects, and keep what the checks need.
     *
     * A failure is a RESULT here, not an exception: "the site did not answer"
     * is the single most important thing this tool can report, and a throw
     * would turn it into an audit that produced nothing at all.
     */
    public static function fetch(string $url, bool $follow = true): self
    {
        $started = hrtime(true);
        $redirects = [];

        try {
            $request = Http::withHeaders([
                // Announced honestly. A site that blocks us should block a name
                // its owner can look up, not a browser we are pretending to be.
                'User-Agent' => 'MultiotoSiteAudit/1.0 (+site health check)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(self::TIMEOUT)->connectTimeout(8);

            $request = $follow
                ? $request->withOptions([
                    'allow_redirects' => ['max' => 6, 'track_redirects' => true],
                    // A certificate that does not verify is a FINDING, not a
                    // reason to come back with nothing. The check reads the
                    // certificate itself; this only stops the fetch dying on it.
                    'verify' => false,
                ])
                : $request->withoutRedirecting()->withOptions(['verify' => false]);

            $response = $request->get($url);

            $redirects = $response->getHeader('X-Guzzle-Redirect-History');

            return new self(
                url: $url,
                status: $response->status(),
                ms: self::elapsed($started),
                headers: self::normalise($response->headers()),
                // Bounded: a report is built from the head and the markup, and
                // an endless response must not become an endless audit.
                body: mb_substr((string) $response->body(), 0, 512 * 1024),
                error: null,
                finalUrl: (string) (end($redirects) ?: $url),
                redirects: array_values($redirects),
            );
        } catch (\Throwable $e) {
            return new self(
                url: $url,
                status: null,
                ms: self::elapsed($started),
                headers: [],
                body: '',
                error: mb_substr($e->getMessage(), 0, 300),
                finalUrl: $url,
                redirects: [],
            );
        }
    }

    public function reachable(): bool
    {
        return $this->error === null && $this->status !== null && $this->status < 400;
    }

    /** A header's first value, case-insensitively, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)][0] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /** @param array<string, list<string>> $headers */
    private static function normalise(array $headers): array
    {
        $lowered = [];

        foreach ($headers as $name => $values) {
            $lowered[mb_strtolower((string) $name)] = array_values((array) $values);
        }

        return $lowered;
    }

    private static function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
