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
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('הכתובת מפנה לכתובת פנימית — הכלי בודק אתרים פומביים בלבד.');
            }
        }

        return $this->decided[$host] = array_values($addresses);
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
