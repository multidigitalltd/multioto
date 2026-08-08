<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource;
use App\Filament\Widgets\SiteAlerts;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
