<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\NotificationType;
use App\Filament\Pages\BroadcastStats;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Support\BroadcastAudience;
use App\Services\Support\MarketingEngagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * מדדי הדיוור: מי פותח, מתי, ולמי מפסיקים לשלוח.
 *
 * הסכנה בפיצ'ר הזה אינה בחישוב אלא בהסקה: כל המספרים כאן מגיעים מדיווחי ספק
 * המייל, ובהתקנה שבה המעקב לא הוגדר אף אחד לא "נפתח" — כלומר כל הלקוחות
 * ייראו כמי שאינם קוראים, והמערכת תפסיק לדוור לכולם בשקט. רוב הבדיקות כאן הן
 * על הגבול הזה: היעדר נתונים אינו נתון.
 */
class MarketingEngagementTest extends TestCase
{
    use RefreshDatabase;

    private function engagement(): MarketingEngagement
    {
        return app(MarketingEngagement::class);
    }

    /** שורת דיוור אחת ללקוח, עם/בלי מסירה ופתיחה. */
    private function log(?Customer $customer, ?Carbon $openedAt = null, bool $delivered = true, ?Carbon $sentAt = null): NotificationLog
    {
        return NotificationLog::create([
            'customer_id' => $customer?->id,
            'channel' => 'email',
            'type' => NotificationType::Broadcast,
            'recipient' => $customer?->email ?? 'x@b.co.il',
            'subject' => 'דיוור',
            'status' => 'sent',
            'sent_at' => $sentAt ?? now()->subDays(3),
            'delivered_at' => $delivered ? ($sentAt ?? now()->subDays(3)) : null,
            'opened_at' => $openedAt,
        ]);
    }

    /*
    | ----------------------------------------------------------------
    | שעת הפתיחה
    | ----------------------------------------------------------------
    */

    /** הפתיחות נספרות לפי השעה שבה נפתחו. */
    public function test_opens_are_counted_by_the_hour_they_happened(): void
    {
        $customer = Customer::factory()->create();

        $this->log($customer, now()->subDays(2)->setTime(9, 15));
        $this->log($customer, now()->subDays(2)->setTime(9, 50));
        $this->log($customer, now()->subDays(1)->setTime(21, 5));

        $byHour = $this->engagement()->opensByHour();

        $this->assertSame(2, $byHour[9]);
        $this->assertSame(1, $byHour[21]);
        $this->assertSame(0, $byHour[3]);
        // כל השעות נוכחות — שעה בלי פתיחות היא מידע, לא שורה חסרה.
        $this->assertCount(24, $byHour);
    }

    /**
     * המלצת שעה ניתנת רק מעל סף פתיחות.
     *
     * שלוש פתיחות באותה שעה אינן "השעה שבה הלקוחות שלנו קוראים", והמלצה
     * שנשענת עליהן גרועה מלא להמליץ.
     */
    public function test_no_hour_is_recommended_from_a_handful_of_opens(): void
    {
        config(['billing.broadcasts.engagement.min_opens_for_advice' => 10]);

        $customer = Customer::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->log($customer, now()->subDays(2)->setTime(9, $i));
        }

