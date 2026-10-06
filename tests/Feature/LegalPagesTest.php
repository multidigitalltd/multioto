<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
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
        // billing.system.* — המפתחות שעבודות הניקוי עצמן קוראות. הגרסה הראשונה
        // של הבדיקה הזאת דרסה מפתח שאינו קיים, וראתה את ברירת המחדל הקבועה
        // חוזרת במקרה: היא עברה בלי להוכיח דבר, ובדיוק הסתירה את התקלה.
        config([
            'billing.system.site_change_retention_days' => 211,
            'billing.system.monitor_check_retention_days' => 77,
        ]);

        $this->get(route('legal.privacy'))
            ->assertSeeText('211 ימים')
            ->assertSeeText('77 ימים');
    }

    /**
     * המפתחות שהמסמך קורא הם המפתחות שהניקוי קורא.
     *
     * השמירה האמיתית היא התנהגות של עבודה מתוזמנת; המסמך הוא הצהרה עליה. שני
     * המקורות חייבים להיות אותו מקור, אחרת ההצהרה מתיישנת בשקט ברגע שמישהו
     * משנה הגדרה — וזו הצהרה שקרית לרגולטור וללקוח.
     */
    public function test_the_document_reads_the_same_keys_the_pruning_reads(): void
    {
        $document = file_get_contents(resource_path('views/legal/privacy.blade.php'));
        $schedule = file_get_contents(base_path('routes/console.php'));

        foreach (['site_change_retention_days', 'site_event_retention_days', 'monitor_check_retention_days'] as $key) {
            $this->assertStringContainsString("billing.system.{$key}", $document);
            $this->assertStringContainsString("billing.system.{$key}", $schedule,
                'המסמך מצטט מפתח שהניקוי אינו קורא — אחד משניהם זז.');
        }
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
     * חלון שאינו מתחלק בשעה נאמר בדקות, ולא מעוגל כלפי מעלה.
     *
     * עיגול כאן מרחיב התחייבות חוזית: 90 דקות שמוצגות כ"שעתיים" הן הבטחה
     * שהקוד מסרב לקיים בדקה ה-91.
     */
    public function test_an_uneven_undo_window_is_not_rounded_up(): void
    {
        config(['siteagent.undo_minutes' => 90]);

        $this->get(route('legal.terms'))
            ->assertSeeText('90 דקות')
            ->assertDontSeeText('2 שעות');
    }

    /**
     * כתובת ריקה ב-.env נקראת כ"לא הוגדר" ולא כ"ריק".
     *
     * מי שהעתיק את .env.example כפי שהוא מגיע עם השורה ריקה ולא חסרה, ו-env()
     * מחזיר אז מחרוזת ריקה בלי לגעת בברירת המחדל — כלומר קישור mailto ריק
     * דווקא אצל מי שלא שינה כלום.
     */
    public function test_a_blank_contact_address_falls_back_instead_of_rendering_empty(): void
    {
        $this->assertNotSame('', trim((string) config('legal.contact_email')));

        $this->get(route('legal.privacy'))->assertDontSee('mailto:"', false);
    }

    /**
     * ואותו דבר בטלפון: סעיף פרטי ההתקשרות בתנאי השימוש נסתר כשהערך ריק,
     * והשורה ב-.env.example ריקה — כך שברירת מחדל של env() לא הייתה נקראת,
     * והסעיף היה נעלם דווקא בהתקנה שלא שינתה כלום.
     */
    public function test_a_blank_phone_line_falls_back_instead_of_hiding_the_contact_section(): void
    {
        $this->assertNotSame('', trim((string) config('legal.company.phone')));

        $this->get(route('legal.terms'))
            ->assertSeeText('טלפון:')
            ->assertDontSee('tel:"', false);
    }

    /**
     * מה שנאמר על הצעה שפגה הוא מה שהקוד עושה.
     *
     * עבודת הניקוי מוחקת את קובץ התמונה ומסמנת את ההצעה כפגה — היא אינה מוחקת
     * את השורה. מסמך שמבטיח מחיקה של תוכן הבקשה היה הצהרה שקרית על מידע של
     * לקוח, וזו הטענה הכי מסוכנת שאפשר לכתוב בעמוד כזה.
     */
    public function test_the_expiry_claim_matches_what_the_pruning_job_does(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 133]);

        $this->get(route('legal.privacy'))
            // הניקוי רץ אחת לשעה, ולכן קובץ של הצעה שפגה מעצמה אינו נמחק באותו
            // רגע. "מיד" היה הבטחה שלוח הזמנים אינו מקיים.
            ->assertSeeText('בתוך שעה')
            ->assertDontSeeText('נמחק מהשרת מיד')
            // והטקסט — לפי החלון שהעבודה באמת אוכפת.
            ->assertSeeText('133 ימים');
    }

    /**
     * חלון 0 פירושו שהמחיקה מושבתת — ולא שהיא מיידית.
     *
     * forgetOld() יוצאת בלי למחוק דבר כשהערך אינו חיובי, כך ש"0 ימים" בטבלה היה
     * הופך הגדרה שמשביתה מחיקה להבטחה למחיקה מיידית. היפוך מלא של ההתנהגות,
     * במשפט שלקוח מסתמך עליו.
     */
    public function test_a_disabled_window_is_not_shown_as_zero_days(): void
    {
        config(['billing.system.site_agent_request_retention_days' => 0]);

        // לפי סדר ההופעה ולא כחיפוש מחרוזת: "0 ימים" הוא תת-מחרוזת של
        // "180 ימים" ושל "90 ימים", כך שחיפוש פשוט היה עובר תמיד — או נכשל
        // תמיד — בלי קשר לשורה שנבדקת.
        $this->get(route('legal.privacy'))->assertSeeTextInOrder([
            'בקשות ששלחתם לבוט בוואטסאפ, וההצעות שהוצגו לכם',
            'המחיקה האוטומטית מושבתת',
        ]);
    }

    /**
     * והתווית הזאת שייכת רק לשורה שהניקוי שלה באמת מכבד אפס.
     *
     * שאר העבודות מעבירות את המספר ישירות ל-subDays(), כך ש-0 אצלן מוחק כמעט
     * הכול בריצה הבאה. "המחיקה מושבתת" עליהן היה היפוך של ההתנהגות — בדיוק
     * ההפך מהבעיה שהתווית נולדה לפתור.
     */
    public function test_the_disabled_label_is_not_claimed_for_jobs_that_ignore_zero(): void
    {
        config(['security.audit.retention_days' => 0]);

        $this->get(route('legal.privacy'))->assertSeeTextInOrder([
            'יומן הביקורת של פעולות במערכת',
            '0 ימים',
        ]);
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

    /*
    | ----------------------------------------------------------------
    | הסכם השירות הכללי, משוזר לתוך התנאים
    | ----------------------------------------------------------------
    */

    /**
     * שעות התמיכה נאמרות — ולא כאילו הן גם שעות הבוט.
     *
     * הבוט אוטומטי ועונה בכל שעה; התמיכה האנושית היא א׳–ה׳ 8:00–17:00 ולא בשבת.
     * נוסח אחד לשניהם היה או מבטיח תמיכה 24/7 או טוען שהבוט שותק בשבת, ושתי
     * האמירות אינן נכונות — ולכן ההפרדה היא הדבר שנבדק כאן, לא רק השעות.
     */
    public function test_the_support_hours_are_stated_apart_from_the_bots_own_hours(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('8:00–17:00')
            ->assertSeeText('הבוט פועל אוטומטית בכל שעה')
            ->assertSeeText('אינו פעיל בשבת');
    }

    /** ושאין התחייבות לזמן פתרון — ההבטחה היחידה שאסור לנסח ברישול. */
    public function test_no_resolution_time_is_promised(): void
    {
        $this->get(route('legal.terms'))->assertSeeText('איננו מתחייבים למסגרת זמן');
    }

    /**
     * השירות לעסקים בלבד — וגם הקופה אומרת זאת.
     *
     * תנאים שמגבילים את השירות לעסקים מול קופה שמוכרת לכל מי שנכנס הם מסמך
     * שאינו חל על חלק מהלקוחות שאישרו אותו. שני המקומות נבדקים יחד בכוונה.
     */
    public function test_the_service_is_for_businesses_in_both_the_terms_and_the_checkout(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('מיועד לעסקים ולארגונים בלבד')
            ->assertSeeText('אינו מיועד לשימוש פרטי');

        // על התבנית ולא על עמוד מורנדר, מאותה סיבה שהבדיקה הקיימת למטה עושה כך:
        // עמוד הרכישה חסום מאחורי מסלול פעיל ומוצר מוכן, וגרסה שפותחת אותו הייתה
        // מדלגת בשקט כשהתנאי אינו מתקיים.
        $this->assertStringContainsString(
            'רוכש/ת עבור עסק',
            file_get_contents(resource_path('views/store/site-agent.blade.php')),
            'הקופה אינה אומרת שהשירות לעסקים, בעוד שהתנאים שהיא מבקשת לאשר מגבילים אותו לכך.',
        );
    }

    /** הספקים בשמם — מי מאחסן את האתר ומי שולח את הדוא״ל. */
    public function test_the_terms_name_the_infrastructure_providers(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('hetzner')
            ->assertSeeText('postmark')
            ->assertSeeText('cloudflare');
    }

    /**
     * תקרת האחריות היא 12 החודשים — ואין לידה "הסעד היחיד הוא ביטול".
     *
     * שתי הגבלות שונות שלא ניתן לכתוב יחד: הסכם השירות הכללי אמר "הסעד היחיד
     * שלך הוא ביטול המנוי", והתנאים אומרים תקרה לפי מה ששולם. נבחרה התקרה, ולכן
     * הנוסח הסותר לא אמור להופיע.
     */
    public function test_the_liability_cap_is_the_twelve_month_figure_and_not_a_sole_remedy_clause(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('שנים-עשר החודשים')
            ->assertDontSeeText('הסעד היחיד');
    }

    /** יישוב סכסוכים: גישור או בורר לפני בית המשפט, ואז תל אביב-יפו. */
    public function test_dispute_resolution_comes_before_the_court(): void
    {
        $this->get(route('legal.terms'))->assertSeeTextInOrder([
            'בורר מוסמך',
            'תל אביב-יפו',
        ]);
    }

    /** ופרטי ההתקשרות — חברה, ח.פ. וטלפון. */
    public function test_the_terms_carry_the_contact_details(): void
    {
        config(['legal.company.phone' => '03-000-0000']);

        $this->get(route('legal.terms'))
            ->assertSeeText(config('legal.company.name'))
            ->assertSeeText('03-000-0000');
    }

    /**
     * אמירת המע״מ מבחינה בין המוצרים, כי הקופות עצמן מציגות אחרת.
     *
     * עמוד בוט ניהול האתר מצטט נטו ומוסיף "+ מע״מ"; עמוד התוספים מציג מחיר שנגזר
     * מ-grossAgorot() ואומר במפורש "המחירים כוללים מע״מ". אמירה גורפת לכאן או
     * לכאן בתנאים הייתה נסתרת על ידי אחת מהקופות — כלומר כל קוני התוספים היו
     * מאשרים תנאים שאומרים את ההפך ממה שהם רואים ומשלמים.
     */
    public function test_the_vat_statement_tells_the_two_product_families_apart(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('הקובע הוא מה שמצוין בעמוד הרכישה')
            ->assertSeeText('במסלולי התוספים המחיר המוצג כולל מע״מ')
            ->assertDontSeeText('המחירים כוללים מע״מ, אלא אם');
    }

    /**
     * ואין בתנאים הבטחה גורפת שהאתר ממשיך לעבוד כשלא שולם.
     *
     * הנוסח הזה היה נכון כשהמסמך כיסה את הבוט בלבד. מרגע שהוא מכסה גם אחסון הוא
     * נסתר על ידי DunningMachine::handleFailure(), ששולחת SuspendSiteJob בשלב
     * המשהה כשלמנוי יש site_id. התחייבות שהמערכת מפרה בעצמה גרועה מהיעדר
     * התחייבות.
     */
    public function test_an_unpaid_hosting_subscription_is_not_promised_to_keep_running(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('בתום המסלול האתר עצמו מושהה בשרת')
            ->assertSeeText('הנתונים נשמרים')
            ->assertDontSeeText('תשלום שלא נפרע עשוי להוביל להשהיית השירות. האתר עצמו ממשיך לעבוד');
    }

    /**
     * ושלושת המקרים בתנאים הם שלושת מכתבי ההשהיה שהמערכת שולחת.
     *
     * לקוח שמקבל את המכתב ופותח את התנאים משווה בדיוק את זה, ולכן המסמך אומר את
     * מה שהמכתב אומר — ובמקרה של רישיון תוסף, באותן מילים.
     */
    public function test_the_three_suspension_cases_match_the_three_dunning_letters(): void
    {
        $terms = $this->get(route('legal.terms'));

        $terms->assertSeeText('מנוי שכולל אחסון')
            ->assertSeeText('מנוי בוט בלבד')
            ->assertSeeText('מנוי רישיון לתוסף');

        // אותו נוסח בדיוק שמכתב הרישיון מבטיח — ושעמוד התוספים מבטיח לפניו.
        $terms->assertSeeText('התוסף ממשיך לעבוד');
        $this->assertStringContainsString('התוסף ממשיך לעבוד', __('dunning.site_suspended_license.body', [
            'name' => 'דנה', 'plan' => 'רישיון', 'amount' => '236.00',
            'update_link' => 'https://example.test', 'until' => '01/01/2027',
        ]));
    }

    /**
     * מספר ההתראות וימי ההמתנה נקראים מהקונפיג ולא מוקלדים בטקסט.
     *
     * מסלול הדאנינג ניתן לשינוי ב-config/billing.php, ומספר כתוב במסמך היה הופך
     * בשקט להתחייבות שאינה נכונה.
     */
    public function test_the_dunning_notices_are_read_from_the_billing_config(): void
    {
        config(['billing.dunning.stages' => [
            1 => ['template' => 'a', 'retry_in_days' => 4, 'suspend' => false],
            2 => ['template' => 'b', 'retry_in_days' => 5, 'suspend' => false],
            3 => ['template' => 'c', 'retry_in_days' => null, 'suspend' => true],
        ]]);

        $this->get(route('legal.terms'))
            ->assertSeeText('2 התראות')
            ->assertSeeText('9 ימים');
    }

    /**
     * וכשירות ההתקשרות מתארת את סוגי העסקים שהמערכת באמת רושמת.
     *
     * App\Enums\BusinessType מקבל עוסק פטור, עוסק מורשה, חברה ומלכ״ר. נוסח
     * שהגביל את השירות ל"ישויות משפטיות שבבעלות בני 18 ומעלה" היה מוציא ממנו
     * עוסק — שאינו ישות משפטית נפרדת — ומלכ״ר, שאין לו בעלים.
     */
    public function test_eligibility_covers_the_business_types_the_system_registers(): void
    {
        $terms = $this->get(route('legal.terms'))
            ->assertDontSeeText('לישויות משפטיות שבבעלות בני 18 ומעלה');

        // מול ה-enum ולא מול רשימה שהועתקה לכאן: סוג שיתווסף או ישונה ייכשל כאן
        // במקום להישאר מסמך שמתאר טופס הרשמה אחר. SignupRequest דוחה כל ערך שאינו
        // ב-enum, ולכן גם ההפך חמור — כשירות שהובטחה למי שאינו יכול להירשם בכלל.
        foreach (BusinessType::cases() as $type) {
            $terms->assertSeeText($type->getLabel());
        }

        // ודוגמאות, לא רשימה סגורה: SiteAgentStoreController::buy() אינו מאמת סוג
        // עסק כלל, כך שרשימה סגורה הייתה שוללת כשירות ממי שהקופה מוכרת לו.
        $terms->assertSeeText('מיועד לעסקים ולארגונים בלבד ואינו מיועד לשימוש פרטי');

        // על טקסט מנורמל, כי בתבנית "למשל" והרשימה יושבים בשורות נפרדות.
        $this->assertStringContainsString(
            'למשל '.BusinessType::cases()[0]->getLabel(),
            (string) preg_replace('/\s+/u', ' ', strip_tags((string) $terms->getContent())),
            'רשימת סוגי העסקים מוצגת כרשימה סגורה ולא כדוגמאות.',
        );
    }

    /**
     * ואין בתנאים הבטחת פטור ממע״מ שהקופה הפומבית אינה מקיימת.
     *
     * PluginCheckout::customer() ו-SiteAgentCheckout::customer() יוצרות Customer
     * בלי vat_exempt, ואף אחד מהטפסים אינו שואל — כלומר קונה חדש שפטור כן מחויב
     * במע״מ. ההבטחה מנוסחת לפי מה שקורה באמת, ונאמר ללקוח מה לעשות.
     */
    public function test_the_vat_exemption_is_not_promised_to_a_checkout_that_never_asks(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('מוחל על לקוח שרשום אצלנו כפטור')
            ->assertSeeText('הקופה הפומבית אינה שואלת')
            ->assertDontSeeText('לקוח הפטור ממע״מ כדין — לא נגבה ממנו מע״מ');
    }

    /**
     * והמסמך אינו אומר על חוב גם "מיידית" וגם "לא מיידית".
     *
     * סעיף הפסקת השירות שומר את ההשהיה המיידית להפרה, לשימוש לרעה ולפגיעה
     * באבטחה — ומפנה לגבי חוב אל מסלול ההתראות שבסעיף התשלום.
     */
    public function test_debt_is_not_listed_among_the_immediate_suspension_grounds(): void
    {
        $this->get(route('legal.terms'))
            ->assertSeeText('הפרת תנאים אלה, שימוש לרעה, פגיעה באבטחה, או כשיש חשש סביר')
            ->assertSeeText('ורק אחריהם השהיה');
    }
}
