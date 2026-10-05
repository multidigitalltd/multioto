<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * תנאי השימוש ומדיניות הפרטיות.
 *
 * עד עכשיו עמוד הרכישה ביקש מהלקוח לסמן "קראתי ואני מאשר את תנאי השימוש
 * ומדיניות הפרטיות" — בלי קישור, ובלי שהמסמכים קיימים. זה גם חור משפטי וגם
 * בדיוק מה שהמערכת בודקת אצל הלקוחות שלנו בסריקת הציות.
 *
 * שלוש דרישות נפרדות נשענות על העמודים האלה, וכל אחת מהן חוסמת: תיבת הסימון
 * ברכישה, הדרישה של מטא לכתובת מדיניות פרטיות פומבית לפני שאפשר לפרסם
 * אפליקציה שמדברת עם לקוחות בוואטסאפ, וחוק הגנת הפרטיות.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * פומביים, בלי התחברות.
     *
     * מי שמתבקש לאשר אותם ברכישה עדיין אין לו חשבון, ומטא טוענת את הכתובת
     * בעצמה בלי להתחבר לשום דבר. עמוד שדורש התחברות הוא עמוד שאי אפשר לקרוא
     * בדיוק במצב שבו הוא נדרש.
     */
    public function test_both_documents_are_public(): void
    {
        $this->get(route('legal.privacy'))->assertOk()->assertSeeText('מדיניות פרטיות');
        $this->get(route('legal.terms'))->assertOk()->assertSeeText('תנאי שימוש');
    }

    /** הישות המשפטית מזוהה — מסמך בלי ח.פ. אינו אומר מי התחייב. */
    public function test_the_company_is_identified(): void
    {
        foreach (['legal.privacy', 'legal.terms'] as $route) {
            $this->get(route($route))
                ->assertSeeText(config('legal.company.name'))
                ->assertSeeText((string) config('legal.company.number'));
        }
    }

    /**
     * הצהרת הבינה המלאכותית — בשם, ועם זכות ההחלפה.
     *
     * העברת תוכן ההודעות של הלקוח לצד שלישי היא בדיוק מה שמדיניות פרטיות
     * קיימת כדי לומר. אם הספקים לא מופיעים בשמם, המסמך לא מצהיר על הדבר
     * המשמעותי ביותר שקורה למידע.
     */
    public function test_the_privacy_page_names_the_ai_providers(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSeeText('Anthropic')
            ->assertSeeText('Google')
            ->assertSeeText('רשאים להחליף');
    }

    /** ושפרטי האשראי אינם אצלנו — הטענה היחידה כאן שהיא גם כלל ארכיטקטורה. */
    public function test_the_privacy_page_states_that_no_card_number_is_stored(): void
    {
        $this->get(route('legal.privacy'))->assertSeeText('אינו נשמר אצלנו');
    }

    /**
     * חלונות השמירה נקראים מההגדרות שהמנגנון קורא.
     *
     * מספר שנכתב בטקסט מתיישן בשקט ברגע שמישהו משנה הגדרה — והמסמך הופך
     * להצהרה שקרית בלי שאיש יבחין.
     */
    public function test_retention_windows_follow_the_configuration(): void
    {
        config(['billing.monitoring.site_change_retention_days' => 211]);

        $this->get(route('legal.privacy'))->assertSeeText('211 ימים');
    }

    /** וכך גם חלון הביטול בתנאי השימוש. */
    public function test_the_undo_window_follows_the_configuration(): void
    {
        config(['siteagent.undo_minutes' => 120, 'siteagent.confirmation_minutes' => 45]);

        $this->get(route('legal.terms'))
            ->assertSeeText('2 שעות')
            ->assertSeeText('45 דקות');
    }

    /**
     * תיבת הסימון מקשרת למה שהיא מבקשת לאשר.
     *
     * זו הבעיה שהתחילה את כל זה: אישור חובה על מסמכים שאי אפשר היה לפתוח.
     *
     * נבדק על התבניות עצמן ולא על עמוד מורנדר, במכוון: שני עמודי הרכישה חסומים
     * מאחורי תנאים משלהם (מסלול פעיל, מוצר מוכן), וגרסה שמנסה לפתוח אותם הייתה
     * מדלגת בשקט ברגע שהתנאי אינו מתקיים — כלומר עוברת בלי לבדוק כלום, שזה
     * בדיוק סוג הבדיקה שהחור הזה שרד מתחת לו.
     */
    public function test_the_storefront_consent_links_to_the_documents(): void
    {
        foreach (['site-agent', 'plugin'] as $page) {
            $template = file_get_contents(resource_path("views/store/{$page}.blade.php"));

            $this->assertStringContainsString("route('legal.terms')", $template,
                "עמוד הרכישה {$page} מבקש אישור על תנאי השימוש בלי לקשר אליהם.");
            $this->assertStringContainsString("route('legal.privacy')", $template,
                "עמוד הרכישה {$page} מבקש אישור על מדיניות הפרטיות בלי לקשר אליה.");
        }
    }

    /** המספר נקרא במפורש כדי שאפשר יהיה לקשר לשם מכל מקום. */
    public function test_the_documents_link_to_each_other(): void
    {
        $this->get(route('legal.terms'))->assertSee(route('legal.privacy'));
        $this->get(route('legal.privacy'))->assertSee(route('legal.terms'));
    }

    /** כתובת לפניות — זכות שאין לאן לממש אותה אינה זכות. */
    public function test_a_contact_address_is_given(): void
    {
        config(['legal.contact_email' => 'privacy@example.co.il']);

        $this->get(route('legal.privacy'))->assertSeeText('privacy@example.co.il');
    }
}
