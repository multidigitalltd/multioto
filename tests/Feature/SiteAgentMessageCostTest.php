<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SiteAgentMessageCost;
use App\Jobs\SyncSiteAgentMessagingCostJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\SiteAgentUsage;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\SiteAgent\MessagingCostReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    /** @var array<string, mixed> The body the faked Graph API currently returns. */
    private array $metaBody = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.phone_number_id' => '1234',
            'siteagent.whatsapp.token' => 'wa-token',
            'siteagent.whatsapp.waba_id' => '1079443834447382',
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
        $body = [
            'currency' => $currency,
            'pricing_analytics' => [
                'data' => [
                    ['data_points' => $points ?? [
                        ['pricing_category' => 'SERVICE', 'cost' => 12.20, 'volume' => 2000],
                        ['pricing_category' => 'AUTHENTICATION', 'cost' => 0.61, 'volume' => 100],
                    ]],
                ],
            ],
        ];

        /*
         | Held on the test and served through a closure, so calling this again
         | REPLACES the response. Registering a second '*' stub would only append
         | one, and Laravel answers with the first that matches — so the earlier
         | body would keep winning and a test that changes the response would be
         | asserting against the old one.
         */
        $this->metaBody = $body;

        Http::fake(['*' => fn () => Http::response($this->metaBody)]);
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
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unsupported get request.']], 400)]);

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
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401)]);

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
        Http::fake(['*' => Http::response([
            'currency' => 'ILS',
            'pricing_analytics' => [
                'data_points' => [['pricing_category' => 'SERVICE', 'cost' => 99.00, 'volume' => 500]],
            ],
        ])]);

        // אין נקודות בקינון הנכון, ולכן אין עלות — ולא ₪0 מדווח בביטחון.
        $this->assertFalse(app(MessagingCostReport::class)->refresh()['ok']);
        $this->assertNull(app(MessagingCostReport::class)->summary()['cost']);
    }

    /** וכמה סדרות — כולן נאספות, ולא רק הראשונה. */
    public function test_every_series_in_the_response_is_aggregated(): void
    {
        Http::fake(['*' => Http::response([
            'currency' => 'ILS',
            'pricing_analytics' => ['data' => [
                ['data_points' => [['pricing_category' => 'SERVICE', 'cost' => 10.00, 'volume' => 1000]]],
                ['data_points' => [['pricing_category' => 'UTILITY', 'cost' => 5.00, 'volume' => 500]]],
            ]],
        ])]);

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

        $customer = Customer::factory()->create();

        foreach (range(1, 40) as $i) {
            SiteAgentUsage::create([
                'customer_id' => $customer->id,
                'provider_message_id' => 'wamid-pending-'.$i,
                'billable' => true,
                'sent_at' => now()->subHours(3),
            ]);
        }

        $summary = app(MessagingCostReport::class)->summary();

        $this->assertSame(40, $summary['pending_messages']);
        $this->assertSame(0, $summary['revenue_net']);
    }
}
