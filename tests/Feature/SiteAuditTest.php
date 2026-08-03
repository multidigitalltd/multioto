<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\SiteAudits;
use App\Jobs\RunSiteAuditJob;
use App\Models\SiteAudit;
use App\Models\User;
use App\Services\Audit\AuditReport;
use App\Services\Audit\Checks\Accessibility;
use App\Services\Audit\Checks\Availability;
use App\Services\Audit\Checks\Discoverability;
use App\Services\Audit\Checks\DomainHealth;
use App\Services\Audit\Checks\SecurityHeaders;
use App\Services\Audit\Checks\Speed;
use App\Services\Audit\Checks\Transport;
use App\Services\Audit\Checks\WordPressExposure;
use App\Services\Audit\SiteAuditor;
use App\Services\Hosting\SiteDiagnostics;
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

        $this->auditorResolving(['10.0.0.5'])->assertPublicTarget('intranet.example.com');
    }

    /** ו-IPv6 נבדק גם הוא — שם שעונה רק שם היה חומק. */
    public function test_an_internal_ipv6_address_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/פנימית/u');

        $this->auditorResolving(['::1'])->assertPublicTarget('intranet.example.com');
    }

    public function test_a_public_address_is_accepted(): void
    {
        $this->auditorResolving(['93.184.216.34'])->assertPublicTarget('example.com');

        $this->assertTrue(true);
    }

    public function test_a_name_that_does_not_resolve_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/לאתר את הדומיין/u');

        $this->auditorResolving([])->assertPublicTarget('nope.example.com');
    }

    public function test_a_malformed_address_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/תקינה/u');

        $this->auditorResolving(['93.184.216.34'])->assertPublicTarget('not a host');
    }

    /** הכפתור פותח שורה ומעביר את העבודה לתור — לא מריץ אותה בתוך הקליק. */
    public function test_the_button_records_the_audit_and_queues_the_work(): void
    {
        Queue::fake();
        $this->bindAuditorResolving(['93.184.216.34']);
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
        $this->bindAuditorResolving(['127.0.0.1']);
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

        $result = $this->auditorResolving(['93.184.216.34'])->run('example.com');

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

        $result = $this->auditorResolving(['93.184.216.34'])->run('example.com');

        $this->assertSame('האתר לא נענה', $result['findings'][0]['title']);
        $this->assertSame('critical', $result['findings'][0]['severity']);
        $this->assertFalse($result['summary']['reachable']);
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
     * A real auditor, checks and all, whose DNS answers are decided here.
     *
     * Deciding what a name resolves to is the guard's whole job, and one that
     * can only be exercised against the live internet is a guard nobody checks
     * — including the case that matters most: a public NAME pointing at a
     * private address.
     *
     * @param  list<string>  $addresses
     */
    private function auditorResolving(array $addresses): SiteAuditor
    {
        return new class(app(Availability::class), app(Transport::class), app(SecurityHeaders::class), app(WordPressExposure::class), app(Speed::class), app(Discoverability::class), app(Accessibility::class), app(DomainHealth::class), $addresses) extends SiteAuditor
        {
            /** @param list<string> $addresses */
            public function __construct(
                Availability $availability,
                Transport $transport,
                SecurityHeaders $headers,
                WordPressExposure $wordpress,
                Speed $speed,
                Discoverability $discoverability,
                Accessibility $accessibility,
                DomainHealth $domain,
                private array $addresses,
            ) {
                parent::__construct(
                    $availability, $transport, $headers, $wordpress,
                    $speed, $discoverability, $accessibility, $domain,
                );
            }

            protected function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }

    /** @param list<string> $addresses */
    private function bindAuditorResolving(array $addresses): void
    {
        $this->app->instance(SiteAuditor::class, $this->auditorResolving($addresses));
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
        $diagnostics = \Mockery::mock(SiteDiagnostics::class)->makePartial();
        $diagnostics->shouldReceive('sslDaysLeft')->andReturn(120);
        $this->app->instance(SiteDiagnostics::class, $diagnostics);

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
