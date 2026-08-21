<?php

namespace Tests\Feature;

use App\Console\Commands\SystemStorageCommand;
use App\Models\Site;
use App\Models\SiteChange;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * מה נשמר, לכמה זמן, ומה מתנקה מעצמו.
 *
 * חלון שמירה קל להגדיר ובלתי אפשרי לזכור. הבדיקות כאן מוודאות שהוא באמת פועל —
 * שהניקוי הלילי מוחק את מה שעבר את החלון ולא נוגע במה שבתוכו — ושדוח האחסון
 * מציג את שני הנתונים זה לצד זה, כדי שחלון שגוי ייראה כמספר גדול ליד מספר
 * ארוך, במקום להתגלות כשדיסק מתמלא.
 */
class StorageRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** Run one named entry from the nightly schedule. */
    private function runScheduled(string $name): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->description === $name) {
                $event->run(app());

                return;
            }
        }

        $this->fail("There is no scheduled task named {$name}.");
    }

    private function change(string $summary, int $daysAgo): SiteChange
    {
        $change = SiteChange::create([
            'site_id' => Site::factory()->create()->id,
            'summary' => $summary,
            'tool' => 'wp_content_update',
        ]);

        // created_at is not fillable — set through the query builder, or every
        // row lands on "now" and a test about time windows never sees one.
        DB::table('site_changes')->where('id', $change->id)
            ->update(['created_at' => now()->subDays($daysAgo)]);

        return $change;
    }

    public function test_the_journal_keeps_recent_changes_and_drops_old_ones(): void
    {
        config(['billing.system.site_change_retention_days' => 180]);

        $kept = $this->change('אתמול', 1);
        $edge = $this->change('בדיוק על הגבול', 179);
        $gone = $this->change('לפני שנה', 400);

        $this->runScheduled('system:prune-site-changes');

        $this->assertNotNull($kept->fresh());
        $this->assertNotNull($edge->fresh(), 'A row inside the window must survive.');
        $this->assertNull($gone->fresh());
    }

    /**
     * וחלון שהוגדר מחדש בפאנל תופס באותו לילה.
     *
     * הניקויים הם סגירות של Schedule ולא ג'ובים בתור, ולכן הם אינם עוברים
     * ב-Queue::before — בלי רענון מפורש של ההגדרות, שינוי חלון היה ממתין
     * להפעלה מחדש של הקונטיינר.
     */
    public function test_a_window_changed_in_the_panel_takes_effect_the_same_night(): void
    {
        config(['billing.system.site_change_retention_days' => 7]);

        $gone = $this->change('לפני חודש', 30);

        $this->runScheduled('system:prune-site-changes');

        $this->assertNull($gone->fresh());
    }

    /**
     * לכל טבלה ברשימה יש באמת את עמודת הגיל שנרשמה לה.
     *
     * ההנחה ש"לכולן יש created_at" שגויה: monitor_checks חותמת checked_at
     * ו-failed_jobs חותמת failed_at. ההנחה הזו הפילה את הדוח כולו על הטבלה
     * הראשונה במקום להציג את שאר השלוש-עשרה — ודוח על תפוסת דיסק שנופל הוא
     * בדיוק הכלי שלא היה שם כשהיה צריך אותו.
     */
    public function test_every_tracked_table_has_the_age_column_it_claims(): void
    {
        $tracked = (new \ReflectionClass(SystemStorageCommand::class))
            ->getConstant('TRACKED');

        $checked = 0;

        foreach ($tracked as $table => [$configKey, $ageColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->assertTrue(
                Schema::hasColumn($table, $ageColumn),
                "{$table} has no column '{$ageColumn}' — the storage report would fail on it.",
            );

            $checked++;
        }

        $this->assertGreaterThan(5, $checked, 'The report should be covering the tables that accumulate.');
    }

    /** דוח האחסון מציג כל טבלה עם החלון שלה. */
    public function test_the_storage_report_shows_each_table_with_its_window(): void
    {
        $this->artisan('system:storage')
            ->expectsOutputToContain('site_changes')
            ->expectsOutputToContain('טבלאות שמצטברות')
            ->assertSuccessful();
    }

    /**
     * וטבלה בלי חלון מוצגת ככזו.
     *
     * זו השורה שכדאי לקרוא: לכל השאר יש תקרה, ולה אין — וזה צריך להיראות ולא
     * להיות מוסק מהיעדר.
     */
    public function test_a_table_with_no_window_says_so(): void
    {
        $this->artisan('system:storage')
            ->expectsOutputToContain('ללא ניקוי')
            ->assertSuccessful();
    }
}
