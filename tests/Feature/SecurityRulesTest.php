<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\SecurityPosture;
use App\Models\SecurityRule;
use App\Models\User;
use App\Services\Security\ThreatQuarantine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * כללי מעקב שהצוות מוסיף מהפאנל.
 *
 * שני השמות שנולדו עם המערכת נמצאו בדרך הקשה ומקובעים בקוד. כל מה שהצוות לומד
 * אחר כך — התוסף שצץ אצל שני לקוחות בחודש שעבר, שם המשתמש שתוקף אוהב — לא היה
 * לו לאן ללכת חוץ מקובץ הגדרות וגרסה, ובפועל זה אומר שהוא לא נרשם בכלל.
 *
 * הקו שאסור לחצות: מחיקה אוטומטית נקבעת בתוסף שבאתר, מרשימה מקובעת שם, כדי
 * ששום דבר שנשלח ברשת לא יוכל להרחיב את מה שאתר מוחק. כלל שנוסף כאן מוצא
 * ומדווח — ולא מוחק.
 */
class SecurityRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function save(array $rules): void
    {
        Livewire::test(SecurityPosture::class)
            ->callAction('manageRules', data: ['rules' => $rules])
            ->assertHasNoActionErrors();
    }

    public function test_a_rule_added_in_the_panel_is_watched_for(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'נמצא אצל שני לקוחות', 'enabled' => true],
        ]);

        $this->assertContains('wp-shell-kit', ThreatQuarantine::plugins());
        // The built-ins are never replaced by what the team adds.
        $this->assertContains('wp-file-manager', ThreatQuarantine::plugins());
    }

    /**
     * הקו שאסור לחצות, בבדיקה.
     *
     * הרשימה שמפעילה מחיקה אוטומטית היא זו שבתוסף, והיא נקראת כאן מהקונפיג.
     * אם כלל שנוסף בפאנל היה נכנס אליה, פאנל שנפרץ היה יכול להורות למחוק את
     * חשבונות המנהל של כל הלקוחות.
     */
    public function test_a_panel_rule_never_widens_what_a_site_deletes_by_itself(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::USER, 'value' => 'admin', 'note' => 'בדיקה', 'enabled' => true],
        ]);

        $this->assertNotContains('admin', ThreatQuarantine::builtInUsers());
        $this->assertSame(['sys_maint'], ThreatQuarantine::builtInUsers());
    }

    /** מסך הכללים אומר במפורש שכלל שנוסף אינו נמחק אוטומטית. */
    public function test_the_screen_says_a_added_rule_is_reported_not_deleted(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);

        $own = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'תוספים שהוספתם למעקב');

        $this->assertNotNull($own);
        $this->assertStringContainsString('אינם נמחקים אוטומטית', $own['detail']);
    }

    /**
     * הכרטיס שמבטיח מחיקה אוטומטית מונה רק את המובנים.
     *
     * זו הטעות שהכי קל לעשות כאן: לצרף את הכללים של הצוות לרשימה שכתוב מעליה
     * "נמחקים בלי לבקש אישור". צוות שיקרא את זה יוסיף כלל ויפסיק לחפש.
     */
    public function test_the_automatic_deletion_card_lists_only_the_built_ins(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::USER, 'value' => 'intruder']);

        $card = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'משתמשים שנמחקים מיד עם הופעתם');

        $this->assertStringContainsString('sys_maint', $card['detail']);
        $this->assertStringNotContainsString('intruder', $card['detail']);
    }

    /**
     * ערך נשמר מנורמל.
     *
     * קוראי המלאי מחזירים שמות באותיות קטנות, ולכן כלל שנשמר כ-"WP-File-Manager"
     * פשוט לא היה תואם לכלום — כלל שנראה קיים במסך ולא קיים בפועל.
     */
    public function test_a_value_is_stored_the_way_the_matcher_reads_it(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => '  WP-Shell-Kit  ', 'note' => null, 'enabled' => true],
        ]);

        $this->assertSame('wp-shell-kit', SecurityRule::firstOrFail()->value);
        $this->assertContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /** כלל מושהה אינו נבדק. */
    public function test_a_disabled_rule_is_not_watched_for(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'enabled' => false]);

        $this->assertNotContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /** כלל שהוסר מהטופס נמחק. */
    public function test_removing_a_rule_from_the_form_deletes_it(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);

        $this->save([]);

        $this->assertSame(0, SecurityRule::count());
        $this->assertNotContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /**
     * אותו שם פעמיים הוא עריכה, לא שורה שנייה.
     *
     * על העמודות יש אינדקס ייחודי, והוספה עיוורת הייתה מפילה את כל השמירה על
     * שגיאת בסיס נתונים — כלומר גם את שאר הכללים שבאותו טופס.
     */
    public function test_the_same_name_twice_is_an_edit_and_never_breaks_the_save(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'ישן']);

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'חדש', 'enabled' => true],
            ['type' => SecurityRule::USER, 'value' => 'intruder', 'note' => null, 'enabled' => true],
        ]);

        $this->assertSame(2, SecurityRule::count());
        $this->assertSame('חדש', SecurityRule::where('value', 'wp-shell-kit')->firstOrFail()->note);
    }

    /**
     * שורה ריקה נדחית בטופס, ולא נבלעת בשקט.
     *
     * הבליעה השקטה הייתה גרועה יותר: מי שהקליד שם ולחץ שמירה היה מקבל "נשמר"
     * על כלל שלא נשמר, ומפסיק לחפש. הטיפול בצד השרת נשאר כשכבה שנייה למקרה
     * ששורה כזאת בכל זאת עוברת.
     */
    public function test_a_blank_row_is_refused_rather_than_silently_dropped(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SecurityPosture::class)
            ->callAction('manageRules', data: [
                'rules' => [['type' => SecurityRule::USER, 'value' => '   ', 'note' => null, 'enabled' => true]],
            ])
            ->assertHasActionErrors();

        $this->assertSame(0, SecurityRule::count());
    }

    /** מי שאין לו מודול הניהול אינו מגיע למסך בכלל. */
    public function test_the_screen_stays_behind_its_module(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['support'],
        ]));

        $this->assertFalse(SecurityPosture::canAccess());
    }

    /**
     * טבלה שעדיין לא קיימת אינה עוצרת את הסריקה.
     *
     * קוד שנפרס לפני המיגרציה שלו הוא מצב רגיל בדקה שאחרי פריסה — ובדיוק אז
     * אסור שסריקת האבטחה תיפול ותדווח על כל האתרים כנקיים.
     */
    public function test_a_missing_table_leaves_the_built_ins_working(): void
    {
        Schema::drop('security_rules');

        $this->assertSame(['sys_maint'], ThreatQuarantine::users());
        $this->assertSame(['wp-file-manager'], ThreatQuarantine::plugins());
    }
}
