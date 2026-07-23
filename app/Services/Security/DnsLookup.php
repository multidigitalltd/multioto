<?php

namespace App\Services\Security;

/**
 * Read the DNS records that matter for hijack/misconfiguration detection —
 * A (where the site points), MX (where the mail goes) and NS (who controls the
 * zone). Values are normalized (lowercase, sorted) so two snapshots compare
 * reliably. Thin infrastructure client — the diffing/alerting logic lives in
 * CheckSiteDnsJob.
 */
class DnsLookup
{
    /**
     * Current records for a domain, normalized and sorted. A type whose lookup
     * failed (SERVFAIL/timeout) is returned as null — "unknown", never "empty" —
     * so an outage can't masquerade as every record having been removed.
     *
     * @return array{a: ?list<string>, mx: ?list<string>, ns: ?list<string>}
     */
    public function records(string $domain): array
    {
        return [
            'a' => $this->values($domain, DNS_A, 'ip'),
            'mx' => $this->values($domain, DNS_MX, 'target'),
            'ns' => $this->values($domain, DNS_NS, 'target'),
        ];
    }

    /** @return ?list<string> */
    protected function values(string $domain, int $type, string $field): ?array
    {
        $records = $this->query($domain, $type);

        if ($records === null) {
            return null;
        }

        return collect($records)
            ->pluck($field)
            ->filter()
            ->map(fn ($v): string => rtrim(mb_strtolower((string) $v), '.'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Raw dns_get_record call — a protected seam so tests can stub DNS answers.
     * Returns null on resolver failure (as opposed to an authoritative empty
     * answer, which PHP reports as []).
     *
     * @return ?array<int, array<string, mixed>>
     */
    protected function query(string $domain, int $type): ?array
    {
        $result = @dns_get_record($domain, $type);

        return $result === false ? null : $result;
    }
}
