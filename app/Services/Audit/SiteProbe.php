<?php

namespace App\Services\Audit;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

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

    /** Enough for the http→https→www chains real sites have, and no further. */
    private const MAX_REDIRECTS = 6;

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
        $redirects = [];
        $current = $url;

        try {
            for ($hop = 0; ; $hop++) {
                $response = self::request($guard, $current);
                $headers = self::normalise($response->headers());
                $location = $follow ? self::redirect($response->status(), $headers, $current) : null;

                if ($location === null) {
                    return new self(
                        url: $url,
                        status: $response->status(),
                        ms: self::elapsed($started),
                        headers: $headers,
                        body: self::read($response),
                        error: null,
                        finalUrl: $current,
                        redirects: $redirects,
                    );
                }

                if ($hop >= self::MAX_REDIRECTS) {
                    throw new RuntimeException('האתר מפנה במעגל — יותר מ-'.self::MAX_REDIRECTS.' הפניות.');
                }

                self::release($response->toPsrResponse()->getBody());
                $redirects[] = $location;
                $current = $location;
            }
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

    /**
     * Statuses that mean "not for you" rather than "not working".
     *
     * A firewall refusing an automated request from a datacentre is the single
     * most likely reason a perfectly healthy site answers us with an error, and
     * the visitor it turns away is us — not the customer.
     */
    private const GATES = [401, 403, 406, 409, 418, 429];

    /** Fingerprints of the things that sit in front of sites and say no. */
    private const GUARDS = [
        'Cloudflare' => ['cf-ray', 'cf-mitigated', 'attention required', 'just a moment', 'cloudflare ray id'],
        'Sucuri' => ['x-sucuri-id', 'x-sucuri-block', 'sucuri website firewall'],
        'Imperva' => ['x-iinfo', 'incap_ses', 'request unsuccessful'],
        'Akamai' => ['akamaighost', 'akamai reference'],
        'Wordfence' => ['wordfence'],
        'ModSecurity' => ['mod_security', 'modsecurity'],
        'AWS WAF' => ['x-amzn-waf', 'awselb/'],
    ];

    /**
     * Whether the answer is a gate rather than the site.
     *
     * This distinction is the difference between a report that says "your site
     * is broken" and one that says "your site would not let us look" — and the
     * first, said about a site that is fine, is the finding that discredits the
     * whole document.
     */
    public function blocked(): bool
    {
        if ($this->status === null) {
            return false;
        }

        return in_array($this->status, self::GATES, true)
            || ($this->status >= 400 && $this->guard() !== null);
    }

    /** Which gatekeeper answered, when one announces itself. */
    public function guard(): ?string
    {
        $announced = mb_strtolower(implode(' ', array_merge(
            array_keys($this->headers),
            array_merge(...array_values($this->headers) ?: [[]]),
            [mb_substr($this->body, 0, 4000)],
        )));

        foreach (self::GUARDS as $name => $signs) {
            foreach ($signs as $sign) {
                if (str_contains($announced, $sign)) {
                    return $name;
                }
            }
        }

        return null;
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

    /**
     * One hop: judged, pinned to the judged address, and not followed further.
     *
     * Redirects are followed by this class rather than by the HTTP client for
     * one reason — the client would resolve each new hostname itself, and the
     * whole point is that nothing is dialled except an address this guard has
     * already looked at.
     */
    private static function request(PublicTarget $guard, string $url): Response
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $secure = mb_strtolower((string) ($parts['scheme'] ?? 'https')) === 'https';
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));

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
        ])->withoutRedirecting();

        return self::pin($request, $guard, $host, $port)->get($url);
    }

    /**
     * Bind the connection to an address the guard approved.
     *
     * Approving a name and then letting the client look it up again leaves a
     * gap: the second answer can be a private address, and the request lands
     * inside the network the panel runs in. Pinning keeps the hostname for TLS
     * and for the Host header — the site sees an ordinary visit — while the
     * socket goes only where it was allowed to go.
     */
    private static function pin(PendingRequest $request, PublicTarget $guard, string $host, int $port): PendingRequest
    {
        $addresses = $guard->addresses($host);

        // Without curl there is no way to say "this name, that address", and the
        // client would look the name up again on its own. Refusing is the only
        // honest answer: an audit that did not run is a visible failure, while
        // one that ran unpinned is the hole this guard exists to close, with
        // nothing on screen to say so.
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('לא ניתן לאבטח את החיבור לאתר (חסרה הרחבת curl ב-PHP).');
        }

        $literal = array_map(
            static fn (string $address): string => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '['.$address.']'
                : $address,
            $addresses,
        );

        return $request->withOptions(['curl' => [
            CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $literal)],
        ]]);
    }

    /**
     * Where this response sends us next, as an absolute address, or null.
     *
     * @param  array<string, list<string>>  $headers
     */
    private static function redirect(int $status, array $headers, string $from): ?string
    {
        $location = trim((string) ($headers['location'][0] ?? ''));

        if ($status < 300 || $status >= 400 || $location === '') {
            return null;
        }

        $to = UriResolver::resolve(new Uri($from), new Uri($location));

        // Only the two schemes a website is served over. file:// and gopher://
        // are things curl will happily follow and no site legitimately sends.
        if (! in_array($to->getScheme(), ['http', 'https'], true)) {
            throw new RuntimeException('האתר מפנה לכתובת שאינה http/https.');
        }

        return (string) $to;
    }

    /** Read up to the cap and stop, whatever the server intends to keep sending. */
    private static function read(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();

        // A seekable body is one already held in memory. Reading it from the
        // start rather than from wherever it was left makes a second read of the
        // same response give the same answer as the first.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
            $chunk = $stream->read(self::MAX_BYTES - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        self::release($stream);

        return $body;
    }

    /**
     * Let go of a live connection — and only of a live one.
     *
     * A body still attached to a socket must be closed or the connection is held
     * for as long as the audit runs. A buffered body holds nothing, and closing
     * it only destroys something that could still be read.
     */
    private static function release(StreamInterface $stream): void
    {
        if (! $stream->isSeekable()) {
            $stream->close();
        }
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
