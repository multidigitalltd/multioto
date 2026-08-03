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
use App\Services\Audit\Checks\Availability;
use App\Services\Audit\Checks\Discoverability;
use App\Services\Audit\Checks\DomainHealth;
use App\Services\Audit\Checks\Transport;
use App\Services\Audit\PublicTarget;
use App\Services\Audit\SiteAuditor;
use App\Services\Audit\SiteProbe;
use App\Services\Monitoring\DomainExpiry;
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
        foreach (['100.64.0.1', '198.18.0.1', '192.0.2.5', '64:ff9b::7f00:1'] as $address) {
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
