<?php

namespace App\Services\Audit;

use RuntimeException;

/**
 * Decides whether an address is one this tool is allowed to fetch.
 *
 * Its own class because the question is asked in three places that must all
 * answer it identically: the screen, before an audit is queued; the auditor,
 * before it starts; and every redirect a site sends the fetcher to. The last
 * one is the reason this matters — a perfectly public site can answer with
 * "go to 169.254.169.254", and a guard that ran once on the address typed in
 * would wave that through.
 */
class PublicTarget
{
    /**
     * Ranges that are not the public internet, whatever PHP's flags think.
     *
     * The private ones are obvious; the rest are the reason this list exists.
     * Carrier-grade NAT (100.64/10) and the benchmarking range (198.18/15) are
     * routable inside plenty of networks and reach things nobody meant to
     * publish, and `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` waves both through.
     * IPv6 is listed in full for the same reason — a name that answers only on
     * AAAA would otherwise be judged by a shorter rule than its IPv4 twin.
     */
    private const NOT_PUBLIC = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24',
        '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // 2001::/23 is the whole IETF protocol-assignment block — Teredo, the
        // benchmarking prefix, ORCHID, AMT, AS112 and the rest. Listing the
        // block rather than its members is not brevity: the members change, and
        // a list of them is a list that goes stale without anyone noticing.
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
        '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
        '5f00::/16', '2620:4f:8000::/48', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var array<string, list<string>> */
    private array $decided = [];

    public function assert(string $host): void
    {
        $this->addresses($host);
    }

    /**
     * The addresses a name is allowed to be reached at.
     *
     * The list is returned rather than merely approved because approving a NAME
     * settles nothing: between the check and the connection the name is looked
     * up a second time, and a server that answers with a public address to the
     * first query and a private one to the second walks straight past a guard
     * that only said yes. The fetcher connects to what came back from here, so
     * the address that was judged is the address that is dialled.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $host = mb_strtolower(trim($host));

        if ($host === '' || ! str_contains($host, '.') || preg_match('/^[a-z0-9.\-]+$/i', $host) !== 1) {
            throw new RuntimeException('הכתובת אינה תקינה.');
        }

        if (isset($this->decided[$host])) {
            return $this->decided[$host];
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException('לא ניתן לאתר את הדומיין. ייתכן שהוא שגוי או שאינו רשום.');
        }

        foreach ($addresses as $address) {
            if (! self::routable($address)) {
                throw new RuntimeException('הכתובת מפנה לכתובת פנימית — הכלי בודק אתרים פומביים בלבד.');
            }
        }

        return $this->decided[$host] = array_values($addresses);
    }

    /** Whether an address is one the public internet can reach. */
    private static function routable(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach (self::NOT_PUBLIC as $range) {
            [$network, $bits] = explode('/', $range);
            $start = @inet_pton($network);

            // Comparing an IPv4 address against an IPv6 range, or the reverse,
            // is not a match — it is a different question, and skipping it is
            // what keeps each family judged by its own list.
            if ($start === false || strlen($start) !== strlen($packed)) {
                continue;
            }

            if (self::sharesPrefix($packed, $start, (int) $bits)) {
                return false;
            }
        }

        return true;
    }

    /** Whether two packed addresses agree on their first $bits bits. */
    private static function sharesPrefix(string $address, string $network, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        $spare = $bits % 8;

        if ($whole > 0 && substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        if ($spare === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $spare)) & 0xFF);

        return ($address[$whole] & $mask) === ($network[$whole] & $mask);
    }

    /** Whether an address may be fetched, without the exception. */
    public function allows(string $host): bool
    {
        try {
            $this->assert($host);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every address the name answers with, IPv6 included.
     *
     * Its own method so a test can decide what a name resolves to: a guard that
     * can only be exercised against the live internet is a guard nobody checks
     * — including the case that matters, a public NAME pointing inwards.
     *
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        return array_values(array_filter(array_merge(
            gethostbynamel($host) ?: [],
            array_column(rescue(fn (): array => (array) dns_get_record($host, DNS_AAAA), [], report: false), 'ipv6'),
        )));
    }
}
