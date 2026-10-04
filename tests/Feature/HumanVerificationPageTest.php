<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Filament\Widgets\SitesInTrouble;
use App\Jobs\CheckSiteContentJob;
use App\Jobs\CheckSiteLayoutJob;
use App\Jobs\MonitorSiteJob;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Monitoring\ChallengePage;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * "אמת שאתה אנושי" — דף אימות שמוצג במקום האתר.
 *
 * זו הנפילה שבדיקת הזמינות עיוורת לה לחלוטין: שכבת ההגנה מחזירה את דף האתגר
 * **בקוד 200**, זמן התגובה מצוין, והמסך מראה אתר ירוק ותקין — בזמן שאף גולש
 * אינו רואה את האתר. לכן אין כאן סף של כמה בדיקות: הבדיקה הראשונה שרואה זאת
 * מתריעה.
 *
 * והצד השני, שהוא עיקר העבודה: עמוד תוכן אמיתי שיש בו captcha או המילים "אני
 * לא רובוט" אינו אתגר. אזהרה שקופצת על עמוד "צור קשר" נקראת פעם אחת ואז הצוות
 * מפסיק לקרוא את כולן.
 */
class HumanVerificationPageTest extends TestCase
{
    use RefreshDatabase;

    /** דף אתגר אמיתי של Cloudflare: כמעט בלי טקסט, עם הנתיב המזהה שלה. */
    private const CLOUDFLARE_PAGE = <<<'HTML'
        <!DOCTYPE html><html><head><title>Just a moment...</title></head>
        <body><div>Verify you are human by completing the action below.</div>
        <script src="/cdn-cgi/challenge-platform/h/b/orchestrate/chl_page/v1"></script>
        </body></html>
        HTML;

    private function site(array $attributes = []): Site
    {
        return Site::factory()->create(array_merge([
            'domain' => 'guarded.example.com',
            'monitor_url' => 'https://guarded.example.com',
            'monitor_enabled' => true,
        ], $attributes));
    }

