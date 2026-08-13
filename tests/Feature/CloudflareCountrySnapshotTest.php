<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Jobs\RefreshCloudflareCountryRulesJob;
use App\Models\User;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CountryRulesSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * למה חלון "כלל מדינות ב-Cloudflare" נפתח מיד.
 *
 * קודם הוא קרא את המצב האמיתי מ-Cloudflare בזמן שנפתח: שתי קריאות API לכל אתר
 * בחשבון, אחת אחרי השנייה, בתוך אותה בקשת HTTP שהמשתמש ממתין לה. עם עשרות
 * זונים זה ארוך יותר ממה שבקשת רשת אמורה לחיות — החלון נטען הרבה זמן ובסוף לא
 * נטען כלל. וגרוע מכך: בקשה שנקטעה לא הספיקה לשמור דבר בקאש, ולכן כל ניסיון
 * חוזר שילם שוב את המחיר המלא ומת באותו מקום.
 *
 * הקריאה עברה לעבודה ברקע. החלון מציג את הקריאה האחרונה ואת מתי היא נעשתה, ואת
 * שלוש הדרכים שבהן היא עלולה לא לשקף את המצב — לא נקרא מעולם, הרענון האחרון
 * נכשל, או שהכללים שונו מהמסך אחרי הקריאה — כל אחת נאמרת במפורש.
 */
class CloudflareCountrySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['billing.cloudflare.api_token' => 'saved-token']);
    }

    /**
     * Flipped mid-test to make Cloudflare stop answering. A flag rather than a
     * second Http::fake() call, because stubs accumulate and the first match
     * wins — a later fake would never be reached.
     */
    private bool $cloudflareIsDown = false;

    /** @param  list<string>  $zones */
    private function fakeAccount(array $zones, array $overrides = []): void
    {
        $answer = fn (array $body): \Closure => fn () => $this->cloudflareIsDown
            ? Http::response(['success' => false], 500)
            : Http::response($body);

        Http::fake($overrides + [
            '*/access_rules/rules*' => $answer(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            '*/rulesets/phases/*' => $answer(['success' => true, 'result' => ['id' => 'rs', 'rules' => [[
                'id' => 'r', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
                'expression' => '(ip.src.country in {"MX"})', 'action' => 'block',
            ]]]]),
            '*/zones*' => $answer([
                'success' => true,
                'result' => collect($zones)->map(fn (string $id): array => ['id' => $id, 'name' => $id.'.com'])->all(),
                'result_info' => ['total_pages' => 1],
            ]),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /*
    | ----------------------------------------------------------------
    | המשקל
    | ----------------------------------------------------------------
    */

    /** פתיחת החלון אינה פונה ל-Cloudflare כלל. */
    public function test_opening_the_window_does_not_call_cloudflare(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1', 'z2']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        Http::fake();

        Livewire::test(ListSites::class)->mountAction('countryRule')->assertOk();

        // זה כל התיקון: מה שהמתין לרשת עבר לרקע.
        Http::assertNothingSent();
    }

    /**
     * והקריאה עצמה קוראת כל אתר פעם אחת.
     *
     * קודם היו שתי סקירות נפרדות שכל אחת מהן רצה על כל הזונים — אחת קראה את
     * כללי ה-WAF ואת כללי הגישה, והשנייה קראה את כללי הגישה שוב לאותם בתים.
     */
    public function test_the_whole_account_is_read_in_one_pass(): void
    {
        $this->fakeAccount(['z1', 'z2', 'z3']);

        $snapshot = app(CloudflareClient::class)->countrySnapshot('saved-token');

        $this->assertTrue($snapshot['ok']);
        $this->assertSame(3, $snapshot['total_zones']);

        // רשימת הזונים פעם אחת, ולכל זון: כללי WAF וכללי גישה. לא יותר.
        $this->assertSame(1 + 3 + 3, Http::recorded()->count());
    }

    /*
    | ----------------------------------------------------------------
    | מה שהקריאה אינה יודעת — נאמר
    | ----------------------------------------------------------------
    */

    /** לפני שנקרא משהו, החלון אומר זאת — ולא מציג רשימה ריקה. */
    public function test_a_window_with_nothing_read_yet_says_so(): void
    {
        $this->actingAs($this->admin());
        Http::fake();

        // "אין כללים" ו"לא בדקנו" נראים אותו דבר בתיבה ריקה, והם הפוכים.
        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('עדיין לא נקראו מ-Cloudflare');
    }

    /** ומתי כן נקרא — נאמר גם הוא. */
    public function test_the_reading_is_shown_with_its_age(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('נקרא מ-Cloudflare');
    }

    /** רענון שנכשל שומר את הקריאה הקודמת ומסמן אותה כלא מאומתת. */
    public function test_a_failed_refresh_keeps_the_previous_reading_and_says_it_failed(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1']);
        (new RefreshCloudflareCountryRulesJob)->handle(app(CloudflareClient::class));

        $this->assertNotNull(CountryRulesSnapshot::read());

        // Cloudflare מפסיקה לענות.
        $this->cloudflareIsDown = true;
        (new RefreshCloudflareCountryRulesJob)->handle(app(CloudflareClient::class));

        $reading = CountryRulesSnapshot::read();

        // הרשימה עדיין הדבר הטוב ביותר שידוע לנו — אבל היא כבר לא עדות.
        $this->assertNotNull($reading);
        $this->assertSame(['MX'], $reading['data']['actions']['block']['countries']);
        $this->assertNotNull($reading['error']);

        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('הקריאה האחרונה');
    }

    /**
     * וכישלון שקדם לכל קריאה מוצלחת מוצג גם הוא.
     *
     * טוקן שגוי נכשל לפני שיש מה להראות, ואז הכישלון הוא כל מה שיש לומר. אם הוא
     * נבלע, המסך מודיע "עדיין לא נקרא" — כאילו אף אחד לא ניסה — והסיבה האמיתית
     * נעלמת.
     */
    public function test_a_failure_before_any_reading_is_shown_and_not_swallowed(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1']);
        $this->cloudflareIsDown = true;

        (new RefreshCloudflareCountryRulesJob)->handle(app(CloudflareClient::class));

        $reading = CountryRulesSnapshot::read();

        $this->assertNotNull($reading);
        $this->assertNull($reading['data']);
        $this->assertNotNull($reading['error']);

        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('הקריאה האחרונה')
            ->assertDontSee('עדיין לא נקראו מ-Cloudflare');
    }

    /**
     * קריאה שהתחילה לפני שינוי לא תוצג כמצב שאחריו.
     *
     * הקריאה השעתית עשויה להיות באוויר בדיוק כשמשנים כללים מהמסך. היא קראה את
     * המצב הישן ותסתיים אחרי השינוי — ובלי הבחנה הייתה נשמרת כתמונה טרייה
     * ומדויקת של מה שכבר לא נכון.
     */
    public function test_a_reading_that_began_before_a_change_is_not_stored_as_the_state_after_it(): void
    {
        $this->fakeAccount(['z1']);

        // הקריאה מתחילה...
        $revision = CountryRulesSnapshot::revision();
        $snapshot = app(CloudflareClient::class)->countrySnapshot('saved-token');

        // ...ובאמצע מישהו משנה את הכללים מהמסך...
        CountryRulesSnapshot::markStale();

        // ...ורק אז היא מסתיימת ונשמרת.
        CountryRulesSnapshot::store($snapshot, $revision);

        $this->assertTrue(CountryRulesSnapshot::read()['stale']);
    }

    /** וקריאה ישנה שהסתיימה מאוחר לא תדרוס קריאה חדשה ממנה. */
    public function test_a_late_finishing_older_reading_does_not_displace_a_newer_one(): void
    {
        $this->fakeAccount(['z1']);
        $stale = app(CloudflareClient::class)->countrySnapshot('saved-token');
        $staleRevision = CountryRulesSnapshot::revision();

        CountryRulesSnapshot::markStale();

        // הקריאה שאחרי השינוי נשמרת ראשונה.
        CountryRulesSnapshot::store(['ok' => true, 'actions' => ['block' => ['countries' => ['HK'], 'zones' => 1, 'consistent' => true]], 'legacy' => [], 'unreadable' => 0, 'total_zones' => 1]);

        // והישנה מסתיימת אחריה.
        CountryRulesSnapshot::store($stale, $staleRevision);

        $reading = CountryRulesSnapshot::read();

        $this->assertSame(['HK'], $reading['data']['actions']['block']['countries']);
        $this->assertFalse($reading['stale']);
    }

    /**
     * אתר שלא הצלחנו לקרוא נספר ונאמר — ואינו נחשב "אתר בלי כלל".
     *
     * ההבדל הזה הוא הכול: אתר שלא נקרא עלול להחזיק רשימה אחרת, ו"החלפת הרשימה
     * כולה" שנבנית על השאר הייתה דורסת אותה בלי שאיש ראה אותה.
     */
    public function test_a_zone_that_could_not_be_read_is_counted_rather_than_assumed_empty(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1', 'z2'], ['*/zones/z2/rulesets/phases/*' => Http::response([], 500)]);

        $snapshot = app(CloudflareClient::class)->countrySnapshot('saved-token');

        $this->assertSame(1, $snapshot['unreadable']);
        $this->assertFalse($snapshot['actions']['block']['consistent']);
        $this->assertSame([], $snapshot['actions']['block']['countries']);

        CountryRulesSnapshot::store($snapshot);

        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('לא נקראו');
    }

    /** שינוי מהמסך מסמן את התמונה השמורה כמתארת את המצב שלפניו. */
    public function test_changing_the_rules_marks_the_stored_picture_as_older_than_the_change(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        Queue::fake([RefreshCloudflareCountryRulesJob::class]);

        Livewire::test(ListSites::class)
            ->callAction('countryRule', data: [
                'mode' => 'block',
                'operation' => 'add',
                'countries' => ['HK'],
                'remove_legacy' => false,
            ])
            ->assertHasNoActionErrors();

        // הרשימה נשארת על המסך — אבל עם מה שהיא באמת: תמונה מלפני השינוי.
        $this->assertTrue(CountryRulesSnapshot::read()['stale']);
        Queue::assertPushed(RefreshCloudflareCountryRulesJob::class);
    }

    /** גם ריצה שנכשלה באמצע מסמנת — אז דווקא התמונה שווה הכי מעט. */
    public function test_a_run_that_failed_halfway_marks_it_too(): void
    {
        $this->actingAs($this->admin());
        $this->fakeAccount(['z1']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        $this->cloudflareIsDown = true;
        Queue::fake([RefreshCloudflareCountryRulesJob::class]);

        Livewire::test(ListSites::class)
            ->callAction('countryRule', data: [
                'mode' => 'block',
                'operation' => 'add',
                'countries' => ['HK'],
                'remove_legacy' => false,
            ]);

        $this->assertTrue(CountryRulesSnapshot::read()['stale']);
        Queue::assertPushed(RefreshCloudflareCountryRulesJob::class);
    }

    /** כפתור הרענון מזמין את הקריאה — ואינו מנסה לחכות לה. */
    public function test_the_refresh_button_asks_for_a_reading_in_the_background(): void
    {
        $this->actingAs($this->admin());
        Queue::fake([RefreshCloudflareCountryRulesJob::class]);
        Http::fake();

        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertSee('קריאה מחדש מ-Cloudflare')
            ->call('refreshCountryRules');

        // הכפתור מזמין קריאה; הוא אינו ממתין לה, ובזה כל ההבדל.
        Queue::assertPushed(RefreshCloudflareCountryRulesJob::class);
        Http::assertNothingSent();
    }

    /*
    | ----------------------------------------------------------------
    | הקריאה שייכת לחשבון שממנו נקראה
    | ----------------------------------------------------------------
    */

    /** טוקן אחר הוא חשבון אחר, וקריאה שלו לא תוצג עליו. */
    public function test_a_reading_is_not_shown_after_the_token_is_replaced(): void
    {
        $this->fakeAccount(['z1']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        $this->assertNotNull(CountryRulesSnapshot::read());

        // טוקן חדש עשוי להיות של חשבון אחר לגמרי; הזונים הקודמים אינם שלו.
        config(['billing.cloudflare.api_token' => 'a-different-account']);

        $this->assertNull(CountryRulesSnapshot::read());
    }

    /** וכשאין טוקן — אין מה להציג, ואין למי לפנות. */
    public function test_without_a_token_nothing_is_shown_and_nothing_is_asked(): void
    {
        $this->fakeAccount(['z1']);
        CountryRulesSnapshot::store(app(CloudflareClient::class)->countrySnapshot('saved-token'));

        config(['billing.cloudflare.api_token' => '']);
        Http::fake();

        (new RefreshCloudflareCountryRulesJob)->handle(app(CloudflareClient::class));

        $this->assertNull(CountryRulesSnapshot::read());
        Http::assertNothingSent();
    }
}
