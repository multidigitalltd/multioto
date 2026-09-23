<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\SiteInstallationResource;
use App\Jobs\PruneSiteAccessJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\SiteInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * הגישה שלקוח מוסר כדי שנתקין לו — ואיך היא מפסיקה להיות אצלנו.
 *
 * השורה הזאת מחזיקה את הדבר המסוכן ביותר במסד הנתונים: דרך להיכנס לוורדפרס של
 * לקוח כמנהל. לכן כל מה שנבדק כאן הוא לא "האם היא נשמרת" אלא "האם היא נעלמת" —
 * בסיום ההתקנה, בפקיעת התוקף, ובבקשה שאיש לא סגר.
 *
 * הכיוון שכשלון בו הכי יקר: שורה שנשכחה. בקשה שהלקוח בסוף התקין לבד היא בדיוק
 * המקרה שבו איש לא ילחץ על שום כפתור, ובלי הסריקה הייתה נשארת אצלנו כניסת מנהל
 * חיה לאתר של לקוח — לתמיד.
 */
class SiteInstallationTest extends TestCase
{
    use RefreshDatabase;

    private function installation(array $attributes = []): SiteInstallation
    {
        return SiteInstallation::create(array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'domain' => 'dana-shop.co.il',
            'state' => SiteInstallation::READY,
            'access_method' => SiteInstallation::ACCESS_TEMP_LOGIN,
            'access_secret' => 'https://dana-shop.co.il/?tml=SECRETLINK',
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * סימון "הותקן" מוחק את הגישה מיד.
     *
     * זה כל הרעיון של העמודה: היא ריקה ברגע שהיא מפסיקה להיות נחוצה.
     */
    public function test_marking_it_installed_wipes_the_access(): void
    {
        $this->actingAs($this->admin());
        $installation = $this->installation();

        Livewire::test(SiteInstallationResource\Pages\ListSiteInstallations::class)
            ->callTableAction('installed', $installation);

        $installation->refresh();
        $this->assertSame(SiteInstallation::INSTALLED, $installation->state);
        $this->assertFalse($installation->hasAccess());
        // Said, not merely absent: "נמחקה" and "מעולם לא נמסרה" are different
        // facts and the screen has to be able to tell them apart.
        $this->assertNotNull($installation->access_cleared_at);
    }

    /** וכך גם סגירה מהטופס, ולא רק מהכפתור שבטבלה. */
    public function test_closing_it_from_the_edit_form_wipes_the_access_too(): void
    {
        $this->actingAs($this->admin());
        $installation = $this->installation();

        Livewire::test(SiteInstallationResource\Pages\EditSiteInstallation::class, ['record' => $installation->id])
            ->fillForm(['state' => SiteInstallation::CANCELED])
            ->call('save');

        $this->assertFalse($installation->fresh()->hasAccess());
    }

    /**
     * צפייה בפרטים נרשמת — בלי להעתיק את הפרטים ליומן.
     *
     * יומן ביקורת שמעתיק את הסוד הוא מקום שני, קבוע, שבו הוא חי.
     */
    public function test_revealing_the_access_is_recorded_without_copying_it(): void
    {
        $this->actingAs($this->admin());
        $installation = $this->installation();

        Livewire::test(SiteInstallationResource\Pages\ListSiteInstallations::class)
            ->callTableAction('reveal', $installation);

        $log = AuditLog::query()->where('event', 'access_revealed')->sole();
        $this->assertStringContainsString('dana-shop.co.il', (string) $log->description);
        $this->assertStringNotContainsString('SECRETLINK', json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    /** הסוד אינו מופיע בטבלה עצמה — רק העובדה שהוא כאן. */
    public function test_the_table_says_whether_access_is_here_and_never_what_it_is(): void
    {
        $this->actingAs($this->admin());
        $this->installation();

        Livewire::test(SiteInstallationResource\Pages\ListSiteInstallations::class)
            ->assertSee('התקבלה')
            ->assertDontSee('SECRETLINK');
    }

    /*
    | ----------------------------------------------------------------
    | הסריקה — הבקשות שאיש לא סגר
    | ----------------------------------------------------------------
    */

    /** גישה שפג תוקפה נמחקת מעצמה. */
    public function test_expired_access_is_swept_away(): void
    {
        $installation = $this->installation(['access_expires_at' => now()->subHour()]);

        (new PruneSiteAccessJob)->handle();

        $this->assertFalse($installation->fresh()->hasAccess());
    }

    /**
     * וגם בקשה בלי תוקף מוצהר, שאיש לא נגע בה שבועות.
     *
     * "בלי תאריך" אינו "לשמור לנצח". זה המקרה של הלקוח שבסוף התקין לבד ולא אמר,
     * והוא המקרה היחיד שבו הגישה באמת נשארת אצלנו בלי שאיש יבחין.
     */
    public function test_a_forgotten_handover_with_no_stated_expiry_is_swept_too(): void
    {
        $installation = $this->installation(['access_expires_at' => null]);
        // Untouched since — the tell that nobody is working on it.
        $installation->forceFill(['updated_at' => now()->subDays(PruneSiteAccessJob::FALLBACK_DAYS + 1)])->saveQuietly();

        (new PruneSiteAccessJob)->handle();

        $this->assertFalse($installation->fresh()->hasAccess());
    }

    /**
     * אבל בקשה טרייה שממתינה להתקנה אינה נמחקת מתחת לידיים של מי שעובד עליה.
     *
     * הכיוון הזה עולה הודעה אחת ללקוח; הכיוון ההפוך עולה ללקוח את האתר שלו.
     */
    public function test_a_live_handover_is_left_alone(): void
    {
        $installation = $this->installation(['access_expires_at' => now()->addDays(3)]);

        (new PruneSiteAccessJob)->handle();

        $this->assertTrue($installation->fresh()->hasAccess());
    }

    /** מי שאין לו מודול הניהול אינו מגיע למסך בכלל. */
    public function test_the_queue_stays_behind_its_module(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['support'],
        ]));

        $this->assertFalse(SiteInstallationResource::canAccess());
    }
}