    /** התראה שנבלעת — הצוות לא מקבל כלום — היא כישלון הבדיקה. */
    private function expectAlert(string $contains, int $times = 1): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->times($times)
            ->withArgs(fn (string $title): bool => str_contains($title, $contains));
        $this->app->instance(TeamNotifier::class, $team);
    }

    private function silenceAlerts(): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->zeroOrMoreTimes();
        $this->app->instance(TeamNotifier::class, $team);
    }

    /**
     * המקרה שבגללו כל זה קיים: קוד 200, דף אימות, והמערכת לא אמרה דבר.
     *
     * ההתראה נשלחת בבדיקה הראשונה — בלי סף של בדיקות כושלות, שלא קיים כאן בכלל
     * מפני שמבחינת הסטטוס שום דבר לא נכשל.
     */
    public function test_a_verification_page_served_with_http_200_is_reported_immediately(): void
    {
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);
        $this->expectAlert('אמת שאתה אנושי');

        MonitorSiteJob::dispatchSync($site->id);

        $check = $site->monitorChecks()->latest('checked_at')->first();
        $this->assertSame('cloudflare', $check->challenge);
        $this->assertStringContainsString('דף אימות אנושי', (string) $check->error);
        $this->assertStringContainsString('Cloudflare', (string) $check->error);
        $this->assertNotNull($site->refresh()->challenge_alerted_at);

        // וגם ביומן האתר, כדי שיהיה מה להראות ללקוח אחר כך.
        $this->assertTrue(SiteEvent::where('site_id', $site->id)->where('type', 'challenge')->exists());
    }

    /** כותרת cf-mitigated לבדה מכריעה — Cloudflare אומרת זאת במפורש. */
    public function test_the_cloudflare_header_alone_is_enough(): void
    {
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response('<html></html>', 403, ['cf-mitigated' => 'challenge'])]);
        $this->expectAlert('אמת שאתה אנושי');

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertSame('cloudflare', $site->monitorChecks()->latest('checked_at')->first()->challenge);
    }

    /**
     * 403 שזוהה כדף אתגר מקבל סיבה מדויקת במקום "כנראה הגנת בוטים".
     *
     * ההבדל אינו ניסוחי: "כנראה" הוא ניחוש שאי אפשר לעשות איתו דבר, ושם הספק
     * והסימן המזהה אומרים למי לפנות ומה לחפש.
     */
    public function test_a_403_challenge_names_the_vendor_instead_of_guessing(): void
    {
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 403)]);
        $this->silenceAlerts();

        MonitorSiteJob::dispatchSync($site->id);

        $check = $site->monitorChecks()->latest('checked_at')->first();
        $this->assertStringNotContainsString('כנראה הגנת בוטים', (string) $check->error);
        $this->assertStringContainsString('Cloudflare', (string) $check->error);
    }

    /** התראה אחת למצב, לא אחת לכל בדיקה — אחרת היא נהיית רעש תוך שעה. */
    public function test_it_alerts_once_and_then_stays_quiet(): void
    {
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);
        $this->expectAlert('אמת שאתה אנושי', times: 1);

        MonitorSiteJob::dispatchSync($site->id);
        MonitorSiteJob::dispatchSync($site->id);
        MonitorSiteJob::dispatchSync($site->id);

        // הבדיקות עצמן ממשיכות להירשם — רק ההתראה לא חוזרת.
        $this->assertSame(3, $site->monitorChecks()->whereNotNull('challenge')->count());
    }

    /** וכשזה נגמר אומרים גם את זה, ונדרכים מחדש לפעם הבאה. */
    public function test_it_announces_the_page_being_gone_and_re_arms(): void
    {
        $site = $this->site(['challenge_alerted_at' => now()->subHour()]);
        Http::fake(['https://guarded.example.com' => Http::response('<html><body><h1>האתר</h1></body></html>', 200)]);
        $this->expectAlert('חזר להיות גלוי');

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertNull($site->refresh()->challenge_alerted_at);
    }

    /**
     * אתר שנפל בזמן שדף האימות עמד — אסור לדווח ש"חזר להיות גלוי".
     *
     * timeout אינו ראיה לכך שהאתגר הוסר; הוא ראיה לכך שלא ראינו כלום. דיווח
     * "נפתר" על בסיס היעדר מידע הוא בדיוק סוג ההודעה שהופכת את כל השאר לחשודה.
     */
    public function test_a_failed_fetch_does_not_announce_the_page_being_gone(): void
    {
        $site = $this->site(['challenge_alerted_at' => now()->subHour()]);
        Http::fake(fn () => throw new \RuntimeException('Connection timed out'));

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->zeroOrMoreTimes()
            ->withArgs(fn (string $title): bool => ! str_contains($title, 'חזר להיות גלוי'));
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertNotNull($site->refresh()->challenge_alerted_at);
    }

    /**
     * 404 או 500 אחרי דף אתגר אינם "דף האימות הוסר".
     *
     * זו אותה שגיאה כמו ב-timeout, רק עם תגובה: שגיאת HTTP אינה ראיה לכך שהאתגר
     * ירד — היא ראיה לתקלה אחרת — ואישור "חזר להיות גלוי" על אתר שמחזיר 500 הוא
     * בדיוק הדיווח שהופך כל שאר ההתראות לחשודות.
     */
    public function test_an_http_error_does_not_announce_the_page_being_gone(): void
    {
        foreach ([404, 500] as $status) {
            $site = $this->site([
                'domain' => "err{$status}.example.com",
                'monitor_url' => "https://err{$status}.example.com",
                'challenge_alerted_at' => now()->subHour(),
            ]);
            Http::fake(["https://err{$status}.example.com" => Http::response('<html><body>שגיאה</body></html>', $status)]);

            $team = Mockery::mock(TeamNotifier::class);
            $team->shouldReceive('alert')->zeroOrMoreTimes()
                ->withArgs(fn (string $title): bool => ! str_contains($title, 'חזר להיות גלוי'));
            $this->app->instance(TeamNotifier::class, $team);

            MonitorSiteJob::dispatchSync($site->id);

            $this->assertNotNull($site->refresh()->challenge_alerted_at, "status {$status}");
        }
    }

    /**
     * שתי בדיקות שרצות במקביל אינן שולחות שתי התראות על אותו מצב.
     *
     * הבדיקות הן עבודות תור רגילות בלי ייחודיות לפי אתר, ולכן שתיים יכולות
     * להיקרא יחד ולראות שתיהן דגל ריק. רק זו שה-UPDATE שלה באמת הפך את השורה
     * מדווחת — אחרת "פעם אחת למצב" מגיע פעמיים.
     */
    public function test_two_overlapping_probes_announce_once(): void
    {
        $site = $this->site();
        $this->expectAlert('אמת שאתה אנושי', times: 1);

        // המצב האמיתי שנבדק כאן הוא שתי בדיקות שכל אחת **טענה** את האתר לפני
        // שהשנייה סימנה — ולכן שני המופעים נטענים כאן מראש. הרצת שתי העבודות
        // במלואן לא הייתה בודקת כלום: השנייה טוענת את האתר מחדש וכבר רואה את
        // הסימון של הראשונה.
        $stale = [Site::find($site->id), Site::find($site->id)];

        $probe = new class(0) extends MonitorSiteJob
        {
            /** @param  array{vendor: string, marker: string}  $detected */
            public function announce(Site $site, TeamNotifier $team, ChallengePage $page, array $detected): void
            {
                $this->evaluateChallenge($site, $team, $page, $detected, servedRealPage: true);
            }
        };

        foreach ($stale as $instance) {
            $probe->announce($instance, app(TeamNotifier::class), app(ChallengePage::class),
                ['vendor' => 'cloudflare', 'marker' => 'cf_chl_opt']);
        }

        $this->assertNotNull($site->refresh()->challenge_alerted_at);
        $this->assertSame(1, SiteEvent::where('site_id', $site->id)->where('type', 'challenge')->count());
    }

    /**
     * זיהוי שכובה בזמן שדגל עומד — המסך לא נשאר תקוע על "דף אימות אנושי".
     *
     * אי אפשר לטעון שזה נפתר (לא בדקנו), ואי אפשר להמשיך להציג ממצא על סמך
     * קריאה שלא תתעדכן יותר. הדגל נוקה בשקט, עם רישום ביומן ובלי התראת "הוסר"
     * שתהיה שקרית.
     */
    public function test_disabling_detection_clears_a_standing_flag_without_claiming_it_was_fixed(): void
    {
        config(['billing.monitoring.challenge.enabled' => false]);
        $site = $this->site(['challenge_alerted_at' => now()->subHour()]);
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertNull($site->refresh()->challenge_alerted_at);
        $this->assertTrue(SystemLog::where('message', 'like', '%נוקה מפני שאינו נבדק יותר%')->exists());
    }

    /**
     * ברירת המחדל: דף אתגר אינו "נפילה" — ולכן אינו פותח תקלה, כרטיס דחוף
     * והצעת תיקון אוטומטית על אתר שאולי עובד מצוין לגולשים.
     */
    public function test_by_default_it_does_not_open_an_incident(): void
    {
        config(['billing.monitoring.failures_to_incident' => 1]);
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);
        $this->silenceAlerts();

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->monitorChecks()->latest('checked_at')->first()->is_up);
        $this->assertFalse($site->openIncident()->exists());
    }

    /** ולמי שמעדיף — הגדרה אחת הופכת את זה לנפילה לכל דבר. */
    public function test_it_can_be_configured_to_count_as_downtime(): void
    {
        config([
            'billing.monitoring.failures_to_incident' => 1,
            'billing.monitoring.challenge.counts_as_down' => true,
        ]);
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);
        $this->silenceAlerts();

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertFalse($site->monitorChecks()->latest('checked_at')->first()->is_up);
        $this->assertTrue($site->openIncident()->exists());
    }

    /** כיבוי מלא — לא התראה, ולא רישום. */
    public function test_detection_can_be_switched_off(): void
    {
        config(['billing.monitoring.challenge.enabled' => false]);
        $site = $this->site();
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertNull($site->monitorChecks()->latest('checked_at')->first()->challenge);
        $this->assertNull($site->refresh()->challenge_alerted_at);
    }

    /**
     * דף אתגר אינו השחתה ואינו מבנה שבור — ושתי הבדיקות האלה היו צועקות עליו.
     *
     * שתיהן מודדות מול בסיס שמור, ודף אתגר הוא עמוד כמעט ריק: הדמיון לתוכן
     * המוכר מתרסק, וכל סימני המבנה (תפריט, כותרת, פוטר) נעלמים. בלי הדילוג הזה
     * כל אתר שעובר למצב אתגר היה מייצר שלוש התראות שונות, ששתיים מהן שקריות
     * וקוברות את הנכונה — ובמקרה של בדיקת ההשחתה גם מזהמות את בסיס התוכן.
     */
    public function test_the_defacement_and_layout_watches_skip_a_verification_page(): void
    {
        $site = $this->site(['content_snapshot' => [
            'checked_at' => now()->subDay()->toIso8601String(),
            'title' => 'חנות',
            'text' => str_repeat('מוצרים שלנו הוסף לסל מבצעים ', 40),
            'hash' => 'x',
            'length' => 500,
            'suspected' => false,
            'alerted_at' => null,
        ]]);
        $baseline = $site->content_snapshot;

        Http::fake(['https://guarded.example.com/*' => Http::response(self::CLOUDFLARE_PAGE, 200)]);
        Http::fake(['https://guarded.example.com' => Http::response(self::CLOUDFLARE_PAGE, 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->never();
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteContentJob::dispatchSync($site->id);
        CheckSiteLayoutJob::dispatchSync($site->id);

        // הבסיס לא נגע, ולא נרשם חשד.
        $this->assertSame($baseline, $site->refresh()->content_snapshot);
        $this->assertFalse(SiteEvent::where('site_id', $site->id)->where('type', 'defacement')->exists());
        $this->assertFalse(SiteEvent::where('site_id', $site->id)->where('type', 'layout_broken')->exists());

        // ושתי הדילוגים מתועדים — דילוג שקט נראה כמו בדיקה שעברה.
        $this->assertSame(2, SystemLog::where('message', 'like', '%דף אימות אנושי%')->count());
    }

    /**
     * מסך האתר אומר את זה — ולא "זמין" בירוק.
     *
     * כל מספר אחר במסך הזה ירוק בזמן שדף האימות עומד: הזמינות 100%, זמן התגובה
     * מצוין, הקוד 200. בלי חיווי מפורש מסך האתר הוא ההוכחה הכי משכנעת שהכול תקין.
     */
    public function test_the_site_screen_says_so_instead_of_showing_plain_green(): void
    {
        $this->actingAs(User::factory()->create());
        $site = $this->site(['challenge_alerted_at' => now()->subMinutes(10)]);
        $site->monitorChecks()->create([
            'checked_at' => now(),
            'is_up' => true,
            'status_code' => 200,
            'response_ms' => 120,
            'error' => 'דף אימות אנושי (Cloudflare) מוצג במקום האתר',
            'challenge' => 'cloudflare',
        ]);

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->assertSeeText('דף אימות אנושי')
            ->assertSeeText('Cloudflare')
            ->assertSeeText('פתחו את האתר בחלון פרטי');
    }

    /** ובלוח הבקרה — אתר כזה נמצא ברשימת "אתרים בבעיה", למרות שהוא מחזיר 200. */
    public function test_the_dashboard_lists_it_among_the_sites_in_trouble(): void
    {
        $this->actingAs(User::factory()->create());
        $site = $this->site(['challenge_alerted_at' => now()]);

        $this->assertTrue(SitesInTrouble::canView());

        Livewire::test(SitesInTrouble::class)
            ->assertSeeText($site->domain)
            ->assertSeeText('דף אימות אנושי');
    }
}
