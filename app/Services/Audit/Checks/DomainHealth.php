<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Hostname;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Security\DnsLookup;
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
        private DnsLookup $dns,
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
            $this->dmarcFindings($site),
            $this->mailRoutingFindings($site),
            $this->certificateAuthorityFindings($site),
            $this->nameServerFindings($site),
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
            $records = $this->records($name, DNS_TXT);

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
     * DMARC — מה שאומר לעולם מה לעשות עם מייל מזויף בשם הדומיין.
     *
     * SPF לבדו מסמן; DMARC הוא זה שמורה לחסום, וגם זה שמחזיר דוחות על מי שמנסה.
     * בלעדיו אפשר לזייף מייל בשם העסק אל הלקוחות שלו — וזו ההונאה הנפוצה ביותר
     * שמתחילה בדומיין מוזנח.
     *
     * @return list<Finding>
     */
    private function dmarcFindings(AuditContext $site): array
    {
        $records = $this->records('_dmarc.'.Hostname::registrable($site->host), DNS_TXT);

        if ($records === null) {
            return [];
        }

        $policy = null;

        foreach ($records as $record) {
            $text = mb_strtolower((string) ($record['txt'] ?? ''));

            if (str_contains($text, 'v=dmarc1') && preg_match('/\bp\s*=\s*(none|quarantine|reject)/', $text, $found) === 1) {
                $policy = $found[1];
            }
        }

        if ($policy === null) {
            return [Finding::warning(
                $this->area(),
                'אין הגדרת DMARC לדומיין',
                'DMARC הוא מה שאומר לשרתי הדואר בעולם מה לעשות עם מייל שמתחזה לדומיין שלכם. בלעדיו אפשר לשלוח ללקוחות שלכם מייל שנראה כאילו הגיע מכם, ואיש לא יעצור אותו.',
                'להוסיף רשומת TXT בשם _dmarc עם מדיניות — להתחיל ב-p=none לצורך מעקב, ולהחמיר ל-quarantine או reject.',
            )];
        }

        if ($policy === 'none') {
            return [Finding::notice(
                $this->area(),
                'הגדרת ה-DMARC אינה חוסמת התחזות',
                'המדיניות מוגדרת p=none, כלומר "לדווח ולא לעשות דבר". זו נקודת התחלה נכונה, אך כל עוד היא כך, מייל שמתחזה לדומיין עדיין מגיע ליעדו.',
                'לאחר תקופת מעקב — להחמיר את המדיניות ל-quarantine ואז ל-reject.',
                'p=none',
            )];
        }

        return [Finding::ok($this->area(), 'הדומיין מוגן מהתחזות בדואר', 'DMARC פעיל עם p='.$policy.'.')];
    }

    /**
     * MX — לאן הולך הדואר, ואם בכלל.
     *
     * @return list<Finding>
     */
    private function mailRoutingFindings(AuditContext $site): array
    {
        $records = $this->records(Hostname::registrable($site->host), DNS_MX);

        if ($records === null || $records !== []) {
            return [];
        }

        return [Finding::warning(
            $this->area(),
            'לדומיין אין הגדרת דואר (MX)',
            'אין שרת דואר מוגדר לדומיין, כלומר מייל שנשלח לכתובת בדומיין הזה פשוט לא מגיע לשום מקום. אם משתמשים בכתובת מייל של הדומיין — היא אינה עובדת.',
            'להגדיר רשומות MX אצל ספק הדואר (Google Workspace, Microsoft 365 או ספק האחסון).',
        )];
    }

    /**
     * CAA — מי רשאי בכלל להנפיק תעודת אבטחה בשם הדומיין.
     *
     * בלי הרשומה הזו כל רשות אישורים בעולם יכולה להנפיק תעודה תקפה לדומיין,
     * וזה בדיוק מה שהופך השתלטות על הדומיין להשתלטות שקטה על התעבורה.
     *
     * @return list<Finding>
     */
    private function certificateAuthorityFindings(AuditContext $site): array
    {
        if (! defined('DNS_CAA')) {
            return [];
        }

        $records = $this->records(Hostname::registrable($site->host), DNS_CAA);

        if ($records === null || $records !== []) {
            return [];
        }

        return [Finding::notice(
            $this->area(),
            'אין הגבלה על מי רשאי להנפיק תעודת אבטחה לדומיין',
            'בלי רשומת CAA כל רשות אישורים בעולם יכולה להנפיק תעודה תקפה לדומיין הזה. זו שכבת הגנה חסרה, לא תקלה פעילה.',
            'להוסיף רשומת CAA ל-DNS שמגבילה את ההנפקה לרשות שבה משתמשים בפועל.',
        )];
    }

    /**
     * שרתי שמות — כמה מהם, ומה קורה כשאחד מהם נופל.
     *
     * @return list<Finding>
     */
    private function nameServerFindings(AuditContext $site): array
    {
        $records = $this->records(Hostname::registrable($site->host), DNS_NS);

        if ($records === null || count($records) >= 2) {
            return [];
        }

        if ($records === []) {
            return [];
        }

        return [Finding::notice(
            $this->area(),
            'לדומיין מוגדר שרת שמות אחד בלבד',
            'כשיש שרת שמות יחיד, תקלה בו מנתקת את האתר ואת הדואר גם אם השרת עצמו עובד מצוין — הדפדפן פשוט אינו מצליח לתרגם את השם לכתובת.',
            'להגדיר לפחות שני שרתי שמות אצל ספק ה-DNS. אצל רוב הספקים זו ברירת המחדל.',
        )];
    }

    /**
     * Records of one type, or null when the lookup itself did not answer.
     *
     * The distinction is the whole point, and it is why this goes through
     * DnsLookup rather than dns_get_record: an empty answer means "nothing
     * published", a failed resolver means nothing at all, and a cast flattens
     * the two into the same confident warning.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function records(string $domain, int $type): ?array
    {
        return rescue(fn (): ?array => $this->dns->lookup($domain, $type), null, report: false);
    }
}
