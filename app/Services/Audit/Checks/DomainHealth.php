<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Security\DomainReputationClient;

/**
 * The things that are true about the address rather than about the pages.
 *
 * The expiry date belongs here for a reason that has nothing to do with
 * technology: a domain that lapses takes the site, the email and the years of
 * search ranking with it, and it lapses because a renewal notice went to an
 * address nobody reads any more.
 */
class DomainHealth implements Check
{
    public function __construct(
        private DomainExpiry $expiry,
        private DomainReputationClient $reputation,
    ) {}

    public function area(): string
    {
        return 'דומיין ומוניטין';
    }

    public function run(AuditContext $site): array
    {
        return array_filter(array_merge(
            [$this->expiryFinding($site)],
            $this->reputationFindings($site),
            $this->mailFindings($site),
        ));
    }

    private function expiryFinding(AuditContext $site): ?Finding
    {
        $expires = $this->expiry->expiresAt($site->host);

        if ($expires === null) {
            return null;
        }

        $days = (int) ceil(now()->diffInDays($expires, false));

        if ($days < 0) {
            return Finding::critical(
                $this->area(),
                'רישום הדומיין פג',
                'הדומיין אינו רשום עוד. בשלב הזה גם האתר וגם המייל מפסיקים לעבוד, והדומיין עלול להילקח.',
                'לחדש את הרישום מיד מול הרשם.',
                $expires->format('d/m/Y'),
            );
        }

        if ($days <= 45) {
            return Finding::warning(
                $this->area(),
                'רישום הדומיין עומד לפוג',
                "נותרו {$days} ימים. דומיין שפג לוקח איתו את האתר, את המייל ואת הדירוג בגוגל — וההודעה על החידוש נשלחת בדרך כלל לכתובת ישנה.",
                'לחדש עכשיו, ולהפעיל חידוש אוטומטי אצל הרשם.',
                $expires->format('d/m/Y'),
            );
        }

        return Finding::ok($this->area(), 'רישום הדומיין בתוקף', 'עד '.$expires->format('d/m/Y').'.');
    }

    /** @return list<Finding> */
    private function reputationFindings(AuditContext $site): array
    {
        $result = $this->reputation->check($site->host);
        $listings = (array) ($result['listings'] ?? []);

        if ($listings === []) {
            // Nothing found and nothing asked are different answers. A source
            // that could not be reached leaves the report looking exactly like
            // a clean bill of health, which is the one thing it must not do.
            return array_filter((array) ($result['sources'] ?? [])) === []
                ? [Finding::notice(
                    $this->area(),
                    'בדיקת רשימות החסימה לא הושלמה',
                    'אף אחד ממאגרי המוניטין לא השיב בזמן הבדיקה. אין בכך כדי לומר שהדומיין נקי — פשוט לא נבדק.',
                    'לנסות שוב מאוחר יותר.',
                )]
                : [];
        }

        return [Finding::critical(
            $this->area(),
            'הדומיין מופיע ברשימות חסימה',
            'הדומיין מסומן כמסוכן באחד המאגרים. התוצאה היא אזהרה אדומה בדפדפן ומיילים שנוחתים בספאם — או לא מגיעים כלל.',
            'לברר את הסיבה, לנקות את האתר אם יש בו קוד זדוני, ולהגיש בקשת הסרה למאגר.',
            implode(', ', array_slice(array_column($listings, 'source'), 0, 3)) ?: null,
        )];
    }

    /**
     * Whether anything stops somebody else sending mail in this domain's name.
     *
     * @return list<Finding>
     */
    private function mailFindings(AuditContext $site): array
    {
        // On the mail domain, not on the address that was typed. SPF lives on
        // example.com while the site is at www.example.com, and asking the
        // wrong name produces a confident warning about a policy that exists.
        $domain = preg_replace('/^www\./i', '', $site->host) ?? $site->host;

        $records = rescue(fn (): array => (array) dns_get_record($domain, DNS_TXT), [], report: false);
        $texts = mb_strtolower(implode(' ', array_column($records, 'txt')));

        if (str_contains($texts, 'v=spf1')) {
            return [];
        }

        return [Finding::warning(
            $this->area(),
            'אין הגדרת SPF לדומיין',
            'בלי SPF כל אחד יכול לשלוח מייל שנראה כאילו הגיע מהדומיין הזה, והמיילים שאתם שולחים נוטים יותר להיחסם כספאם.',
            'להוסיף רשומת SPF ל-DNS, ואחריה DKIM ו-DMARC.',
        )];
    }
}
