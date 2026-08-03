<?php

namespace App\Services\Audit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;

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

    /**
     * How much of a response is read.
     *
     * A report is built from the head and the markup; nothing here needs more.
     * The bound is enforced while READING, not after — a server can answer with
     * a stream that never ends, and a worker that politely downloads all of it
     * dies with the audit stuck on "running".
     */
    private const MAX_BYTES = 512 * 1024;

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
        $guard = app(PublicTarget::class);

        try {
            $guard->assert((string) parse_url($url, PHP_URL_HOST));

            $request = Http::withHeaders([
                // Announced honestly. A site that blocks us should block a name
                // its owner can look up, not a browser we are pretending to be.
                'User-Agent' => 'MultiotoSiteAudit/1.0 (+site health check)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(self::TIMEOUT)->connectTimeout(8)->withOptions([
                // A certificate that does not verify is a FINDING, not a reason
                // to come back with nothing. The certificate check does its own
                // verified handshake; this only stops the fetch dying on it.
                'verify' => false,
                'stream' => true,
            ]);

            $request = $follow
                ? $request->withOptions([
                    'allow_redirects' => [
                        'max' => 6,
                        'track_redirects' => true,
                        // Every hop is checked, not only the address typed in.
                        // A public site is free to answer "go to 169.254.169.254",
                        // and following that would turn this tool into a way to
                        // read the inside of the network it runs in.
                        'on_redirect' => static function ($request, $response, UriInterface $to) use ($guard): void {
                            $guard->assert($to->getHost());
                        },
                    ],
                ])
                : $request->withoutRedirecting();

            $response = $request->get($url);
            $redirects = $response->getHeader('X-Guzzle-Redirect-History');

            return new self(
                url: $url,
                status: $response->status(),
                ms: self::elapsed($started),
                headers: self::normalise($response->headers()),
                body: self::read($response),
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

    /** Read up to the cap and stop, whatever the server intends to keep sending. */
    private static function read(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
            $chunk = $stream->read(self::MAX_BYTES - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        $stream->close();

        return $body;
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
