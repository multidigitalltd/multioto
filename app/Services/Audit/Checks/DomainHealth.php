<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Hostname;
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

    /**
     * Blocklists — and, just as importantly, which of them actually answered.
     *
     * Nothing found and nothing asked are different answers, and that holds per
     * source as well as overall: one provider silent while another came back
     * clean still leaves a hole in the report, and a hole that looks exactly
     * like a clean bill of health is the one thing this must never produce.
     *
     * @return list<Finding>
     */
    private function reputationFindings(AuditContext $site): array
    {
        $result = $this->reputation->check($site->host);
        $listings = (array) ($result['listings'] ?? []);
        $sources = (array) ($result['sources'] ?? []);
        $silent = array_keys(array_filter($sources, static fn ($answered): bool => ! $answered));
        $findings = [];

        if ($listings !== []) {
            $findings[] = Finding::critical(
                $this->area(),
                'הדומיין מופיע ברשימות חסימה',
                'הדומיין מסומן כמסוכן באחד המאגרים. התוצאה היא אזהרה אדומה בדפדפן ומיילים שנוחתים בספאם — או לא מגיעים כלל.',
                'לברר את הסיבה, לנקות את האתר אם יש בו קוד זדוני, ולהגיש בקשת הסרה למאגר.',
                implode(', ', array_slice(array_column($listings, 'source'), 0, 3)) ?: null,
            );
        }

        if ($sources !== [] && $silent === []) {
            return $findings;
        }

        $all = $sources === [] || count($silent) === count($sources);

        $findings[] = Finding::notice(
            $this->area(),
            $all ? 'בדיקת רשימות החסימה לא הושלמה' : 'בדיקת רשימות החסימה הושלמה חלקית',
            $all
                ? 'אף אחד ממאגרי המוניטין לא השיב בזמן הבדיקה. אין בכך כדי לומר שהדומיין נקי — פשוט לא נבדק.'
                : 'חלק ממאגרי המוניטין לא השיבו בזמן הבדיקה. מה שנבדק נמצא כפי שמופיע כאן, אך זו אינה תמונה מלאה.',
            'לנסות שוב מאוחר יותר.',
            $silent !== [] ? 'לא השיבו: '.implode(', ', $silent) : null,
        );

        return $findings;
    }

    /**
     * Whether anything stops somebody else sending mail in this domain's name.
     *
     * @return list<Finding>
     */
    private function mailFindings(AuditContext $site): array
    {
        // Asked of the address itself AND of the domain it belongs to. A policy
        // is published where the mail is sent from — usually example.com, while
        // the site sits at www.example.com or shop.example.com — and asking only
        // the name that was typed produces a confident warning about a record
        // that exists and is easy to go and look at.
        $names = array_unique([mb_strtolower($site->host), Hostname::registrable($site->host)]);
        $answered = false;

        foreach ($names as $name) {
            $records = $this->txt($name);

            if ($records === null) {
                continue;
            }

            $answered = true;

            if (str_contains(mb_strtolower(implode(' ', array_column($records, 'txt'))), 'v=spf1')) {
                return [];
            }
        }

        // No answer at all is not an answer of "no record". A resolver that
        // timed out and a domain with nothing published look identical from
        // here, and only one of them is somebody's fault.
        if (! $answered) {
            return [Finding::notice(
                $this->area(),
                'לא ניתן היה לבדוק את הגדרת ה-SPF',
                'שאילתת ה-DNS לרשומות הטקסט של הדומיין לא נענתה בזמן הבדיקה. אין בכך כדי לומר שאין SPF — פשוט לא נבדק.',
                'לנסות שוב מאוחר יותר, או לבדוק ידנית מול ספק ה-DNS.',
            )];
        }

        return [Finding::warning(
            $this->area(),
            'אין הגדרת SPF לדומיין',
            'בלי SPF כל אחד יכול לשלוח מייל שנראה כאילו הגיע מהדומיין הזה, והמיילים שאתם שולחים נוטים יותר להיחסם כספאם.',
            'להוסיף רשומת SPF ל-DNS, ואחריה DKIM ו-DMARC.',
        )];
    }

    /**
     * TXT records for a name — null when the lookup itself did not answer.
     *
     * Its own method for the distinction it keeps: `dns_get_record` returns an
     * empty array for "nothing published" and false for "could not ask", and a
     * cast flattens the two into the same thing.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function txt(string $domain): ?array
    {
        $records = rescue(fn () => @dns_get_record($domain, DNS_TXT), false, report: false);

        return $records === false ? null : (array) $records;
    }
}
