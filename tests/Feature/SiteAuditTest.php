<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\SiteAudits;
use App\Jobs\RunSiteAuditJob;
use App\Models\SiteAudit;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditReport;
use App\Services\Audit\CertificateInspector;
use App\Services\Audit\Checks\Accessibility;
use App\Services\Audit\Checks\Availability;
use App\Services\Audit\Checks\Discoverability;
use App\Services\Audit\Checks\DomainHealth;
use App\Services\Audit\Checks\LegalDocuments;
use App\Services\Audit\Checks\SecurityHeaders;
use App\Services\Audit\Checks\Transport;
use App\Services\Audit\PublicTarget;
use App\Services\Audit\SiteAuditor;
use App\Services\Audit\SiteProbe;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Security\DnsLookup;
use App\Services\Security\DomainReputationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * בדיקת אתר — הכלי שהופך כתובת לרשימת ממצאים ולמסמך שאפשר לשלוח.
 *
 * מה שנשמר הוא מה שנמצא באותו רגע, והמסמך מרנדר את זה בלבד: דוח שמשתנה בשקט
 * בין הרגע שנשלח לרגע שנקרא אינו בסיס לשיחה על כסף.
 */
class SiteAuditTest extends TestCase
{
    use RefreshDatabase;

    /** כתובת בלי סכימה היא מה שאדם מקליד, ו-https זה מה שהוא מתכוון. */
    public function test_a_bare_domain_becomes_a_secure_address(): void
    {
        $this->assertSame('https://example.co.il', SiteAuditor::normaliseUrl('example.co.il'));
        $this->assertSame('https://example.co.il', SiteAuditor::normaliseUrl('  example.co.il  '));
        $this->assertSame('http://example.co.il', SiteAuditor::normaliseUrl('http://example.co.il'));
    }

    /**
     * הכלי בודק אתרים פומביים בלבד.
     *
     * בלי הגבלה כזו הפאנל הופך לדרך לדפוק על דלתות בתוך הרשת שהוא מתארח בה,
     * ולקרוא בחזרה את מה שעונה.
     */
    public function test_an_address_inside_the_network_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/פנימית/u');

