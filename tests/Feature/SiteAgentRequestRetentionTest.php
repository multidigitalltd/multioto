<?php

namespace Tests\Feature;

use App\Jobs\PruneSiteAgentRequestsJob;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * כמה זמן נשמר מה שכתבתם לסוכן.
 *
 * עד עכשיו התמונה טופלה והמילים לא: הטקסט של כל הוראה שלקוח שלח אי פעם בוואטסאפ
 * — מה ביקש, מה הוצע לו בחזרה, ותצוגה מקדימה של התוכן שלו — נשאר בטבלה לתמיד.
 * מדיניות פרטיות אינה יכולה להבטיח חלון ששום דבר אינו אוכף, ולכן הבדיקות כאן הן
 * מה שהופך את המשפט שבמסמך לנכון.
 */
class SiteAgentRequestRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $attributes = []): SiteAgentRequest
    {
        $site = Site::factory()->create();
        $subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ]);

        return SiteAgentRequest::create(array_merge([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'customer_id' => $site->customer_id,
            'message' => 'תעדכן את שעות הפתיחה',
            'state' => SiteAgentRequest::APPLIED,
        ], $attributes));
    }

    /** בקשה ישנה מהחלון נמחקת — טקסט וכל. */
    public function test_a_request_older_than_the_window_is_deleted(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 180]);

        $old = $this->request();
        $old->forceFill(['created_at' => now()->subDays(181)])->save();

        PruneSiteAgentRequestsJob::dispatchSync();

        $this->assertDatabaseMissing('site_agent_requests', ['id' => $old->id]);
    }

    /** ובקשה בתוך החלון נשארת. */
    public function test_a_request_inside_the_window_is_kept(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 180]);

        $recent = $this->request();
        $recent->forceFill(['created_at' => now()->subDays(179)])->save();

        PruneSiteAgentRequestsJob::dispatchSync();

        $this->assertDatabaseHas('site_agent_requests', ['id' => $recent->id]);
    }

    /**
     * המחיקה היא לפי גיל ולא לפי מצב.
     *
     * שורה שנשארה "ממתינה" חצי שנה אינה שאלה פתוחה שמישהו עדיין מחכה לתשובה
     * עליה — היא שורה שמעולם לא נסגרה. גיל הוא המבחן הכן, והוא זה שמוצהר
     * במדיניות הפרטיות.
     */
    public function test_an_ancient_awaiting_row_is_not_kept_forever(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 180]);

        $stuck = $this->request(['state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->subDays(200)]);
        $stuck->forceFill(['created_at' => now()->subDays(200)])->save();

        PruneSiteAgentRequestsJob::dispatchSync();

        $this->assertDatabaseMissing('site_agent_requests', ['id' => $stuck->id]);
    }

    /** קובץ שנשאר על הדיסק נמחק יחד עם השורה שמחזיקה את שמו. */
    public function test_a_lingering_image_goes_with_the_row(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('site-agent/old.jpg', 'x');
        config(['billing.system.site_agent_request_retention_days' => 180]);

        $old = $this->request(['plan' => ['image_path' => 'site-agent/old.jpg']]);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        PruneSiteAgentRequestsJob::dispatchSync();

        Storage::disk('local')->assertMissing('site-agent/old.jpg');
    }

    /** 0 מכבה את המחיקה במפורש, ולא מוחק הכול בטעות. */
    public function test_a_zero_window_keeps_everything(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 0]);

        $ancient = $this->request();
        $ancient->forceFill(['created_at' => now()->subYears(5)])->save();

        PruneSiteAgentRequestsJob::dispatchSync();

        $this->assertDatabaseHas('site_agent_requests', ['id' => $ancient->id]);
    }

    /** והסגירה השעתית של הצעות שלא נענו ממשיכה לעבוד כרגיל. */
    public function test_an_unanswered_offer_still_expires_and_drops_its_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('site-agent/pending.jpg', 'x');

        $pending = $this->request([
            'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->subMinute(),
            'plan' => ['image_path' => 'site-agent/pending.jpg'],
        ]);

        PruneSiteAgentRequestsJob::dispatchSync();

        $this->assertSame(SiteAgentRequest::EXPIRED, $pending->refresh()->state);
        Storage::disk('local')->assertMissing('site-agent/pending.jpg');
    }
}
