<?php

namespace App\Services\Audit;

use App\Models\SiteAudit;
use App\Services\Audit\Checks\Accessibility;
use App\Services\Audit\Checks\Availability;
use App\Services\Audit\Checks\Check;
use App\Services\Audit\Checks\Discoverability;
use App\Services\Audit\Checks\DomainHealth;
use App\Services\Audit\Checks\SecurityHeaders;
use App\Services\Audit\Checks\Speed;
use App\Services\Audit\Checks\Transport;
use App\Services\Audit\Checks\WordPressExposure;
use App\Services\Security\DnsLookup;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Look at a website from the outside and say what is wrong with it.
 *
 * Outside-in ON PURPOSE, with no credentials and nothing installed: the address
 * typed in is usually somebody who is not a customer yet, and everything the
 * report says has to be something they could verify themselves. That constraint
 * is also the honest one — it is exactly what their own visitors experience.
 *
 * Every check is independent and none of them can end the audit. A check that
 * throws is recorded as a question that could not be answered, because a report
 * missing a section reads identically to one where that section was fine.
 */
class SiteAuditor
{
    public function __construct(
        private Availability $availability,
        private Transport $transport,
        private SecurityHeaders $headers,
        private WordPressExposure $wordpress,
        private Speed $speed,
        private Discoverability $discoverability,
        private Accessibility $accessibility,
        private DomainHealth $domain,
    ) {}

    /**
     * Run every check against one address.
     *
     * @return array{findings: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(string $url): array
    {
        $url = self::normaliseUrl($url);
        $host = DnsLookup::host($url);

        $this->assertPublicTarget($host);

        $site = new AuditContext($url, $host, SiteProbe::fetch($url));

        $findings = [];

        foreach ($this->checks() as $check) {
            foreach ($this->safely($check, $site) as $finding) {
                $findings[] = $finding->toArray();
            }
        }

        return ['findings' => $findings, 'summary' => self::summarise($findings, $site)];
    }

    /** @return list<Check> */
    private function checks(): array
    {
        return [
            $this->availability,
            $this->transport,
            $this->headers,
            $this->wordpress,
            $this->speed,
            $this->discoverability,
            $this->accessibility,
            $this->domain,
        ];
    }

    /**
     * One check's findings, or a finding saying it could not be run.
     *
     * @return list<Finding>
     */
    private function safely(Check $check, AuditContext $site): array
    {
        try {
            return $check->run($site);
        } catch (\Throwable $e) {
            Log::warning('site audit check failed', [
                'check' => $check::class,
                'host' => $site->host,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [Finding::notice(
                $check->area(),
                'בדיקה זו לא הושלמה',
                'לא ניתן היה להשלים את הבדיקה בתחום הזה. אין בכך כדי לומר שהכול תקין — פשוט לא נבדק.',
                'לנסות שוב מאוחר יותר, או לבדוק ידנית.',
            )];
        }
    }

    /**
     * The address as it will actually be fetched.
     *
     * A bare "example.com" is what a person types, and https is what they mean —
     * an audit that reported the whole site as unreachable because the scheme
     * was missing would be worse than useless.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.ltrim($url, '/');
    }

    /**
     * Refuse anything that is not a public website.
     *
     * Without this the panel becomes a way to knock on doors inside the network
     * it is hosted in — a private address or a name that resolves to one — and
     * to read back whatever answers. The tool exists to look at the public web,
     * so that is all it is allowed to look at.
     */
    public function assertPublicTarget(string $host): void
    {
        if ($host === '' || ! str_contains($host, '.') || preg_match('/^[a-z0-9.\-]+$/i', $host) !== 1) {
            throw new RuntimeException('הכתובת אינה תקינה.');
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
    }

    /**
     * Every address the name answers with, IPv6 included.
     *
     * Its own method so a test can decide what a name resolves to: a guard that
     * can only be exercised against the live internet is a guard nobody checks.
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

    /**
     * The one-line verdict, and the counts the screen and the report both show.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private static function summarise(array $findings, AuditContext $site): array
    {
        $counts = array_fill_keys(SiteAudit::SEVERITIES, 0);

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? '');
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return [
            'counts' => $counts,
            'checked_at' => now()->toIso8601String(),
            'final_url' => $site->home->finalUrl,
            'response_ms' => $site->home->ms,
            'reachable' => $site->home->reachable(),
        ];
    }
}
