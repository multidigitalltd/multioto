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
     * The bare hostname DNS queries need. The sites table may store a
     * subdirectory install ("example.com/blog") or a host:port — both would
     * make every dns_get_record() call fail and read as a resolver outage.
     */
    public static function host(string $domain): string
    {
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($domain)) ?? '';

        foreach (['/', ':', '?', '#'] as $separator) {
            $host = explode($separator, $host, 2)[0];
        }

        return rtrim(mb_strtolower($host), '.');
    }

    /**
     * Current records for a domain, normalized and sorted. A type whose lookup
     * failed (SERVFAIL/timeout) is returned as null — "unknown", never "empty" —
     * so an outage can't masquerade as every record having been removed.
     * MX values keep their priority ("10 mail.example.com") — promoting a
     * secondary mail server is a real routing change the diff must see.
     *
     * @return array{a: ?list<string>, mx: ?list<string>, ns: ?list<string>}
     */
    public function records(string $domain): array
    {
        $host = self::host($domain);

        return [
            'a' => $this->values($host, DNS_A, 'ip'),
            'mx' => $this->values($host, DNS_MX, 'target', 'pri'),
            'ns' => $this->values($host, DNS_NS, 'target'),
        ];
    }

    /** @return ?list<string> */
    protected function values(string $domain, int $type, string $field, ?string $priorityField = null): ?array
    {
        $records = $this->query($domain, $type);

        if ($records === null) {
            return null;
        }

        return collect($records)
            ->map(function (array $record) use ($field, $priorityField): ?string {
                $value = $record[$field] ?? null;

                if (blank($value)) {
                    return null;
                }

                $value = rtrim(mb_strtolower((string) $value), '.');

                return $priorityField !== null && isset($record[$priorityField])
                    ? ((int) $record[$priorityField]).' '.$value
                    : $value;
            })
            ->filter()
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