        $this->targetResolving(['10.0.0.5'])->assert('intranet.example.com');
    }

    /** ו-IPv6 נבדק גם הוא — שם שעונה רק שם היה חומק. */
    public function test_an_internal_ipv6_address_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/פנימית/u');

        $this->targetResolving(['::1'])->assert('intranet.example.com');
    }

    public function test_a_public_address_is_accepted(): void
    {
        $this->targetResolving(['93.184.216.34'])->assert('example.com');

        $this->assertTrue(true);
    }

    /**
     * לא כל מה שאינו "פרטי" הוא האינטרנט.
     *
     * טווח ה-CGNAT וטווח הבדיקות מנותבים בתוך רשתות רבות ומגיעים לדברים שאיש לא
     * התכוון לפרסם — ודגלי ה-private/reserved של PHP מעבירים את שניהם הלאה.
     */
    public function test_an_address_that_is_routable_but_not_public_is_refused(): void
    {
        foreach (['100.64.0.1', '198.18.0.1', '192.0.2.5', '64:ff9b::7f00:1', '2001:2::1', '3fff::1'] as $address) {
            $this->assertFalse(
                $this->targetResolving([$address])->allows('somewhere.example.com'),
                $address.' התקבלה ככתובת פומבית',
            );
        }
    }

    public function test_a_name_that_does_not_resolve_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/לאתר את הדומיין/u');

        $this->targetResolving([])->assert('nope.example.com');
    }

    public function test_a_malformed_address_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/תקינה/u');

        $this->targetResolving(['93.184.216.34'])->assert('not a host');
    }

    /** הכפתור פותח שורה ומעביר את העבודה לתור — לא מריץ אותה בתוך הקליק. */
    public function test_the_button_records_the_audit_and_queues_the_work(): void
    {
        Queue::fake();
        $this->bindTargetResolving(['93.184.216.34']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(SiteAudits::class)
            ->fillForm(['url' => 'example.com'])
            ->call('startAudit')
            ->assertNotified();

        $audit = SiteAudit::query()->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('https://example.com', $audit->url);
        $this->assertSame('example.com', $audit->host);
        $this->assertSame(SiteAudit::STATUS_RUNNING, $audit->status);

        Queue::assertPushed(RunSiteAuditJob::class);
    }

    /** כתובת פנימית נענית על המסך, מול מי שהקליד אותה — לא כשורה שנכשלה. */
    public function test_an_internal_address_is_answered_on_the_screen(): void
    {
        Queue::fake();
        $this->bindTargetResolving(['127.0.0.1']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(SiteAudits::class)
            ->fillForm(['url' => 'intranet.example.com'])
            ->call('startAudit')
            ->assertNotified();

        $this->assertSame(0, SiteAudit::query()->count());
        Queue::assertNothingPushed();
    }

    /** ריצה שנכשלה נראית ככזו, ולא נשארת "בבדיקה" לנצח. */
    public function test_a_failed_audit_is_recorded_as_failed(): void
    {
        $audit = SiteAudit::create([
            'url' => 'https://example.com',
            'host' => 'example.com',
            'status' => SiteAudit::STATUS_RUNNING,
        ]);

        $auditor = \Mockery::mock(SiteAuditor::class);
        $auditor->shouldReceive('run')->andThrow(new \RuntimeException('הדומיין אינו קיים'));

        (new RunSiteAuditJob($audit->id))->handle($auditor);

        $this->assertSame(SiteAudit::STATUS_FAILED, $audit->fresh()->status);
        $this->assertStringContainsString('הדומיין אינו קיים', (string) $audit->fresh()->error);
    }

    /**
     * ריצה מלאה מול אתר מומצא — הבדיקות מחוברות, והממצאים הם מה שבאמת נמצא.
     *
     * האתר המפוברק כאן שגוי בכוונה בכמה מישורים בבת אחת, כי זה המצב האמיתי:
     * אתר מוזנח אינו שבור בדבר אחד.
     */
    public function test_a_neglected_site_produces_the_findings_it_deserves(): void
    {
        $this->fakeCollaborators();

        Http::fake([
            'http://*' => Http::response('', 200),
            '*/robots.txt' => Http::response('User-agent: *'.PHP_EOL.'Disallow: /', 200),
            '*' => Http::response(
                '<html><head><title>דף</title></head><body>'
                .'<img src="a.jpg"><img src="b.jpg"><p>תוכן</p></body></html>',
                200,
            ),
        ]);

        $result = $this->auditorFor(['93.184.216.34'])->run('example.com');

        $titles = array_column($result['findings'], 'title');

        // גוגל חסום לגמרי — הסיבה הנפוצה ביותר לאתר שלא מופיע בחיפוש.
        $this->assertContains('הקובץ robots.txt חוסם את כל האתר מגוגל', $titles);
        // תמונות בלי תיאור — גם נגישות וגם דרישה בתקן הישראלי.
        $this->assertContains('לתמונות אין תיאור טקסטואלי', $titles);
        // אין הצהרת נגישות בשום מקום בדף.
        $this->assertContains('לא נמצאה הצהרת נגישות', $titles);
        // ואין כותרת ראשית.
        $this->assertContains('אין כותרת ראשית (H1) בדף', $titles);

        $this->assertGreaterThan(0, $result['summary']['counts']['critical']);
        $this->assertTrue($result['summary']['reachable']);
    }

    /** אתר שאינו נענה — הממצא היחיד שחשוב יותר מכל השאר. */
    public function test_a_site_that_does_not_answer_says_so_first(): void
    {
        $this->fakeCollaborators();

        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = $this->auditorFor(['93.184.216.34'])->run('example.com');

        $this->assertSame('האתר לא נענה', $result['findings'][0]['title']);
        $this->assertSame('critical', $result['findings'][0]['severity']);
        $this->assertFalse($result['summary']['reachable']);
    }

    /**
     * הפניה אל תוך הרשת נחסמת — גם כשהכתובת שהוקלדה עצמה תקינה.
     *
     * אתר פומבי לגמרי רשאי לענות "לך אל 169.254.169.254", ושומר שרץ פעם אחת על
     * הכתובת שהוקלדה היה מעביר את זה הלאה.
     */
    public function test_a_redirect_into_the_network_is_refused(): void
    {
        // The address typed in is fine; the place it sends the fetcher is not.
        // The fetcher asks this same guard about every hop, which is the whole
        // reason the question lives in one object rather than at one call site.
        $guard = new class extends PublicTarget
        {
            protected function resolve(string $host): array
            {
                return $host === 'example.com' ? ['93.184.216.34'] : ['169.254.169.254'];
            }
        };

        $this->assertTrue($guard->allows('example.com'));
        $this->assertFalse($guard->allows('redirected.example.net'));
    }

    /**
     * הפניה אל תוך הרשת עוצרת את הקריאה — גם כשההפניה מגיעה תוך כדי.
     *
     * הכתובת שהוקלדה נבדקה ואושרה; המקום שאליו האתר שלח את הבודק לא. מי שעוקב
     * אחרי ההפניה בלי לשאול שוב, מביא בחזרה את מה שיש בפנים.
     */
    public function test_a_redirect_into_the_network_stops_the_fetch(): void
    {
        $this->app->instance(PublicTarget::class, new class extends PublicTarget
        {
            protected function resolve(string $host): array
            {
                return $host === 'example.com' ? ['93.184.216.34'] : ['169.254.169.254'];
            }
        });

        Http::fake([
            'https://example.com' => Http::response('', 302, ['Location' => 'http://metadata.example.net/']),
            '*' => Http::response('סוד מהרשת הפנימית', 200),
        ]);

        $probe = SiteProbe::fetch('https://example.com');

        $this->assertNotNull($probe->error);
        $this->assertStringNotContainsString('סוד מהרשת הפנימית', $probe->body);
    }

    /**
     * התעודה שנבדקת היא זו שהמבקר באמת רואה.
     *
     * אתר שמפנה מעביר את המבקר בין תעודות, ולבדוק רק את הכתובת שהוקלדה זה
     * לאשר תעודה שאיש לא מגיע אליה — בזמן שבזו שכן מגיעים אליה מוצג מסך אזהרה.
     */
    public function test_the_certificate_judged_is_the_one_the_visitor_lands_on(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        $certificates = \Mockery::mock(CertificateInspector::class)->makePartial();
        $certificates->shouldReceive('inspect')->with('example.com', 443)->andReturn([
            'reachable' => true, 'trusted' => true, 'days_left' => 200, 'error' => null,
        ]);
        $certificates->shouldReceive('inspect')->with('elsewhere.example.net', 443)->andReturn([
            'reachable' => true, 'trusted' => false, 'days_left' => 200, 'error' => 'hostname mismatch',
        ]);
        $this->app->instance(CertificateInspector::class, $certificates);

        Http::fake([
            'https://example.com' => Http::response('', 301, ['Location' => 'https://elsewhere.example.net/']),
            '*' => Http::response('<html lang="he"><body></body></html>', 200),
        ]);

        $titles = array_column(app(Transport::class)->run($this->contextFor('example.com')), 'title');

        $this->assertContains('הדפדפן אינו מקבל את תעודת האבטחה', $titles);
    }

    /**
     * לתת-דומיין אין צורת www, ואין להאשים אותו בהיעדרה.
     *
     * shop.example.com הוא שם שמישהו בחר; www.shop.example.com הוא שם שהכלי
     * ממציא. ממצא על המצאה הוא תקלה שלאתר אין.
     */
    public function test_a_subdomain_is_not_faulted_for_a_www_it_never_had(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            'https://www.*' => Http::response('', 404),
            '*' => Http::response('<html lang="he"><body></body></html>', 200),
        ]);

        $subdomain = array_column(app(Availability::class)->run($this->contextFor('shop.example.com')), 'title');
        $domain = array_column(app(Availability::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertNotContains('הכתובת www.shop.example.com אינה עובדת', $subdomain);
        // ובדומיין עצמו — שם ל-www באמת יש משמעות — הממצא כן מופיע.
        $this->assertContains('הכתובת www.example.co.il אינה עובדת', $domain);
    }

    /**
     * קבוצה שנוקבת בשם גוגל גוברת על הכללית.
     *
     * "לכולם אסור, ולגוגל מותר" הוא אתר שמכניס את גוגל בכוונה. לדווח לו שהוא
     * חסום מגוגל זה לקרוא את הקובץ הפוך.
     */
    public function test_a_group_naming_google_beats_the_catch_all(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nAllow: /", 200),
            '*' => Http::response('<html lang="he"><head><title>ד</title></head><body><h1>כ</h1></body></html>', 200),
        ]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertNotContains('הקובץ robots.txt חוסם את כל האתר מגוגל', $titles);
    }

    /**
     * מאגר ששתק אינו מאגר שאמר "נקי".
     *
     * כשאחד המאגרים עונה והשני לא, הדוח נראה בדיוק כמו אישור נקיון — וזה הדבר
     * היחיד שאסור לו להיראות כמוהו.
     */
    public function test_a_source_that_did_not_answer_is_not_read_as_clean(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        $expiry = \Mockery::mock(DomainExpiry::class)->makePartial();
        $expiry->shouldReceive('expiresAt')->andReturn(null);
        $this->app->instance(DomainExpiry::class, $expiry);

        $reputation = \Mockery::mock(DomainReputationClient::class)->makePartial();
        $reputation->shouldReceive('check')->andReturn([
            'sources' => ['urlhaus' => true, 'spamhaus' => false],
            'listings' => [],
            'errors' => ['spamhaus' => 'לא נענה'],
        ]);
        $this->app->instance(DomainReputationClient::class, $reputation);

        Http::fake(['*' => Http::response('<html lang="he"><body></body></html>', 200)]);

        $findings = app(DomainHealth::class)->run($this->contextFor('example.com'));
        $titles = array_column($findings, 'title');

        $this->assertContains('בדיקת רשימות החסימה הושלמה חלקית', $titles);
    }

    /**
     * גם לחיצת היד של התעודה מחויגת לכתובת שאושרה.
     *
     * שתי חיצות היד כאן הן חיבור נוסף לרשת, ואם הן שואלות את ה-DNS מחדש הן
     * פותחות בדיוק את הפרצה שהשליפה סגרה — שם שהחליף כתובת בין שאלה לשאלה.
     */
    public function test_the_certificate_handshake_dials_the_approved_address(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        $inspector = new class(app(PublicTarget::class)) extends CertificateInspector
        {
            /** @var list<string> */
            public array $dialled = [];

            protected function connect(string $endpoint, mixed $context, ?string &$error = null)
            {
                $this->dialled[] = $endpoint;
                $error = 'לא נוסה חיבור אמיתי';

                return false;
            }
        };

        $result = $inspector->inspect('example.com');

        $this->assertSame(['ssl://93.184.216.34:443'], $inspector->dialled);
        $this->assertFalse($result['reachable']);
    }

    /**
     * שם עם כמה כתובות — מנסים את כולן.
     *
     * כתובת IPv6 מתה לצד IPv4 שמגישה את האתר היא מצב רגיל לגמרי, ולדווח
     * "לא ניתן לבדוק את התעודה" על אתר שהדפדפן פותח בלי היסוס זה לשלוח מישהו
     * לחפש תקלה שאין.
     */
    public function test_every_approved_address_is_tried_for_the_certificate(): void
    {
        $this->bindTargetResolving(['2606:2800:220::1', '93.184.216.34']);

        $inspector = new class(app(PublicTarget::class)) extends CertificateInspector
        {
            /** @var list<string> */
            public array $dialled = [];

            protected function connect(string $endpoint, mixed $context, ?string &$error = null)
            {
                $this->dialled[] = $endpoint;
                $error = 'אין מענה';

                return false;
            }
        };

        $inspector->inspect('example.com');

        $this->assertSame(
            ['ssl://[2606:2800:220::1]:443', 'ssl://93.184.216.34:443'],
            $inspector->dialled,
        );
    }

    /**
     * SPF נבדק גם על הדומיין עצמו, לא רק על הכתובת שהוקלדה.
     *
     * המדיניות מתפרסמת במקום שממנו יוצא הדואר — בדרך כלל example.com — בזמן
     * שהאתר יושב על shop.example.com. אזהרה על רשומה שקיימת, במסמך שנשלח
     * ללקוח, היא בדיוק סוג התקלה שהורס את אמון הקורא בכל השאר.
     */
    public function test_spf_is_looked_for_on_the_domain_and_not_only_on_the_subdomain(): void
    {
        $health = $this->domainHealthWithDns([
            'example.co.il|'.DNS_TXT => [['txt' => 'v=spf1 include:_spf.example.com ~all']],
            'shop.example.co.il|'.DNS_TXT => [],
        ]);

        $titles = array_column($health->run($this->contextFor('shop.example.co.il')), 'title');

        $this->assertNotContains('אין הגדרת SPF לדומיין', $titles);
    }

    /** ושאילתה שלא נענתה אינה "אין רשומה" — היא "לא נבדק". */
    public function test_a_dns_lookup_that_failed_is_not_reported_as_a_missing_record(): void
    {
        $health = $this->domainHealthWithDns([]);

        $titles = array_column($health->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('לא ניתן היה לבדוק את הגדרת ה-SPF', $titles);
        $this->assertNotContains('אין הגדרת SPF לדומיין', $titles);
        // וגם השאר שותקים — לא נשאלו, אז אין להם מה לומר.
        $this->assertNotContains('אין הגדרת DMARC לדומיין', $titles);
        $this->assertNotContains('לדומיין אין הגדרת דואר (MX)', $titles);
    }

    /**
     * הדומיין נבדק גם על מה שמחזיק את הדואר ואת האמון בו.
     *
     * SPF מסמן, DMARC מורה לחסום; MX הוא השאלה אם מייל לדומיין מגיע בכלל
     * לאנשהו; CAA הוא מי רשאי להנפיק תעודה בשמו; ושרת שמות יחיד הוא נקודת
     * כשל שמפילה גם אתר תקין לגמרי.
     */
    public function test_the_domain_is_judged_on_its_mail_and_its_trust_records(): void
    {
        $health = $this->domainHealthWithDns([
            'example.co.il|'.DNS_TXT => [['txt' => 'v=spf1 -all']],
            '_dmarc.example.co.il|'.DNS_TXT => [],
            'example.co.il|'.DNS_MX => [],
            'example.co.il|'.DNS_CAA => [],
            'example.co.il|'.DNS_NS => [['target' => 'ns1.example.net']],
        ]);

        $titles = array_column($health->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('אין הגדרת DMARC לדומיין', $titles);
        $this->assertContains('לדומיין אין הגדרת דואר (MX)', $titles);
        $this->assertContains('אין הגבלה על מי רשאי להנפיק תעודת אבטחה לדומיין', $titles);
        $this->assertContains('לדומיין מוגדר שרת שמות אחד בלבד', $titles);
    }

    /** DMARC שרק מדווח אינו DMARC שחוסם — ונאמר כך. */
    public function test_a_dmarc_policy_of_none_is_reported_as_not_yet_blocking(): void
    {
        $health = $this->domainHealthWithDns([
            'example.co.il|'.DNS_TXT => [['txt' => 'v=spf1 -all']],
            '_dmarc.example.co.il|'.DNS_TXT => [['txt' => 'v=DMARC1; p=none; rua=mailto:a@example.co.il']],
            'example.co.il|'.DNS_MX => [['target' => 'mail.example.co.il']],
            'example.co.il|'.DNS_CAA => [['value' => 'letsencrypt.org']],
            'example.co.il|'.DNS_NS => [['target' => 'ns1.example.net'], ['target' => 'ns2.example.net']],
        ]);

        $titles = array_column($health->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('הגדרת ה-DMARC אינה חוסמת התחזות', $titles);
        $this->assertNotContains('אין הגדרת DMARC לדומיין', $titles);
    }

    /**
     * A DomainHealth whose DNS answers are decided here.
     *
     * Keyed "name|type" so one map can settle SPF, DMARC, MX, CAA and NS at
     * once. A key that is missing is a lookup that did NOT answer, and one
     * mapped to [] is an authoritative "nothing published" — the distinction the
     * whole check turns on, and one real DNS cannot be asked to reproduce.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $records
     */
    private function domainHealthWithDns(array $records): DomainHealth
    {
        $this->bindTargetResolving(['93.184.216.34']);
        Http::fake(['*' => Http::response('<html lang="he"><body></body></html>', 200)]);

        $expiry = \Mockery::mock(DomainExpiry::class)->makePartial();
        $expiry->shouldReceive('expiresAt')->andReturn(null);

        $reputation = \Mockery::mock(DomainReputationClient::class)->makePartial();
        $reputation->shouldReceive('check')->andReturn([
            'sources' => ['urlhaus' => true], 'listings' => [], 'errors' => [],
        ]);

        return new class($expiry, $reputation, app(DnsLookup::class), $records) extends DomainHealth
        {
            /** @param array<string, array<int, array<string, mixed>>> $records */
            public function __construct(
                DomainExpiry $expiry,
                DomainReputationClient $reputation,
                DnsLookup $dns,
                private array $answers,
            ) {
                parent::__construct($expiry, $reputation, $dns);
            }

            protected function records(string $domain, int $type): ?array
            {
                return $this->answers[$domain.'|'.$type] ?? null;
            }
        };
    }

    /**
     * שתי כתובות שנענות אינן שתי כתובות שמסכימות.
     *
     * כששתיהן מגישות את האתר בלי שאחת מפנה לשנייה, אלה שני אתרים לגוגל עם אותו
     * תוכן — וזו בדיוק התקלה שהבדיקה הזו קיימת כדי למצוא, לא לצבוע בירוק.
     */
    public function test_two_names_that_do_not_agree_are_not_reported_as_fine(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response('<html lang="he"><body>אותו תוכן</body></html>', 200)]);

        $split = array_column(app(Availability::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('שתי צורות הכתובת מגישות את האתר בנפרד', $split);
    }

    /** ואתר שמצהיר על כתובת אחת כקנונית אמר בדיוק את מה שנדרש. */
    public function test_a_canonical_tag_settles_which_of_the_two_names_is_the_real_one(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            'https://www.example.co.il' => Http::response(
                '<html><head><link rel="canonical" href="https://example.co.il/"></head><body></body></html>', 200,
            ),
            '*' => Http::response('<html lang="he"><body></body></html>', 200),
        ]);

        $titles = array_column(app(Availability::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('שתי צורות הכתובת מובילות לאותו מקום', $titles);
    }

    /**
     * Googlebot-News אינו Googlebot.
     *
     * "לכולם אסור, ולחדשות מותר" הוא אתר שבאמת נעלם מהחיפוש, ולתת לסורק אחר
     * לחתום עליו כתקין זו הטעות שהכי יקר לגלות מאוחר.
     */
    public function test_a_group_naming_another_google_crawler_does_not_stand_in_for_the_main_one(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: Googlebot-News\nAllow: /", 200),
            '*' => Http::response('<html lang="he"><head><title>ד</title></head><body><h1>כ</h1></body></html>', 200),
        ]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertContains('הקובץ robots.txt חוסם את כל האתר מגוגל', $titles);
    }

    /**
     * סדר התכונות ב-HTML אינו קובע — ובדיקה שכן קובעת אותו ממציאה תקלה.
     *
     * ממצא כזב על תיאור שקיים הוא הראשון שהקורא בודק, והוא זה שמפיל את האמון
     * בכל מה שמתחתיו.
     */
    public function test_a_description_written_the_other_way_round_is_still_a_description(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(
            '<html lang="he"><head><title>ד</title>'
            .'<meta content="תיאור ארוך דיו של העסק והשירות שהוא מספק" name="description">'
            .'<meta content="כותרת" property="og:title"><meta content="/a.png" property="og:image">'
            .'</head><body><h1>כ</h1></body></html>', 200,
        )]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertNotContains('אין תיאור לדף', $titles);
        $this->assertNotContains('לינק לאתר משותף בלי תמונה וכותרת', $titles);
    }

    /**
     * CSP עם frame-ancestors הוא ההגנה המודרנית — ולא היעדר הגנה.
     *
     * לומר לאתר שעשה את הדבר הנכון שהוא חשוף זו טעות שגם פוגעת וגם מסגירה שהכלי
     * בודק נוכחות של כותרת ולא את השאלה עצמה.
     */
    public function test_a_content_security_policy_counts_as_framing_protection(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response('<html lang="he"><body></body></html>', 200, [
            'Strict-Transport-Security' => 'max-age=31536000',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ])]);

        $titles = array_column(app(SecurityHeaders::class)->run($this->contextFor('example.com')), 'title');

        $this->assertNotContains('ניתן להטמיע את האתר בתוך אתר אחר', $titles);
        $this->assertContains('הגנות הדפדפן מוגדרות', $titles);
    }

    /**
     * 403 מחומת אש אינו אתר שבור — הוא אתר שלא נתן לנו להיכנס.
     *
     * זו ההבחנה שמפרידה בין דוח שאומר "האתר שלך מקולקל" לבין דוח שאומר "האתר
     * שלך לא נתן לנו להסתכל". הראשון, כשהוא נאמר על אתר תקין, הוא השורה שמפילה
     * את האמון בכל המסמך — ובמקרה הזה גם כל בדיקות התוכן היו מדווחות על דף
     * החסימה: בלי כותרת, בלי H1, בלי טקסט חלופי ובלי הצהרת נגישות.
     */
    public function test_a_firewall_block_is_not_reported_as_a_broken_site(): void
    {
        $this->fakeCollaborators();

        Http::fake(['*' => Http::response(
            '<html><head><title>Attention Required! | Cloudflare</title></head>'
            .'<body>Please enable cookies. Cloudflare Ray ID: 8a2b</body></html>',
            403,
            ['CF-RAY' => '8a2b3c4d', 'Server' => 'cloudflare'],
        )]);

        $result = $this->auditorFor(['93.184.216.34'])->run('example.com');
        $titles = array_column($result['findings'], 'title');

        $this->assertContains('האתר חסם את הבדיקה', $titles);
        $this->assertNotContains('האתר מחזיר שגיאה 403', $titles);

        // ובדיקות התוכן עמדו מהצד — ואמרו שכך עשו.
        $this->assertContains('לא נבדק — האתר חסם את הבדיקה', $titles);
        $this->assertNotContains('לדף הראשי אין כותרת', $titles);
        $this->assertNotContains('לא נמצאה הצהרת נגישות', $titles);
        $this->assertNotContains('אין כותרת ראשית (H1) בדף', $titles);

        // חסום ולא-זמין הן שתי תשובות שונות, והדוח יודע להבחין ביניהן.
        $this->assertTrue($result['summary']['blocked']);
    }

    /** אתר מוגן בסיסמה נאמר בשמו, ולא כתקלה. */
    public function test_a_password_protected_site_is_named_as_such(): void
    {
        $this->fakeCollaborators();

        Http::fake(['*' => Http::response('', 401, ['WWW-Authenticate' => 'Basic realm="staging"'])]);

        $titles = array_column($this->auditorFor(['93.184.216.34'])->run('example.com')['findings'], 'title');

        $this->assertContains('האתר מוגן בסיסמה', $titles);
    }

    /**
     * נגישות — מה שקורא מסך וגולש מקלדת נתקלים בו בפועל.
     *
     * שדה בלי תווית הוא טופס יצירת קשר שלא נשלח; אתר שאוסר זום נועל בחוץ את מי
     * שצריך להגדיל טקסט; ועברית בלי dir="rtl" נשברת באמצע משפט.
     */
    public function test_the_accessibility_check_reads_forms_zoom_and_direction(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(
            '<html lang="he"><head>'
            .'<meta name="viewport" content="width=device-width, user-scalable=no">'
            .'</head><body><form>'
            .'<input type="text" placeholder="שם מלא"><input type="email" placeholder="אימייל">'
            .'<input type="submit" value="שליחה"></form>'
            .'<iframe src="/map"></iframe>'
            .'<a href="/f"><i class="icon"></i></a><a href="/t"><span class="icon"></span></a>'
            .'</body></html>', 200,
        )]);

        $titles = array_column(app(Accessibility::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('לשדות בטופס אין תווית', $titles);
        $this->assertContains('האתר מונע הגדלה במכשירים ניידים', $titles);
        $this->assertContains('האתר בעברית אך אינו מוגדר ככיוון ימין-לשמאל', $titles);
        $this->assertContains('לתוכן מוטמע בדף אין כותרת', $titles);
        $this->assertContains('קישורים ללא טקסט כלל', $titles);
        $this->assertContains('אין אזור תוכן ראשי מוגדר בדף', $titles);
    }

    /** ואתר שעשה את זה נכון אינו מקבל אף אחד מהם. */
    public function test_an_accessible_page_collects_none_of_those_findings(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(
            '<html lang="he" dir="rtl"><head>'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'</head><body><main><form>'
            .'<label for="n">שם מלא</label><input type="text" id="n">'
            .'<input type="email" aria-label="אימייל">'
            .'</form><iframe src="/map" title="מפת הגעה"></iframe></main></body></html>', 200,
        )]);

        $titles = array_column(app(Accessibility::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertNotContains('לשדות בטופס אין תווית', $titles);
        $this->assertNotContains('האתר מונע הגדלה במכשירים ניידים', $titles);
        $this->assertNotContains('האתר בעברית אך אינו מוגדר ככיוון ימין-לשמאל', $titles);
        $this->assertNotContains('לתוכן מוטמע בדף אין כותרת', $titles);
        $this->assertNotContains('אין אזור תוכן ראשי מוגדר בדף', $titles);
        $this->assertContains('לכל שדות הטופס יש תווית', $titles);
    }

    /**
     * מסמכי חובה — התחום היחיד שבו הממצא הוא חשיפה משפטית, לא איכות.
     *
     * אתר תדמית נשאל על נגישות, פרטיות ותקנון. הוא אינו נשאל על מדיניות
     * החזרות — דרישות חוק הגנת הצרכן חלות על מכירה, ולדרוש מאתר שאינו מוכר
     * מסמך שאינו חייב בו זו התראת שווא.
     */
    public function test_a_brochure_site_is_asked_only_for_the_documents_it_owes(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response('<html lang="he" dir="rtl"><body>אודות</body></html>', 200)]);

        $titles = array_column(app(LegalDocuments::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('לא נמצאה הצהרת נגישות', $titles);
        $this->assertContains('לא נמצאה מדיניות פרטיות', $titles);
        $this->assertContains('לא נמצא תקנון או תנאי שימוש', $titles);
        $this->assertNotContains('לא נמצאה מדיניות ביטולים והחזרות', $titles);
        $this->assertNotContains('לא נמצאו פרטי העוסק באתר', $titles);
    }

    /** חנות נשאלת גם על ביטולים ועל פרטי העוסק. */
    public function test_a_store_owes_the_consumer_law_documents_too(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(
            '<html lang="he" dir="rtl"><body><a href="/cart">עגלת קניות</a>'
            .'<button class="add-to-cart">הוספה לסל</button></body></html>', 200,
        )]);

        $titles = array_column(app(LegalDocuments::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertContains('לא נמצאה מדיניות ביטולים והחזרות', $titles);
        $this->assertContains('לא נמצאו פרטי העוסק באתר', $titles);
    }

    /** ואתר שפרסם הכול אינו מקבל ממצא — הוא מקבל אישור. */
    public function test_a_site_with_every_document_is_told_so(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(
            '<html lang="he" dir="rtl"><body><footer>'
            .'<a href="/accessibility">הצהרת נגישות</a>'
            .'<a href="/privacy">מדיניות פרטיות</a>'
            .'<a href="/terms">תקנון</a>'
            .'</footer></body></html>', 200,
        )]);

        $titles = array_column(app(LegalDocuments::class)->run($this->contextFor('example.co.il')), 'title');

        $this->assertSame(['כל מסמכי החובה מקושרים מהדף הראשי'], $titles);
    }

    /** תשובה אינסופית אינה מפילה את העובד — הקריאה נעצרת בגבול. */
    public function test_an_endless_response_is_cut_at_the_limit(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response(str_repeat('x', 900 * 1024), 200)]);

        $probe = SiteProbe::fetch('https://example.com');

        $this->assertLessThanOrEqual(512 * 1024, strlen($probe->body));
    }

    /**
     * תעודה שהדפדפן דוחה אינה "תעודה בתוקף".
     *
     * תאריך שטרם עבר אינו אומר דבר על תעודה שהונפקה לשם אחר או שאיש אינו חותם
     * עליה — ובכל אחד מהמקרים המבקר רואה מסך אזהרה במקום האתר.
     */
    public function test_an_untrusted_certificate_is_not_reported_as_valid(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        $certificates = \Mockery::mock(CertificateInspector::class)->makePartial();
        $certificates->shouldReceive('inspect')->andReturn([
            'reachable' => true, 'trusted' => false, 'days_left' => 300, 'error' => 'self signed certificate',
        ]);
        $this->app->instance(CertificateInspector::class, $certificates);

        Http::fake(['*' => Http::response('<html lang="he"><body></body></html>', 200)]);

        $titles = array_column(app(Transport::class)->run($this->contextFor('example.com')), 'title');

        $this->assertContains('הדפדפן אינו מקבל את תעודת האבטחה', $titles);
    }

    /**
     * robots.txt שחוסם רק סורק אחר אינו חוסם את גוגל.
     *
     * ההנחיות שייכות לקבוצת ה-user-agent שמעליהן, ואתר שסירב ל-GPTBot עשה משהו
     * מכוון ונכון. לדווח לו שהוא נעלם מגוגל זו התראת שווא מהסוג המבהיל, במסמך
     * שהוא לא יכול לאמת.
     */
    public function test_a_block_aimed_at_another_crawler_is_not_a_google_block(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            '*/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /", 200),
            '*' => Http::response('<html lang="he"><head><title>ד</title></head><body><h1>כ</h1></body></html>', 200),
        ]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertNotContains('הקובץ robots.txt חוסם את כל האתר מגוגל', $titles);
    }

    /** ובאמת חוסם — כשהקבוצה היא הכללית. */
    public function test_a_block_on_every_crawler_is_reported(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200),
            '*' => Http::response('<html lang="he"><head><title>ד</title></head><body><h1>כ</h1></body></html>', 200),
        ]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertContains('הקובץ robots.txt חוסם את כל האתר מגוגל', $titles);
    }

    /**
     * דף 404 שמחזיר 200 אינו מפת אתר.
     *
     * אתר שמגיש את הדף הרגיל שלו לכל נתיב לא מוכר היה גורם לכל ניחוש להיראות
     * כמו מפת אתר, והבדיקה הייתה עוברת בשקט לאתר שאין לו אחת.
     */
    public function test_a_soft_404_is_not_mistaken_for_a_sitemap(): void
    {
        $this->bindTargetResolving(['93.184.216.34']);

        Http::fake(['*' => Http::response('<html lang="he"><head><title>ד</title></head><body><h1>כ</h1></body></html>', 200)]);

        $titles = array_column(app(Discoverability::class)->run($this->contextFor('example.com')), 'title');

        $this->assertContains('לא נמצאה מפת אתר', $titles);
    }

    /** המסמך מרנדר את מה שנשמר — ולא בודק שוב. */
    public function test_the_report_renders_what_was_stored(): void
    {
        $audit = $this->completed();

        $pdf = app(AuditReport::class)->pdf($audit);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('example.com', app(AuditReport::class)->filename($audit));
    }

    /** הממצאים מסודרים לפי דחיפות, ומה שתקין אינו נספר כבעיה. */
    public function test_problems_come_worst_first_and_exclude_what_passed(): void
    {
        $audit = $this->completed();

        $problems = $audit->problems();

        $this->assertCount(3, $problems);
        $this->assertSame('critical', $problems[0]['severity']);
        $this->assertSame('warning', $problems[1]['severity']);
        $this->assertSame('notice', $problems[2]['severity']);
        $this->assertSame(1, $audit->count('ok'));
    }

    /**
     * A guard whose DNS answers are decided here.
     *
     * Deciding what a name resolves to is its whole job, and one that can only
     * be exercised against the live internet is a guard nobody checks —
     * including the case that matters most: a public NAME pointing inwards.
     *
     * @param  list<string>  $addresses
     */
    private function targetResolving(array $addresses): PublicTarget
    {
        return new class($addresses) extends PublicTarget
        {
            /** @param list<string> $addresses */
            public function __construct(private array $addresses) {}

            protected function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    /** @param list<string> $addresses */
    private function bindTargetResolving(array $addresses): void
    {
        $this->app->instance(PublicTarget::class, $this->targetResolving($addresses));
    }

    /** @param list<string> $addresses */
    private function auditorFor(array $addresses): SiteAuditor
    {
        $this->bindTargetResolving($addresses);

        return app(SiteAuditor::class);
    }

    /** The context a single check reads from, for tests that exercise one check. */
    private function contextFor(string $host): AuditContext
    {
        return new AuditContext('https://'.$host, $host, SiteProbe::fetch('https://'.$host));
    }

    /**
     * The collaborators that reach the world by other means than HTTP.
     *
     * Left real they would make the suite depend on a certificate handshake and
     * a WHOIS lookup — slow, and answering differently on a machine with no way
     * out.
     */
    private function fakeCollaborators(): void
    {
        $certificates = \Mockery::mock(CertificateInspector::class)->makePartial();
        $certificates->shouldReceive('inspect')->andReturn([
            'reachable' => true, 'trusted' => true, 'days_left' => 120, 'error' => null,
        ]);
        $this->app->instance(CertificateInspector::class, $certificates);

        $expiry = \Mockery::mock(DomainExpiry::class)->makePartial();
        $expiry->shouldReceive('expiresAt')->andReturn(null);
        $this->app->instance(DomainExpiry::class, $expiry);

        $reputation = \Mockery::mock(DomainReputationClient::class)->makePartial();
        $reputation->shouldReceive('check')->andReturn(['sources' => [], 'listings' => [], 'errors' => []]);
        $this->app->instance(DomainReputationClient::class, $reputation);
    }

    private function completed(): SiteAudit
    {
        return SiteAudit::create([
            'url' => 'https://example.com',
            'host' => 'example.com',
            'status' => SiteAudit::STATUS_COMPLETED,
            'finished_at' => now(),
            'findings' => [
                ['severity' => 'ok', 'area' => 'זמינות', 'title' => 'האתר נטען'],
                ['severity' => 'notice', 'area' => 'מהירות', 'title' => 'סקריפטים חוסמים', 'detail' => 'ד', 'fix' => 'פ'],
                ['severity' => 'critical', 'area' => 'אבטחה', 'title' => 'אין HTTPS', 'detail' => 'ד', 'fix' => 'פ'],
                ['severity' => 'warning', 'area' => 'נגישות', 'title' => 'אין הצהרת נגישות', 'detail' => 'ד', 'fix' => 'פ'],
            ],
            'summary' => ['counts' => ['critical' => 1, 'warning' => 1, 'notice' => 1, 'ok' => 1]],
        ]);
    }
}