        $this->assertNull($this->engagement()->bestWindow());
    }

    /** ומעל הסף — מתקבל חלון השעתיים שבו נפתח הכי הרבה. */
    public function test_the_busiest_two_hour_window_is_recommended(): void
    {
        config(['billing.broadcasts.engagement.min_opens_for_advice' => 5]);

        $customer = Customer::factory()->create();

        foreach (range(1, 6) as $i) {
            $this->log($customer, now()->subDays(2)->setTime(9, $i));
        }
        foreach (range(1, 4) as $i) {
            $this->log($customer, now()->subDays(2)->setTime(10, $i));
        }
        $this->log($customer, now()->subDays(2)->setTime(2, 0));

        $best = $this->engagement()->bestWindow();

        $this->assertSame(9, $best['from']);
        $this->assertSame(11, $best['to']);
        $this->assertSame(10, $best['opens']);
    }

    /*
    | ----------------------------------------------------------------
    | מי שאינו פותח
    | ----------------------------------------------------------------
    */

    /** מי שקיבל מספיק הודעות ולא פתח אף אחת — מזוהה. */
    public function test_a_customer_who_never_opens_is_identified(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 3]);

        $reader = Customer::factory()->create(['email' => 'reads@b.co.il']);
        $silent = Customer::factory()->create(['email' => 'never@b.co.il']);

        // צריך להיות לפחות אירוע פתיחה אחד במערכת, אחרת אין מדידה בכלל.
        $this->log($reader, now()->subDays(2)->setTime(9, 0));
        $this->log($reader);
        $this->log($reader);

        foreach (range(1, 3) as $i) {
            $this->log($silent);
        }

        $this->assertSame([$silent->id], $this->engagement()->nonOpenerIds());
    }

    /** מתחת לסף — לא מסיקים דבר. */
    public function test_too_few_messages_prove_nothing(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 5]);

        $reader = Customer::factory()->create();
        $this->log($reader, now()->subDays(2)->setTime(9, 0));

        $quiet = Customer::factory()->create();
        $this->log($quiet);
        $this->log($quiet);

        $this->assertSame([], $this->engagement()->nonOpenerIds());
    }

    /**
     * הודעה שלא נמסרה אינה ראיה לכך שהנמען אינו קורא.
     */
    public function test_undelivered_messages_do_not_count_against_a_customer(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 3]);

        $reader = Customer::factory()->create();
        $this->log($reader, now()->subDays(2)->setTime(9, 0));

        $unreached = Customer::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->log($unreached, delivered: false);
        }

        $this->assertSame([], $this->engagement()->nonOpenerIds());
    }

    /**
     * בלי שום נתוני פתיחה — איש אינו מסומן, ואיש אינו מדולג.
     *
     * זו הבדיקה החשובה בקובץ. בהתקנה שבה Webhook הפתיחות לא הוגדר, הקריאה
     * התמימה של הנתונים היא "אף לקוח אינו פותח" — והתוצאה היא מערכת שמפסיקה
     * לדוור לכולם בלי לומר מילה.
     */
    public function test_without_any_open_tracking_nobody_is_written_off(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 2]);

        $customer = Customer::factory()->create(['email' => 'someone@b.co.il']);
        foreach (range(1, 10) as $i) {
            $this->log($customer);
        }

        $this->assertFalse($this->engagement()->hasOpenData());
        $this->assertSame([], $this->engagement()->nonOpenerIds());
        $this->assertFalse($this->engagement()->skipsNonOpeners());

        // ולכן הוא עדיין נמצא בקהל של דיוור פרסומי.
        $reachable = app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, ['status' => 'all'], marketing: true)
            ->pluck('id');

        $this->assertContains($customer->id, $reachable->all());
    }

    /*
    | ----------------------------------------------------------------
    | הקהל בפועל
    | ----------------------------------------------------------------
    */

    /** בדיוור פרסומי — מי שאינו פותח מדולג, ונספר בנפרד. */
    public function test_a_marketing_send_skips_customers_who_never_open(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 2]);

        [$reader, $silent] = $this->readerAndSilent();

        $audience = app(BroadcastAudience::class);
        $reachable = $audience->reachable(BroadcastChannel::Email, ['status' => 'all'], marketing: true)->pluck('id');

        $this->assertContains($reader->id, $reachable->all());
        $this->assertNotContains($silent->id, $reachable->all());

        $summary = $audience->summary(BroadcastChannel::Email, ['status' => 'all'], marketing: true);
        $this->assertSame(1, $summary['never_opens']);
    }

    /**
     * הודעת שירות מגיעה לכולם.
     *
     * תחזוקה מתוכננת או עדכון אבטחה אינם פרסום, ולקוח שאינו פותח דיוורים עדיין
     * צריך לדעת שהאתר שלו יורד מחר בלילה.
     */
    public function test_a_service_announcement_still_reaches_everyone(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 2]);

        [, $silent] = $this->readerAndSilent();

        $reachable = app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, ['status' => 'all'], marketing: false)
            ->pluck('id');

        $this->assertContains($silent->id, $reachable->all());
    }

    /** אפשר לכלול אותם בכל זאת, לדיוור מסוים. */
    public function test_the_operator_can_include_them_for_one_broadcast(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 2]);

        [, $silent] = $this->readerAndSilent();

        $reachable = app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, ['status' => 'all', 'include_non_openers' => true], marketing: true)
            ->pluck('id');

        $this->assertContains($silent->id, $reachable->all());
    }

    /** וכשהכלל כבוי בהגדרות — אין דילוג. */
    public function test_nothing_is_skipped_when_the_rule_is_switched_off(): void
    {
        config([
            'billing.broadcasts.engagement.skip_non_openers.enabled' => false,
            'billing.broadcasts.engagement.skip_non_openers.min_sent' => 2,
        ]);

        [, $silent] = $this->readerAndSilent();

        $reachable = app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, ['status' => 'all'], marketing: true)
            ->pluck('id');

        $this->assertContains($silent->id, $reachable->all());
    }

    /** בוואטסאפ אין מדד פתיחה, ולכן אין על מה לבסס דילוג. */
    public function test_whatsapp_is_never_filtered_by_opens(): void
    {
        config(['billing.broadcasts.engagement.skip_non_openers.min_sent' => 2]);

        [, $silent] = $this->readerAndSilent();
        $silent->update(['phone' => '+972501234567']);

        $reachable = app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Whatsapp, ['status' => 'all'], marketing: true)
            ->pluck('id');

        $this->assertContains($silent->id, $reachable->all());
    }

    /*
    | ----------------------------------------------------------------
    | המסך
    | ----------------------------------------------------------------
    */

    /** המסך נטען ומציג את שעת השיא. */
    public function test_the_stats_screen_shows_the_recommended_window(): void
    {
        config(['billing.broadcasts.engagement.min_opens_for_advice' => 3]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        foreach (range(1, 4) as $i) {
            $this->log($customer, now()->subDays(2)->setTime(9, $i));
        }

        $this->get(BroadcastStats::getUrl())
            ->assertOk()
            ->assertSee('09:00');
    }

    /** ובלי נתונים — אומר שאין מדידה, ולא מציג אפסים כאילו הם ממצא. */
    public function test_the_stats_screen_says_when_nothing_is_measured(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(BroadcastStats::getUrl())
            ->assertOk()
            ->assertSee('אין עדיין נתוני פתיחה');
    }

    /**
     * מדידה שעבדה פעם ונשברה — האבחון עדיין מוצג.
     *
     * "האם אי פעם נרשמה פתיחה" הוא זיכרון היסטורי, והמספרים על המסך הם חלון.
     * פתיחה אחת ישנה הייתה משתיקה את האבחון לתמיד — בדיוק כשהוא נחוץ, אחרי
     * שהמדידה הפסיקה לעבוד.
     */
    public function test_the_diagnosis_still_appears_after_tracking_breaks(): void
    {
        $this->actingAs(User::factory()->create());

        // פתיחה מלפני שנה: היסטורית קיימת, אך מחוץ לחלון שהמסך מציג.
        $old = now()->subDays(400);
        $this->log(Customer::factory()->create(), openedAt: $old, sentAt: $old);

        $this->get(BroadcastStats::getUrl())
            ->assertOk()
            ->assertSee('אבחון: איפה זה נעצר');
    }

    /**
     * לקוח שקורא ולקוח ששותק — עם אירוע פתיחה אחד לפחות במערכת, כדי שהמדידה
     * תהיה קיימת.
     *
     * @return array{0: Customer, 1: Customer}
     */
    private function readerAndSilent(): array
    {
        $reader = Customer::factory()->create(['email' => 'reads@b.co.il']);
        $silent = Customer::factory()->create(['email' => 'never@b.co.il']);

        $this->log($reader, now()->subDays(2)->setTime(9, 0));
        $this->log($reader);

        $this->log($silent);
        $this->log($silent);

        return [$reader, $silent];
    }
}
