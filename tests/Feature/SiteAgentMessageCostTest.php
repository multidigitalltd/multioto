<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteAgentMessageCost;
use App\Jobs\SyncSiteAgentMessagingCostJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\SiteAgent\MessagingCostReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * עלות ההודעות מול מה שחויב.
 *
 * Every test here is one way this screen could lie, and the dangerous lie is
 * always the same shape: reporting ₪0, or a healthy margin, when the truth is
 * "we could not find out". ₪0 for messages is the one figure nobody questions,
 * so an unavailable cost has to stay visibly unavailable.
 */
class SiteAgentMessageCostTest extends TestCase
{
    use RefreshDatabase;

    /** The configured phone-number id, which the analytics filter resolves through. */
    private const PHONE_ID = '1234';

    /** @var array<string, mixed> The body the faked Graph API currently returns. */
    private array $metaBody = [];

    /** The status it returns with. */
    private int $metaStatus = 200;

    /** Whether the phone-number lookup succeeds. */
    private bool $numberResolves = true;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.phone_number_id' => self::PHONE_ID,
            'siteagent.whatsapp.token' => 'wa-token',
            'siteagent.whatsapp.waba_id' => '1079443834447382',
        ]);
    }

    /**
     * Point the faked Graph API at a body of our choosing for the analytics call.
     *
     * The response is held on the test and served through a closure, so calling
     * this again REPLACES it. Registering a second stub would only append one and
     * Laravel answers with the first that matches, so the earlier body would keep
     * winning — and a test that changes the response mid-way would quietly be
     * asserting against the old one.
     *
     * The number lookup is stubbed alongside, because the analytics call now
     * depends on it. Without it that call never happens, and a test asserting
     * "the refresh failed" would pass for a reason it never meant to test.
     *
     * @param  array<string, mixed>  $body
     */
    private function fakeGraph(array $body, int $status = 200): void
    {
        $this->metaBody = $body;
        $this->metaStatus = $status;
        $this->stubGraph();
    }

    /** Register the stubs once; they read the state the helpers above set. */
    private function stubGraph(): void
    {
        Http::fake([
            '*/'.self::PHONE_ID.'?*' => fn () => $this->numberResolves
                ? Http::response(['display_phone_number' => '+972 50-123-4567'])
                : Http::response(['error' => ['message' => 'nope']], 400),
            '*' => fn () => Http::response($this->metaBody, $this->metaStatus),
        ]);
    }

    /**
     * Meta's own shape — and the nesting is the point.
     *
     * pricing_analytics.data[].data_points[], not data_points on the envelope,
     * and the currency is a field of the ACCOUNT rather than of the analytics.
     * A fixture that flattens this passes against a contract that does not
     * exist, which is the one way these tests could all be green over a screen
     * that reports ₪0 forever.
     */
    private function fakeMeta(string $currency = 'ILS', ?array $points = null): void
    {
        $this->fakeGraph([
            'currency' => $currency,
            'pricing_analytics' => [
                'data' => [
                    ['data_points' => $points ?? [
                        ['pricing_category' => 'SERVICE', 'cost' => 12.20, 'volume' => 2000],
                        ['pricing_category' => 'AUTHENTICATION', 'cost' => 0.61, 'volume' => 100],
                    ]],
                ],
            ],
        ]);
    }

    /** A subscription a renewal can still collect on. */
    private function collectable(): Subscription
    {
        return Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->addWeek(),
        ]);
    }

    /**
     * Mark a charge's messages as sent inside the window and settled onto it.
     *
     * Revenue is attributed by when the messages went out, so a charge with no
     * usage rows behind it earns nothing — which is what the ledger says.
     */
    private function settle(Charge $charge, int $count, ?Customer $customer = null): void
    {
        $customer ??= Customer::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            SiteAgentUsage::create([
                'customer_id' => $customer->id,
                'provider_message_id' => 'wamid-'.$charge->id.'-'.$i,
                'billable' => true,
                'charge_id' => $charge->id,
                'sent_at' => now()->subDay(),
            ]);
        }
    }

    private function billedCharge(int $count, int $netAgorot, array $overrides = []): Charge
    {
        return Charge::create(array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'status' => ChargeStatus::Succeeded,
            'amount_agorot' => $netAgorot,
            'vat_agorot' => 0,
            'total_agorot' => $netAgorot,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'description' => 'חידוש',
            'lines' => [
                ['kind' => 'plan', 'name' => 'מנוי', 'qty' => 1, 'unit_price_agorot' => 14900],
                ['kind' => 'messages', 'name' => 'הודעות', 'qty' => 1,
                    'unit_price_agorot' => $netAgorot, 'count' => $count, 'net_agorot' => $netAgorot],
            ],
        ], $overrides));
    }

    /*
    | ----------------------------------------------------------------
    | העלות מצד מטא
    | ----------------------------------------------------------------
    */

    public function test_the_cost_is_read_from_meta_by_category_and_turned_into_agorot(): void
    {
        $this->fakeMeta();

        $this->assertTrue(app(MessagingCostReport::class)->refresh()['ok']);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame('ILS', $summary['currency']);
        // 12.20 + 0.61 = 12.81 שקל → 1281 אגורות.
        $this->assertSame(1281, $summary['cost']['total']);
        $this->assertSame(2100, $summary['cost']['messages']);
        $this->assertSame(1220, $summary['cost']['by_category']['service']['cost']);
        $this->assertSame(61, $summary['cost']['by_category']['authentication']['cost']);
    }

    /**
     * אגורה אחת להודעה אינה נעלמת בעיגול.
     *
     * 0.61 × 100 יוצא 60.999… בבינארי, ו-(int) עליו מחזיר 60. על עשרת אלפים
     * שורות זה הפרש שמצטבר לכדי שקלים, בכיוון שמחמיא למרווח.
     */
    public function test_a_fractional_amount_is_rounded_and_not_truncated(): void
    {
        $this->fakeMeta('ILS', [['pricing_category' => 'service', 'cost' => 0.61, 'volume' => 100]]);

        app(MessagingCostReport::class)->refresh();

        $this->assertSame(61, app(MessagingCostReport::class)->summary()['cost']['total']);
    }

    /**
     * עלות שלא התקבלה אינה ₪0.
     *
     * מטא אינה מחזירה COST בכלל לחשבון שעובד דרך קו אשראי של שותף, וטוקן יכול
     * לפוג. ₪0 על הודעות הוא המספר היחיד שאף אחד לא בודק.
     */
    public function test_an_unavailable_cost_is_null_and_carries_the_reason(): void
    {
        $this->fakeGraph(['error' => ['message' => 'Unsupported get request.']], 400);

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);

        $summary = app(MessagingCostReport::class)->summary();
        $this->assertNull($summary['cost']);
        $this->assertNull($summary['margin']);
        $this->assertStringContainsString('Unsupported', (string) $summary['cost_error']['reason']);
    }

    /** ובלי מזהה WABA — אין קריאה בכלל, ונאמר מה חסר. */
    public function test_without_a_waba_id_nothing_is_called_and_the_gap_is_named(): void
    {
        config(['siteagent.whatsapp.waba_id' => '']);
        Http::fake();

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('WABA', (string) $result['reason']);
        Http::assertNothingSent();
    }

    /**
     * מזהה WABA שאינו מספרי אינו נכנס לכתובת.
     *
     * הוא מוזן בידי אדם ומשורשר לנתיב של Graph API. בלי הבדיקה, ערך עם "../"
     * היה פונה לנתיב אחר לגמרי באותו דומיין, עם הטוקן שלנו.
     */
    public function test_a_non_numeric_waba_id_is_refused_before_any_request(): void
    {
        config(['siteagent.whatsapp.waba_id' => '123/../me']);
        Http::fake();

        $this->assertFalse(app(MessagingCostReport::class)->refresh()['ok']);
        Http::assertNothingSent();
    }

    /**
     * מטא שמדווחת בדולרים אינה מחוסרת משקלים.
     *
     * חיסור בין שני מטבעות דורש שער, ושער שהיינו בוחרים כאן הוא ההנחה היחידה
     * שלא נראית על המסך — והיא זו שהופכת הפסד לרווח.
     */
    public function test_a_foreign_currency_cost_is_shown_but_no_margin_is_computed(): void
    {
        $this->fakeMeta('USD');
        app(MessagingCostReport::class)->refresh();

        $this->settle($this->billedCharge(count: 2000, netAgorot: 4000), 2000);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertFalse($summary['comparable']);
        $this->assertNull($summary['margin']);
        // הסכום עצמו כן מוצג — הוא פשוט לא בשקלים.
        $this->assertSame(1281, $summary['cost']['total']);
        $this->assertNull(app(MessagingCostReport::class)->costPerMessage());
    }

    /*
    | ----------------------------------------------------------------
    | ההכנסה, ומה שלא חויב
    | ----------------------------------------------------------------
    */

    public function test_the_margin_is_revenue_before_vat_minus_what_meta_charged(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $this->settle($this->billedCharge(count: 2000, netAgorot: 2400), 2000);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(2400, $summary['revenue_net']);
        $this->assertSame(2000, $summary['billed_messages']);
        $this->assertSame(2400 - 1281, $summary['margin']);
    }

    /**
     * חיוב שנכשל אינו הכנסה.
     *
     * לספור אותו היה הופך בעיית גבייה למרווח.
     */
    public function test_a_failed_charge_is_not_revenue(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $this->settle($this->billedCharge(count: 2000, netAgorot: 2400, overrides: ['status' => ChargeStatus::Failed]), 2000);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(0, $summary['revenue_net']);
        $this->assertSame(0, $summary['billed_messages']);
    }

    /** וחיוב מלפני שהנטו נשמר על השורה נספר כמשוער, ולא מנוחש. */
    public function test_a_charge_without_the_net_on_its_line_is_reported_as_estimated(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $charge = $this->billedCharge(count: 500, netAgorot: 600);
        $lines = $charge->lines;
        unset($lines[1]['net_agorot']);
        $charge->update(['lines' => $lines]);
        $this->settle($charge, 500);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(1, $summary['estimated_rows']);
        // לא נוחש מהברוטו: הסכום לא נכנס להכנסה.
        $this->assertSame(0, $summary['revenue_net']);
        $this->assertSame(500, $summary['billed_messages']);
    }

    /**
     * הודעות שאף אחד לא ישלם עליהן נספרות בנפרד.
     *
     * הודעה בתקופת ניסיון עולה בדיוק כמו הודעה מחויבת, ומרווח שמתעלם ממנה הוא
     * מרווח שמחמיא לעצמו.
     */
    public function test_messages_nobody_will_pay_for_are_counted_separately(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $customer = Customer::factory()->create();

        foreach ([true, true, false] as $i => $billable) {
            SiteAgentUsage::create([
                'customer_id' => $customer->id,
                'provider_message_id' => 'wamid-'.$i,
                'billable' => $billable,
                'sent_at' => now()->subDay(),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(3, $summary['sent_messages']);
        $this->assertSame(1, $summary['unbilled_messages']);
    }

    /** העלות הממוצעת להודעה — המספר ש"מחיר להודעה" צריך לכסות. */
    public function test_the_average_cost_per_message_is_reported(): void
    {
        $this->fakeMeta('ILS', [['pricing_category' => 'service', 'cost' => 20.00, 'volume' => 1000]]);
        app(MessagingCostReport::class)->refresh();

        // ₪20 על 1,000 הודעות = 2 אגורות להודעה.
        $this->assertSame(2, app(MessagingCostReport::class)->costPerMessage());
    }

    /** ובלי הודעות אין ממוצע — חלוקה באפס הייתה מוצגת כ"חינם". */
    public function test_no_messages_means_no_average_rather_than_zero(): void
    {
        $this->fakeMeta('ILS', [['pricing_category' => 'service', 'cost' => 0, 'volume' => 0]]);
        app(MessagingCostReport::class)->refresh();

        $this->assertNull(app(MessagingCostReport::class)->costPerMessage());
    }

    /*
    | ----------------------------------------------------------------
    | העבודה עצמה
    | ----------------------------------------------------------------
    */

    /** השליפה היא Job מתוזמן, ולא קריאה מתוך טעינת עמוד. */
    public function test_the_scheduled_job_stores_the_figures(): void
    {
        $this->fakeMeta();

        (new SyncSiteAgentMessagingCostJob)->handle(app(MessagingCostReport::class));

        $this->assertSame(1281, app(MessagingCostReport::class)->summary()['cost']['total']);
    }

    /** וכשמטא לא עונה — נרשמת שורה ביומן עם הסיבה, במקום מסך ריק בלי הסבר. */
    public function test_a_failed_pull_is_recorded_in_the_journal_with_its_reason(): void
    {
        $this->fakeGraph(['error' => ['message' => 'Invalid OAuth access token.']], 401);

        (new SyncSiteAgentMessagingCostJob)->handle(app(MessagingCostReport::class));

        $log = SystemLog::query()->where('source', 'siteagent')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Invalid OAuth', $log->message);
    }

    /** והמוצר כבוי — לא פונים למטא בכלל. */
    public function test_nothing_is_pulled_when_the_product_is_off(): void
    {
        config(['siteagent.enabled' => false]);
        Http::fake();

        (new SyncSiteAgentMessagingCostJob)->handle(app(MessagingCostReport::class));

        Http::assertNothingSent();
    }

    /*
    | ----------------------------------------------------------------
    | המסך עצמו
    | ----------------------------------------------------------------
    */

    /**
     * המסך נפתח — גם כשאין נתונים בכלל.
     *
     * זה המצב של כל התקנה ברגע הראשון, והוא גם המצב שבו הקוד נוגע בכל ענף
     * ה"לא ידוע". בדיקות על ה-service לבדן לא היו תופסות שגיאת Blade כאן.
     */
    public function test_the_screen_opens_for_an_admin_with_no_data_at_all(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(SiteAgentMessageCost::getUrl())
            ->assertOk()
            ->assertSee('אין נתוני עלות ממטא')
            ->assertSee('לא ניתן לחשב');
    }

    /** ועם נתונים — המספרים והקטגוריות על המסך. */
    public function test_the_screen_shows_the_figures_and_the_categories(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();
        $this->settle($this->billedCharge(count: 2000, netAgorot: 2400), 2000);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(SiteAgentMessageCost::getUrl())
            ->assertOk()
            ->assertSee('12.81')                       // העלות
            ->assertSee('24.00')                       // מה שחויב
            ->assertSee('11.19')                       // המרווח
            ->assertSee('שיחה (תשובות הבוט)')
            ->assertSee('אימות (קודים)');
    }

    /**
     * המסך סגור למי שאינו מנהל.
     *
     * זה המרווח הגולמי של המוצר, לא נתון תפעולי.
     */
    public function test_a_non_admin_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));

        $this->get(SiteAgentMessageCost::getUrl())->assertForbidden();
    }

    /**
     * הודעות שכלולות במנוי אינן נספרות כהודעות שחויבו.
     *
     * הן עולות לנו בדיוק כמו המחויבות, וחלוקה של ההכנסה בכל ההודעות שבחשבונית
     * הייתה מדווחת מחיר להודעה שלא גבינו מעולם.
     */
    public function test_messages_included_in_the_plan_are_not_counted_as_charged(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        // 2,000 על החשבונית, 500 מהן כלולות, ולכן 1,500 חויבו.
        $charge = $this->billedCharge(count: 2000, netAgorot: 1800);
        $lines = $charge->lines;
        $lines[1]['included'] = 500;
        $charge->update(['lines' => $lines]);
        $this->settle($charge, 2000);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(2000, $summary['billed_messages']);
        $this->assertSame(500, $summary['included_messages']);
        // ההכנסה היא מה שנגבה בפועל, ולא מחיר כפול מספר ההודעות.
        $this->assertSame(1800, $summary['revenue_net']);
    }

    /*
    | ----------------------------------------------------------------
    | החוזה מול מטא — חמש הדרכים שהמסך היה מדווח ₪0 בשקט
    | ----------------------------------------------------------------
    */

    /**
     * הנתונים נקראים מהקינון האמיתי, לא משכבה אחת מעליו.
     *
     * מטא מחזירה pricing_analytics.data[].data_points[]. קריאה של data_points
     * ישירות מהעוטף מחזירה מערך ריק — ומערך ריק נראה על המסך בדיוק כמו חודש
     * שלא נשלחה בו אף הודעה.
     */
    public function test_a_flattened_response_is_not_what_we_read(): void
    {
        // הצורה השגויה: data_points על העוטף, בלי data[].
        $this->fakeGraph([
            'currency' => 'ILS',
            'pricing_analytics' => [
                'data_points' => [['pricing_category' => 'SERVICE', 'cost' => 99.00, 'volume' => 500]],
            ],
        ]);

        // אין נקודות בקינון הנכון, ולכן אין עלות — ולא ₪0 מדווח בביטחון.
        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);
        // והכישלון הוא על הצורה עצמה: תשובה שאינה בקינון המצופה אינה "חודש שקט"
        // ואינה נשמרת כאפס. בלי האימות הזה הבדיקה עוברת גם אם הקריאה לא יצאה
        // בכלל, מסיבה שאינה קשורה.
        $this->assertStringContainsString('אינה בצורה המצופה', (string) $result['reason']);
        $this->assertNull(app(MessagingCostReport::class)->summary()['cost']);
    }

    /** וכמה סדרות — כולן נאספות, ולא רק הראשונה. */
    public function test_every_series_in_the_response_is_aggregated(): void
    {
        $this->fakeGraph([
            'currency' => 'ILS',
            'pricing_analytics' => ['data' => [
                ['data_points' => [['pricing_category' => 'SERVICE', 'cost' => 10.00, 'volume' => 1000]]],
                ['data_points' => [['pricing_category' => 'UTILITY', 'cost' => 5.00, 'volume' => 500]]],
            ]],
        ]);

        app(MessagingCostReport::class)->refresh();
        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(1500, $summary['cost']['total']);
        $this->assertSame(1500, $summary['cost']['messages']);
    }

    /**
     * הבקשה מבקשת את כל מה שהדוח קורא.
     *
     * COST לבדו אינו מחזיר volume ואינו מחזיר pricing_category, והוא גם אינו
     * נכשל — הוא פשוט מחזיר דוח של אפס הודעות בשורה אחת לא מסווגת. והמטבע הוא
     * שדה של החשבון ולא של האנליטיקס.
     */
    public function test_the_request_asks_for_both_metrics_the_category_and_the_currency(): void
    {
        $this->fakeMeta();

        app(MessagingCostReport::class)->refresh();

        Http::assertSent(function (Request $request): bool {
            $fields = urldecode((string) ($request->data()['fields'] ?? ''));

            return str_contains($fields, 'COST')
                && str_contains($fields, 'VOLUME')
                && str_contains($fields, 'PRICING_CATEGORY')
                && str_starts_with($fields, 'currency,');
        });
    }

    /**
     * עלות שמטא השאירה בחוץ אינה ₪0 — גם כשהתשובה עצמה הצליחה.
     *
     * לחשבון שמחויב דרך קו אשראי של שותף מטא עונה כרגיל ופשוט לא מחזירה COST.
     * ברירת מחדל של 0 הייתה הופכת בדיוק את המצב שהמסך הזה נבנה לשמר — "אי אפשר
     * לדעת" — לרענון מוצלח של אפס, ומוחקת את הסיבה בדרך.
     */
    public function test_a_withheld_cost_fails_instead_of_becoming_zero(): void
    {
        // תשובה תקפה, עם volume, בלי cost.
        $this->fakeMeta('ILS', [['pricing_category' => 'SERVICE', 'volume' => 5000]]);

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('קו אשראי של שותף', (string) $result['reason']);

        $summary = app(MessagingCostReport::class)->summary();
        $this->assertNull($summary['cost']);
        $this->assertNull($summary['margin']);
    }

    /**
     * כל חלון נשמר בנפרד.
     *
     * אחרת תצוגת 7 ימים מחסרת עלות של 30 יום מהכנסה של 7 — טעות פי ארבעה,
     * בכיוון שאף אחד לא בודק — ורענון של חלון אחד משנה בשקט את התקופה שכל מסך
     * אחר קורא.
     */
    public function test_each_window_keeps_its_own_cost(): void
    {
        $this->fakeMeta('ILS', [['pricing_category' => 'SERVICE', 'cost' => 30.00, 'volume' => 3000]]);
        app(MessagingCostReport::class)->refresh(30);

        $this->fakeMeta('ILS', [['pricing_category' => 'SERVICE', 'cost' => 7.00, 'volume' => 700]]);
        app(MessagingCostReport::class)->refresh(7);

        $this->assertSame(3000, app(MessagingCostReport::class)->summary(30)['cost']['total']);
        $this->assertSame(700, app(MessagingCostReport::class)->summary(7)['cost']['total']);
    }

    /** וחלון שלא נשלף אינו מציג את הנתון של חלון אחר. */
    public function test_a_window_never_pulled_shows_no_cost_rather_than_anothers(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh(30);

        $this->assertNotNull(app(MessagingCostReport::class)->summary(30)['cost']);
        $this->assertNull(app(MessagingCostReport::class)->summary(7)['cost']);
    }

    /** והשליפה המתוזמנת מכסה את כל החלונות שהמסך מציע. */
    public function test_the_scheduled_pull_covers_every_window_the_screen_offers(): void
    {
        $this->fakeMeta();

        (new SyncSiteAgentMessagingCostJob)->handle(app(MessagingCostReport::class));

        foreach (MessagingCostReport::WINDOWS as $days) {
            $this->assertNotNull(app(MessagingCostReport::class)->summary($days)['cost'],
                "לחלון {$days} אין נתון, והמסך מציע אותו.");
        }
    }

    /**
     * ההכנסה מיוחסת לפי מתי ההודעה נשלחה, לא לפי מתי יצאה החשבונית.
     *
     * הודעות מחויבות בדיעבד: חידוש שיצא הבוקר יכול לכלול חודש שלם. חיתוך לפי
     * תאריך החשבונית היה מעמיד שבוע של עלות ממטא מול חודש של הכנסה — והמרווח
     * היה יכול לצאת בסימן ההפוך, כששתי השאילתות נכונות כל אחת לעצמה.
     */
    public function test_revenue_follows_when_the_messages_were_sent(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh(7);
        app(MessagingCostReport::class)->refresh(30);

        // חשבונית שיצאה היום על 1,000 הודעות — 900 מהן נשלחו לפני שלושה שבועות,
        // ו-100 בשבוע האחרון.
        $charge = $this->billedCharge(count: 1000, netAgorot: 1000);
        $customer = Customer::factory()->create();

        foreach (range(1, 1000) as $i) {
            SiteAgentUsage::create([
                'customer_id' => $customer->id,
                'provider_message_id' => 'wamid-old-'.$i,
                'billable' => true,
                'charge_id' => $charge->id,
                'sent_at' => $i <= 900 ? now()->subDays(21) : now()->subDays(2),
            ]);
        }

        // בתצוגת 7 ימים נספרות 100 ההודעות של השבוע, ואיתן עשירית מההכנסה.
        $week = app(MessagingCostReport::class)->summary(7);
        $this->assertSame(100, $week['billed_messages']);
        $this->assertSame(100, $week['revenue_net']);

        // ובתצוגת 30 יום — כולן.
        $month = app(MessagingCostReport::class)->summary(30);
        $this->assertSame(1000, $month['billed_messages']);
        $this->assertSame(1000, $month['revenue_net']);
    }

    /** והודעות שנשלחו וטרם חויבו מדווחות בנפרד — לא כהכנסה ולא כאובדן. */
    public function test_messages_sent_but_not_yet_billed_are_reported_as_pending(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        // על מנוי שעוד אפשר לגבות בו — אחרת אלה הודעות שלא ייחויבו לעולם.
        $subscription = $this->collectable();

        foreach (range(1, 40) as $i) {
            SiteAgentUsage::create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'provider_message_id' => 'wamid-pending-'.$i,
                'billable' => true,
                'sent_at' => now()->subHours(3),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(40, $summary['pending_messages']);
        $this->assertSame(0, $summary['revenue_net']);
    }

    /*
    | ----------------------------------------------------------------
    | סבב שני: מספר אחד מתוך החשבון, נתון מעופש, ואותו חלון בשני הצדדים
    | ----------------------------------------------------------------
    */

    /**
     * העלות מסוננת למספר של הבוט, ולא של כל החשבון.
     *
     * חשבון WhatsApp Business יכול להחזיק כמה מספרים עסקיים, ובלי הסינון מטא
     * מחזירה את ההוצאה של כולם — בעוד שהיומן שלנו מכיל רק את התנועה של הבוט.
     * המרווח היה יוצא שגוי בדיוק כגודל מה שהמספרים האחרים שלחו.
     */
    public function test_the_cost_is_filtered_to_the_bots_own_number(): void
    {
        $this->fakeMeta();

        app(MessagingCostReport::class)->refresh();

        // נבדק על בקשת האנליטיקס עצמה. בלי התנאי הראשון, חיפוש המספר היה מספק
        // את assertSent לבדו והבדיקה לא הייתה בודקת דבר.
        Http::assertSent(function (Request $request): bool {
            $fields = urldecode((string) ($request->data()['fields'] ?? ''));

            return str_contains($fields, 'pricing_analytics')
                // מוזן כמזהה, ונשלח כמספר — זה מה שמטא מסננת לפיו.
                && str_contains($fields, 'phone_numbers([972501234567])');
        });
    }

    /**
     * ומספר שלא הצלחנו לזהות — לא פונים בכלל.
     *
     * "לשאול על הכול" היא התשובה היחידה שנראית כמו נתון ואינה נתון.
     */
    public function test_an_unresolvable_number_stops_the_call_rather_than_widening_it(): void
    {
        // האנליטיקס היה מחזיר נתונים אם היו פונים אליו — אבל חיפוש המספר נכשל.
        $this->fakeMeta('ILS', [['pricing_category' => 'SERVICE', 'cost' => 99.00, 'volume' => 9999]]);
        $this->numberResolves = false;

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('מספר הטלפון', (string) $result['reason']);
        $this->assertNull(app(MessagingCostReport::class)->summary()['cost']);

        // ולא נשלחה בקשת אנליטיקס אחרי הכישלון.
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            urldecode((string) ($request->data()['fields'] ?? '')), 'pricing_analytics'));
    }

    /**
     * רענון שנכשל מוצג גם כשנשאר נתון מלפני כן.
     *
     * אחרת מספר מתיישן נשאר על המסך עד שמונה ימים בלי שום סימן שכל פנייה מאז
     * נדחתה — ונתון מעופש שמוצג כעדכני גרוע מאין נתון בכלל.
     */
    public function test_a_stale_figure_is_shown_with_the_reason_it_is_not_fresher(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        // ומעתה מטא דוחה.
        $this->fakeGraph(['error' => ['message' => 'Invalid OAuth access token.']], 401);
        app(MessagingCostReport::class)->refresh();

        $summary = app(MessagingCostReport::class)->summary();

        // הנתון הקודם נשאר — ולידו הסיבה.
        $this->assertNotNull($summary['cost']);
        $this->assertNotNull($summary['cost_error']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(SiteAgentMessageCost::getUrl())
            ->assertOk()
            ->assertSee('אינם מעודכנים')
            ->assertSee('Invalid OAuth access token.')
            // ולא ההסבר על מזהה WABA חסר: ברור שהגענו למטא פעם אחת.
            ->assertDontSee('חסר <strong>מזהה WABA</strong>', false);
    }

    /**
     * שני הצדדים נמדדים על אותו חלון — זה של העלות.
     *
     * הנתון של מטא נגמר כשהשליפה רצה, לא עכשיו. חישוב מחדש "עד עכשיו" היה
     * מעמיד הכנסה של אחר הצהריים מול עלות שנעצרה לפני הבוקר.
     */
    public function test_both_sides_are_measured_over_the_cost_interval(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh(7);

        $customer = Customer::factory()->create();

        // הודעה שנשלחה אחרי שהשליפה נגמרה: העלות שלה אינה בנתון של מטא, ולכן
        // היא גם לא נספרת בצד שלנו.
        SiteAgentUsage::create([
            'customer_id' => $customer->id,
            'provider_message_id' => 'wamid-after-the-pull',
            'billable' => true,
            'sent_at' => now()->addHours(2),
        ]);

        $summary = app(MessagingCostReport::class)->summary(7);

        $this->assertSame(0, $summary['sent_messages']);
        $this->assertSame(0, $summary['pending_messages']);
    }

    /*
    | ----------------------------------------------------------------
    | סבב שלישי: דיוק אגורות, וגבול עליון פתוח
    | ----------------------------------------------------------------
    */

    /**
     * שאריות של פחות מאגורה נאספות, ולא נזרקות בכל נקודה.
     *
     * granularity יומי כפול קטגוריה מחזיר הרבה נקודות, כל אחת עשרונית. עיגול של
     * כל אחת לאגורה שלמה מאבד את השארית בכל פעם, והשאריות אינן מתקזזות — הן
     * פשוט נעלמות, מקטינות את העלות ומחמיאות למרווח.
     */
    public function test_sub_agora_residues_are_accumulated_and_not_dropped_per_point(): void
    {
        // ארבע נקודות של 0.004 ש״ח. עיגול לכל נקודה: 0+0+0+0 = 0.
        // צבירה ואז עיגול: 0.016 ש״ח = 1.6 אגורות → 2.
        $this->fakeMeta('ILS', [
            ['pricing_category' => 'SERVICE', 'cost' => 0.004, 'volume' => 1],
            ['pricing_category' => 'SERVICE', 'cost' => 0.004, 'volume' => 1],
            ['pricing_category' => 'SERVICE', 'cost' => 0.004, 'volume' => 1],
            ['pricing_category' => 'SERVICE', 'cost' => 0.004, 'volume' => 1],
        ]);

        app(MessagingCostReport::class)->refresh();
        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(2, $summary['cost']['total']);
        $this->assertSame(2, $summary['cost']['by_category']['service']['cost']);
    }

    /**
     * והטבלה מסתכמת לסכום שלה.
     *
     * עיגול הקטגוריות והסכום הכולל בנפרד מאפשר להם לא להסכים באגורה, ודוח שאינו
     * מסתכם גרוע מדוח פחות מדויק.
     */
    public function test_the_categories_add_up_to_the_total(): void
    {
        $this->fakeMeta('ILS', [
            ['pricing_category' => 'SERVICE', 'cost' => 0.005, 'volume' => 1],
            ['pricing_category' => 'UTILITY', 'cost' => 0.005, 'volume' => 1],
            ['pricing_category' => 'AUTHENTICATION', 'cost' => 0.005, 'volume' => 1],
        ]);

        app(MessagingCostReport::class)->refresh();
        $cost = app(MessagingCostReport::class)->summary()['cost'];

        $this->assertSame(
            $cost['total'],
            array_sum(array_column($cost['by_category'], 'cost')),
            'סכום הקטגוריות אינו שווה לסכום הכולל שמוצג מעליהן.',
        );
    }

    /**
     * הגבול העליון פתוח, כמו הדלי של מטא.
     *
     * ה-end שמטא עונה עליו הוא ה-start של הדלי הבא, ולכן שורה שנופלת בדיוק עליו
     * אינה בתוך העלות — והיא הייתה נספרת אצלנו עם הכנסה ובלי עלות לידה.
     */
    public function test_a_row_landing_exactly_on_the_upper_bound_is_outside_the_window(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh(7);

        $to = Carbon::parse(
            app(MessagingCostReport::class)->summary(7)['to'],
        );

        SiteAgentUsage::create([
            'customer_id' => Customer::factory()->create()->id,
            'provider_message_id' => 'wamid-on-the-boundary',
            'billable' => true,
            'sent_at' => $to,
        ]);

        $this->assertSame(0, app(MessagingCostReport::class)->summary(7)['sent_messages']);

        // ושנייה אחת לפניו — כן בתוך החלון.
        SiteAgentUsage::create([
            'customer_id' => Customer::factory()->create()->id,
            'provider_message_id' => 'wamid-just-inside',
            'billable' => true,
            'sent_at' => $to->copy()->subSecond(),
        ]);

        $this->assertSame(1, app(MessagingCostReport::class)->summary(7)['sent_messages']);
    }

    /*
    | ----------------------------------------------------------------
    | סבב רביעי: נקודת איזון אמיתית, וחודש שקט
    | ----------------------------------------------------------------
    */

    /**
     * נקודת האיזון מחולקת בהודעות שבאמת מחויבות, לא בכל מה שמטא גבתה עליו.
     *
     * קודי אימות והודעות מערכת מחויבים לנו ואינם מחויבים ללקוח — פריסת ההוצאה
     * עליהם מורידה את המספר פי כמה, מתחת לתווית שאומרת שמחיר המסלול צריך לכסות
     * אותו. זה בדיוק המספר היחיד שהמסך הזה קיים כדי לתת.
     */
    public function test_the_break_even_rate_divides_by_the_messages_that_actually_earn(): void
    {
        // ₪10 בסך הכול: 100 תשובות ו-900 קודי אימות.
        $this->fakeMeta('ILS', [
            ['pricing_category' => 'SERVICE', 'cost' => 5.00, 'volume' => 100],
            ['pricing_category' => 'AUTHENTICATION', 'cost' => 5.00, 'volume' => 900],
        ]);
        app(MessagingCostReport::class)->refresh();

        // ומהצד שלנו: 100 הודעות שחויבו.
        $this->settle($this->billedCharge(count: 100, netAgorot: 300), 100);

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(100, $summary['charged_messages']);
        // 1000 אגורות / 100 הודעות = 10 אגורות, ולא 1 (שהיה יוצא מחלוקה ב-1,000).
        $this->assertSame(10, $summary['break_even_agorot']);

        // והעלות הממוצעת של הודעה כלשהי נשארת זמינה, לתמחור מה שלא חויב.
        $this->assertSame(1, app(MessagingCostReport::class)->costPerMessage());
    }

    /** ובלי הודעות שחויבו אין נקודת איזון — חלוקה באפס אינה "חינם". */
    public function test_no_charged_messages_means_no_break_even_rather_than_zero(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $this->assertNull(app(MessagingCostReport::class)->summary()['break_even_agorot']);
    }

    /**
     * חודש שלא נשלחה בו אף הודעה נשמר כאפס, ולא כ"עלות נמנעה".
     *
     * שני המצבים מגיעים כתשובה בלי עלות בתוכה ומשמעותם הפוכה: אחד הוא חודש שקט,
     * והשני חשבון שמטא אינה מגלה את ההוצאה שלו. אבחון שגוי כאן גם מציג סיבה לא
     * נכונה וגם משאיר את הנתון הגדול מהתקופה הקודמת על המסך.
     */
    public function test_a_period_with_no_messages_is_cached_as_zero(): void
    {
        // תשובה תקפה ו"ריקה" — אין data_points בכלל.
        $this->fakeGraph(['currency' => 'ILS', 'pricing_analytics' => ['data' => []]]);

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertTrue($result['ok']);

        $summary = app(MessagingCostReport::class)->summary();
        $this->assertNotNull($summary['cost']);
        $this->assertSame(0, $summary['cost']['total']);
        $this->assertSame(0, $summary['cost']['messages']);
        $this->assertNull($summary['cost_error']);
    }

    /** וחודש שקט אחרי חודש פעיל מחליף את הנתון, ולא משאיר אותו. */
    public function test_a_quiet_period_replaces_the_previous_figure(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();
        $this->assertSame(1281, app(MessagingCostReport::class)->summary()['cost']['total']);

        $this->fakeGraph(['currency' => 'ILS', 'pricing_analytics' => ['data' => []]]);
        app(MessagingCostReport::class)->refresh();

        $this->assertSame(0, app(MessagingCostReport::class)->summary()['cost']['total']);
    }

    /*
    | ----------------------------------------------------------------
    | סבב חמישי: הודעות שטרם חויבו, וסדרה שלא מזוהה
    | ----------------------------------------------------------------
    */

    /**
     * הודעות שנשלחו וטרם חויבו נכנסות למחלק של נקודת האיזון.
     *
     * העלות שלהן כבר בתוך המונה — מטא גבתה עליהן — ולכן השארתן בחוץ מחלקת את כל
     * ההוצאה בשבריר ההודעות שייצרו אותה. חלון שכולו הודעות שטרם חויבו היה מדווח
     * שאין נקודת איזון בכלל, בעוד שהמסך אומר שהן יחויבו בחידוש הבא.
     */
    public function test_pending_messages_count_toward_the_break_even_denominator(): void
    {
        // ₪10 על 1,000 הודעות.
        $this->fakeMeta('ILS', [['pricing_category' => 'SERVICE', 'cost' => 10.00, 'volume' => 1000]]);
        app(MessagingCostReport::class)->refresh();

        $subscription = $this->collectable();

        // כולן נשלחו, אף אחת לא חויבה עדיין.
        foreach (range(1, 1000) as $i) {
            SiteAgentUsage::create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'provider_message_id' => 'wamid-unbilled-'.$i,
                'billable' => true,
                'sent_at' => now()->subHour(),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(1000, $summary['pending_messages']);
        $this->assertSame(1000, $summary['charged_messages']);
        // 1000 אגורות / 1000 הודעות = אגורה אחת, ולא null.
        $this->assertSame(1, $summary['break_even_agorot']);
    }

    /**
     * סדרה בלי data_points אינה "חודש שקט".
     *
     * אחרת היא דורסת נתון אמיתי באפס ומנקה את הסיבה בדרך — אותו ₪0 שקט, בדרך
     * צרה יותר.
     */
    public function test_a_series_without_data_points_is_refused_rather_than_read_as_zero(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();
        $this->assertSame(1281, app(MessagingCostReport::class)->summary()['cost']['total']);

        // data קיים ולא ריק, אבל הסדרה שבתוכו אינה בצורה המצופה.
        $this->fakeGraph(['currency' => 'ILS', 'pricing_analytics' => ['data' => [
            ['points' => [['pricing_category' => 'SERVICE', 'cost' => 1.00, 'volume' => 10]]],
        ]]]);

        $result = app(MessagingCostReport::class)->refresh();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('data_points', (string) $result['reason']);

        // והנתון הקודם נשאר, עם הסיבה לידו — ולא נדרס באפס.
        $summary = app(MessagingCostReport::class)->summary();
        $this->assertSame(1281, $summary['cost']['total']);
        $this->assertNotNull($summary['cost_error']);
    }

    /*
    | ----------------------------------------------------------------
    | סבב שישי: הזנב של מטא, מנוי שבוטל, והקצאה בדיוק מלא
    | ----------------------------------------------------------------
    */

    /**
     * החלון נסגר במקום שבו הנתון של מטא נגמר, לא במקום שבו ביקשנו.
     *
     * האנליטיקס של מטא מתעדכן באיחור: בקשה "עד עכשיו" חוזרת מוצלחת אבל קטועה,
     * והשעות האחרונות עדיין בלי דלי. השוואה של הכנסה עד עכשיו מול נתון כזה היא
     * הכנסה בלי העלות שלה — מרווח מנופח, והכי בולט בתצוגת 7 הימים.
     */
    public function test_the_window_closes_where_metas_data_ends(): void
    {
        $bucketEnd = now()->subHours(6)->startOfSecond();

        $this->fakeMeta('ILS', [[
            'pricing_category' => 'SERVICE',
            'cost' => 5.00,
            'volume' => 500,
            'end' => $bucketEnd->getTimestamp(),
        ]]);

        app(MessagingCostReport::class)->refresh(7);

        $summary = app(MessagingCostReport::class)->summary(7);

        $this->assertSame(
            $bucketEnd->getTimestamp(),
            Carbon::parse($summary['to'])->getTimestamp(),
            'החלון נסגר בזמן שביקשנו ולא בזמן שמטא דיווחה עליו.',
        );

        // והכנסה שנוצרה אחרי הדלי האחרון אינה נספרת, כי אין לה עלות לידה.
        SiteAgentUsage::create([
            'customer_id' => Customer::factory()->create()->id,
            'provider_message_id' => 'wamid-in-the-tail',
            'billable' => true,
            'sent_at' => now()->subHour(),
        ]);

        $this->assertSame(0, app(MessagingCostReport::class)->summary(7)['sent_messages']);
    }

    /**
     * הודעות של מנוי שבוטל אינן "ייחויבו בחידוש הבא".
     *
     * cancel() מנקה את next_charge_at ואינו מסדיר את השימוש שמאחוריו, ולכן
     * השורות נשארות billable בלי חיוב לנצח. ספירתן כנושאות מחיר מקטינה את נקודת
     * האיזון וגם מגבה אמירה על המסך שאינה נכונה — חידוש שלא יקרה.
     */
    public function test_pending_usage_of_a_canceled_subscription_is_not_counted_as_owed(): void
    {
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $live = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->addWeek(),
        ]);

        $dead = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $dead->cancel();

        foreach ([[$live, 'live'], [$dead, 'dead']] as [$subscription, $tag]) {
            SiteAgentUsage::create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'provider_message_id' => 'wamid-'.$tag,
                'billable' => true,
                'sent_at' => now()->subHour(),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        // שתיהן נשלחו...
        $this->assertSame(2, $summary['sent_messages']);
        // ...אבל רק אחת עוד יכולה להיגבות.
        $this->assertSame(1, $summary['pending_messages']);
    }

    /**
     * עלות ההודעות שלא חויבו מוקצית בדיוק מלא, ולא כמחיר מעוגל כפול כמות.
     *
     * 1,281 אגורות על 2,000 הודעות הן 0.64 אגורה להודעה. עיגול ל-1 וכפל ב-2,000
     * מדווח ₪20 במקום ₪12.81, וממוצע מתחת לחצי אגורה מדווח את העלות כאפס.
     */
    public function test_the_cost_of_unbilled_messages_is_allocated_at_full_precision(): void
    {
        // ₪12.81 על 2,000 הודעות.
        $this->fakeMeta();
        app(MessagingCostReport::class)->refresh();

        $customer = Customer::factory()->create();

        foreach (range(1, 2000) as $i) {
            SiteAgentUsage::create([
                'customer_id' => $customer->id,
                'provider_message_id' => 'wamid-free-'.$i,
                'billable' => false,
                'sent_at' => now()->subHour(),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(2000, $summary['unbilled_messages']);
        /*
         | מטא דיווחה 2,100 הודעות ב-1,281 אגורות (2,000 שיחה ו-100 אימות), ולכן
         | חלקן של 2,000 ההודעות הוא 2000 × 1281 / 2100 = 1,220.
         |
         | וזו הנקודה: מחיר מעוגל כפול כמות היה נותן 1 × 2,000 = 2,000 אגורות —
         | יותר מכל העלות של התקופה כולה.
         */
        $this->assertSame(1220, $summary['unbilled_cost_agorot']);
    }
}
