<?php

namespace App\Services\Audit;

/**
 * Everything the checks are allowed to know about the site being inspected.
 *
 * Extra paths are fetched through here rather than by each check, so a path two
 * checks both care about is requested once. That is not only politeness to
 * somebody else's server: an audit that hammers a prospect's site is a poor
 * introduction, and one that fires forty requests can trip a firewall and end
 * up reporting an outage it caused itself.
 */
class AuditContext
{
    /** @var array<string, SiteProbe> */
    private array $fetched = [];

    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly SiteProbe $home,
    ) {}

    /** The homepage markup, lowercased once for the many checks that scan it. */
    public function markup(): string
    {
        return $this->home->body;
    }

    /** A path on the same host, fetched at most once per audit. */
    public function path(string $path): SiteProbe
    {
        return $this->fetched[$path] ??= SiteProbe::fetch($this->base().'/'.ltrim($path, '/'));
    }

    /** The origin the site actually answers on, redirects included. */
    public function base(): string
    {
        $parts = parse_url($this->home->finalUrl ?: $this->url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? $this->host)
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function servesHttps(): bool
    {
        return str_starts_with(mb_strtolower($this->base()), 'https://');
    }

    /**
     * The first capture of a pattern against the homepage markup, or null.
     */
    public function match(string $pattern): ?string
    {
        return preg_match($pattern, $this->markup(), $found) === 1 ? trim($found[1] ?? $found[0]) : null;
    }

    /** How many times a pattern appears in the homepage markup. */
    public function occurrences(string $pattern): int
    {
        return (int) preg_match_all($pattern, $this->markup());
    }
}
