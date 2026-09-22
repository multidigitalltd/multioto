<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\TeamAuditLog;
use App\Filament\Resources\SiteResource;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Filament\Widgets\SiteAlerts;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * חיווי ממצאי האתרים בפאנל הראשי.
 *
 * ההתראות האלה — שינוי DNS, מנהל חדש, תוסף שהותקן — נשלחו תמיד במייל ובקבוצת
 * הניהול, ושם הן נבלעו. התראה שאין לה מקום שבו רואים שהיא עוד לא נבדקה היא
 * התראה שאפשר לפספס בלי שאיש ידע. הבדיקות כאן הן על ההבדל בין "לא היה ממצא"
 * ל"היה ממצא ואף אחד לא הסתכל".
 */
class SiteAlertsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function event(string $severity = 'critical', string $type = 'admin_added'): SiteEvent
    {
        $site = Site::factory()->create(['domain' => 'shop.co.il']);

        SiteEvent::record($site->id, $type, $severity, 'משתמש מנהל חדש: attacker');

        return SiteEvent::latest('id')->firstOrFail();
    }

    /**
     * מספיק ממצאים עם דומיינים ארוכים כדי שתיאור הרישום ייחתך ב-480 תווים.
     *
     * @return Collection<int, SiteEvent>
     */
    private function manyLongDomainFindings(): Collection
    {
        return collect(range(1, 12))->map(function (int $i): SiteEvent {
            $site = Site::factory()->create(['domain' => "a-very-long-customer-domain-number-{$i}.example.co.il"]);
            SiteEvent::record($site->id, 'dns', 'critical', "שינוי DNS מספר {$i}");

            return SiteEvent::latest('id')->firstOrFail();
        });
    }

    /** ממצא שלא טופל מופיע בווידג'ט. */
    public function test_an_unhandled_finding_is_listed(): void
    {
        $event = $this->event();

        Livewire::test(SiteAlerts::class)->assertCanSeeTableRecords([$event]);
        $this->assertSame(1, SiteAlerts::pendingCount());
    }

    /** משסומן כטופל — יורד מהחיווי, ונרשם מי סימן ומתי. */
    public function test_acknowledging_removes_it_from_the_indicator_and_records_who(): void
    {
        $event = $this->event();

        Livewire::test(SiteAlerts::class)
            ->callTableAction('acknowledge', $event);

        $event->refresh();
        $this->assertNotNull($event->acknowledged_at);
        $this->assertSame($this->user->id, $event->acknowledged_by);
        $this->assertSame(0, SiteAlerts::pendingCount());

        Livewire::test(SiteAlerts::class)->assertCanNotSeeTableRecords([$event]);
    }

    /**
     * עדכון תוכן שהלקוח ביקש (info) אינו ממצא לטיפול.
     *
     * חיווי שסופר גם תיעוד שגרתי מגיע למספר דו-ספרתי תוך שבוע ומפסיק להיקרא.
     */
    public function test_routine_information_is_not_counted_as_a_finding(): void
    {
        $event = $this->event(severity: 'info', type: 'content_change');

        $this->assertSame(0, SiteAlerts::pendingCount());
        Livewire::test(SiteAlerts::class)->assertCanNotSeeTableRecords([$event]);
    }

    /** אין ממצאים — הווידג'ט נשאר ואומר זאת. היעלמות היא תשובה דו-משמעית. */
    public function test_the_widget_stays_visible_when_there_is_nothing_to_show(): void
    {
        $this->assertTrue(SiteAlerts::canView());

        Livewire::test(SiteAlerts::class)->assertSee('אין ממצאים חדשים מהאתרים');
    }

    /** התג בתפריט סופר את מה שממתין, ונעלם כשאין. */
    public function test_the_navigation_badge_counts_what_is_waiting(): void
    {
        $this->assertNull(SiteResource::getNavigationBadge());

        $event = $this->event(severity: 'warning', type: 'dns');

        $this->assertSame('1', SiteResource::getNavigationBadge());

        $event->acknowledge($this->user);

        $this->assertNull(SiteResource::getNavigationBadge());
    }

    /** מחיקה של ממצא בודד מורידה אותו מהיומן עצמו, לא רק מהחיווי. */
    public function test_a_finding_can_be_deleted_outright(): void
    {
        $event = $this->event();

        Livewire::test(SiteAlerts::class)
            ->callTableAction('discard', $event);

        $this->assertDatabaseMissing('site_events', ['id' => $event->id]);
        $this->assertSame(0, SiteAlerts::pendingCount());
    }

    /**
     * מחיקה נרשמת ביומן הצוות, עם האתר וסוג הממצא.
     *
     * "טופל" משאיר את הממצא עצמו כראיה; מחיקה מוחקת אותה. בלי הרישום הזה
     * הפעולה היחידה במסך שמוחקת ראיה היא גם היחידה שלא נשאר ממנה זכר.
     */
    public function test_deleting_is_written_to_the_team_log_with_what_was_deleted(): void
    {
        $event = $this->event();

        Livewire::test(SiteAlerts::class)
            ->callTableAction('discard', $event);

        $entry = AuditLog::where('event', 'deleted')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('shop.co.il', $entry->description);
        $this->assertStringContainsString('משתמש מנהל חדש', $entry->description);
        $this->assertSame($this->user->id, $entry->user_id);
    }

    /**
     * מחיקה מרובה שומרת את הפירוט המלא, גם כשהתיאור נחתך.
     *
     * `AuditLog::record` חותך את התיאור ב-480 תווים, ובבחירה של עמוד שלם עם
     * דומיינים ארוכים זה קורה. מה שנחתך משם הוא בדיוק מה שהמחיקה השמידה, ולכן
     * הפירוט יושב בשדה מובנה שאינו נחתך.
     */
    public function test_the_full_detail_survives_even_when_the_description_is_cut(): void
    {
        $events = $this->manyLongDomainFindings();

        Livewire::test(SiteAlerts::class)
            ->callTableBulkAction('discardSelected', $events->all());

        $entry = AuditLog::where('event', 'deleted')->latest('id')->firstOrFail();

        // התיאור אכן נחתך — זו הנקודה.
        $this->assertLessThanOrEqual(480, mb_strlen($entry->description));

        // ובכל זאת כל אחד מהם נשמר, כולל האחרון שנפל מחוץ לתיאור.
        $this->assertCount(12, $entry->changes['findings']);
        $this->assertSame(
            $events->map(fn (SiteEvent $e): int => $e->id)->all(),
            array_column($entry->changes['findings'], 'id'),
        );
        $this->assertStringContainsString(
            'a-very-long-customer-domain-number-12.example.co.il',
            json_encode($entry->changes, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * מה שנשמר ניתן גם לקריאה מתוך המערכת.
     *
     * עמודת "שדות שהשתנו" ביומן מציגה שמות שדות בלבד, כלומר את המילה findings.
     * ראיה שנשמרה ואי אפשר לפתוח אותה שווה מעט מאוד — המסך הזה הוא ההבדל.
     */
    public function test_the_kept_detail_can_be_opened_from_the_team_log(): void
    {
        $this->user->forceFill(['role' => UserRole::Admin])->save();

        $events = $this->manyLongDomainFindings();

        Livewire::test(SiteAlerts::class)
            ->callTableBulkAction('discardSelected', $events->all());

        $entry = AuditLog::where('event', 'deleted')->latest('id')->firstOrFail();

        // הדומיין האחרון נפל מחוץ לתיאור החתוך, ולכן הוא הדבר היחיד שמבדיל בין
        // "נשמר" ל"נשמר וגם אפשר לקרוא".
        $lastDomain = 'a-very-long-customer-domain-number-12.example.co.il';
        $this->assertStringNotContainsString($lastDomain, $entry->description);

        Livewire::test(TeamAuditLog::class)
            ->assertTableActionExists('payload')
            ->mountTableAction('payload', $entry)
            ->assertSee($lastDomain);
    }

    /**
     * רישום שנכשל מחזיר את המחיקה.
     *
     * המחיקה מותרת כאן רק מפני שהיא מותירה תיעוד. אם התיעוד לא נכתב — מסד
     * שנפל, מטען שלא נכנס — מחיקה שבכל זאת עוברת היא בדיוק מחיקת ראיה בלי
     * תיעוד, כלומר מה שהעסקה נועדה למנוע. record() בולע כישלון כזה בכוונה,
     * ולכן המסלול הזה משתמש בווריאנט שנכשל בקול.
     */
    public function test_a_failed_log_puts_the_findings_back(): void
    {
        $event = $this->event();

        Event::listen(
            'eloquent.creating: '.AuditLog::class,
            fn () => throw new \RuntimeException('audit down'),
        );

        try {
            Livewire::test(SiteAlerts::class)->callTableAction('discard', $event);
        } catch (\Throwable) {
            // הכישלון הוא הנקודה; מה שנבדק הוא מה שנשאר אחריו.
        }

        $this->assertDatabaseHas('site_events', ['id' => $event->id]);
        $this->assertSame(1, SiteAlerts::pendingCount());
    }

    /**
     * גם רישום שבוטל בשקט מחזיר את המחיקה.
     *
     * מאזין Eloquent שמחזיר false מבטל את ההוספה בלי לזרוק, ו-create() מחזיר
     * בכל זאת את המודל. בלי בדיקה שהשורה אכן נשמרה, "נכשל בקול" מפספס בדיוק
     * את הכישלון השקט.
     */
    public function test_a_silently_cancelled_log_also_puts_the_findings_back(): void
    {
        $event = $this->event();

        Event::listen('eloquent.creating: '.AuditLog::class, fn (): bool => false);

        try {
            Livewire::test(SiteAlerts::class)->callTableAction('discard', $event);
        } catch (\Throwable) {
            // הכישלון הוא הנקודה.
        }

        $this->assertDatabaseHas('site_events', ['id' => $event->id]);
        $this->assertSame(0, AuditLog::where('event', 'deleted')->count());
    }

    /** כמה ממצאים יחד — נמחקים בפעולה אחת, ונרשמים כאחת. */
    public function test_several_findings_can_be_deleted_together(): void
    {
        $first = $this->event();
        $second = $this->event(severity: 'warning', type: 'dns');

        Livewire::test(SiteAlerts::class)
            ->callTableBulkAction('discardSelected', [$first, $second]);

        $this->assertDatabaseMissing('site_events', ['id' => $first->id]);
        $this->assertDatabaseMissing('site_events', ['id' => $second->id]);
        $this->assertSame(0, SiteAlerts::pendingCount());
        $this->assertSame(1, AuditLog::where('event', 'deleted')->count());
    }

    /**
     * ממצא שכבר נמחק בין הבחירה לאישור אינו נספר.
     *
     * ההודעה ללקוח אומרת כמה נמחקו, והמספר הזה חייב להיות מה שבאמת ירד —
     * לא כמה היו מסומנים על המסך.
     */
    public function test_the_count_reports_what_actually_went(): void
    {
        $first = $this->event();
        $second = $this->event(severity: 'warning', type: 'dns');

        $second->delete();

        Livewire::test(SiteAlerts::class)
            ->callTableBulkAction('discardSelected', [$first, $second]);

        $this->assertStringContainsString(
            'מחיקת ממצאי אתרים (1)',
            (string) AuditLog::where('event', 'deleted')->latest('id')->first()?->description,
        );
    }

    /** הממצאים מוצגים גם מעל רשימת האתרים, לא רק בדשבורד. */
    public function test_the_findings_sit_above_the_sites_list(): void
    {
        $event = $this->event();

        Livewire::test(ListSites::class)
            ->assertSeeLivewire(SiteAlerts::class);

        Livewire::test(SiteAlerts::class)->assertCanSeeTableRecords([$event]);
    }

    /** הקריטי מופיע מעל האזהרה, ולא לפי סדר הזמן בלבד. */
    public function test_critical_findings_are_listed_first(): void
    {
        $site = Site::factory()->create(['domain' => 'shop.co.il']);

        SiteEvent::record($site->id, 'dns', 'warning', 'שינוי DNS');
        $warning = SiteEvent::latest('id')->firstOrFail();

        // נרשם אחרי — כלומר ישן יותר לפי סדר הזמן, וחייב בכל זאת להיות ראשון.
        SiteEvent::record($site->id, 'admin_added', 'critical', 'מנהל חדש');
        SiteEvent::latest('id')->firstOrFail()->forceFill(['detected_at' => now()->subDay()])->save();
        $critical = SiteEvent::latest('id')->firstOrFail();

        Livewire::test(SiteAlerts::class)->assertCanSeeTableRecords([$critical, $warning], inOrder: true);
    }
}
