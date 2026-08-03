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
        private PublicTarget $target,
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

        $this->target->assert($host);

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
