<?php

namespace App\Services\SiteAgent;

/** Terminal explanations for known capability limits, never executable fallbacks. */
final class SiteAgentCapabilityReply
{
    public const TOOL = 'explain_capability_limit';

    private const REASONS = [
        'order_customer_email' => 'שליחת הערה ללקוח באימייל אינה נתמכת דרך הבוט. הערה פנימית אינה הודעה ללקוח, ולכן לא החלפתי את הבקשה בהערה פנימית.',
        'seo_canonical' => 'שינוי כתובת canonical אינו נתמך דרך כלי ה-SEO של הבוט. ניתן לערוך כותרת ותיאור SEO; עריכת ACF או טקסט בעמוד אינה דרך לשנות את הגדרת canonical.',
        'elementor_link' => 'הוספה או שינוי של קישור בתוך ווידגט Elementor אינם נתמכים דרך הבוט. החלפת המילים אינה יוצרת את הקישור המבוקש.',
        'elementor_structure' => 'מחיקת ווידגט ושינוי פריסה או מבנה Elementor אינם נתמכים דרך הבוט. עריכת הטקסט שבו אינה מבצעת את שינוי המבנה המבוקש.',
        'permanent_deletion' => 'מחיקה לצמיתות ללא אפשרות שחזור אינה נתמכת דרך הבוט. העברה לפח היא פעולה אחרת ולא הוצעה במקומה.',
        'permanent_subscription_cancellation' => 'ביטול מיידי וסופי של מנוי WooCommerce אינו נתמך דרך הבוט. ביטול בסוף התקופה הוא פעולה אחרת ולא הוצע במקומו.',
        'refund' => 'ביצוע החזר כספי אינו נתמך דרך הבוט. שינוי סטטוס הזמנה אינו מבצע החזר כספי.',
        'unrecoverable_update' => 'התקנת תוספים או תבניות ועדכון וורדפרס, תוספים או תבניות ללא מסלול שחזור מאומת אינם נתמכים דרך הבוט.',
        'media_file_rename' => 'שינוי שם הקובץ הפיזי או כתובתו אינו נתמך דרך הבוט. שינוי כותרת בספריית המדיה אינו משנה את הקובץ או את כתובתו.',
        'menu_item_removal' => 'הסרת פריט קיים מתפריט אינה נתמכת דרך הבוט. ניתן לערוך פריט או להוסיף פריט, אך אלה אינם תחליף להסרה.',
        'security_management' => 'שינוי סיסמאות, מתן הרשאת מנהל וביטול הגנות אינם נתמכים דרך הבוט. פעולות אלה דורשות ניהול ישיר של האתר בידי מנהל מורשה.',
        'code_execution' => 'הרצת קוד או עריכת קובצי האתר אינן נתמכות דרך הבוט.',
        'learndash_progress' => 'שינוי התקדמות, איפוס התקדמות, השלמת שיעורים והזנת ציוני מבחנים ב-LearnDash אינם נתמכים דרך הבוט. מידע זה זמין לקריאה בלבד.',
        'learndash_structure' => 'שיוך קורס לקבוצה ושינוי מבנה הלמידה ב-LearnDash אינם נתמכים דרך הבוט. רישום תלמיד לקורס או לקבוצה אינו אותה פעולה.',
        'optimole_secrets' => 'חשיפה או שינוי של מפתחות API וסודות Optimole אינם נתמכים דרך הבוט.',
        'optimole_offload' => 'הפעלת offload ומחיקת קובצי המקור דרך Optimole אינן נתמכות דרך הבוט.',
        'remote_media_download' => 'הורדת קובץ מקישור חיצוני לצורך העלאה אינה נתמכת דרך הבוט. להעלאת תמונה יש לשלוח את הקובץ עצמו בוואטסאפ.',
        'protected_plugin' => 'כיבוי תוסף מוגן או חיוני, כגון WooCommerce, אינו נתמך דרך הבוט.',
        'cct_system_fields' => 'עריכת עמודות מערכת או מזהי רשומות CCT אינה נתמכת דרך הבוט. ניתן לערוך רק שדות שהסכמה החיה מאפשרת.',
        'support_handoff' => 'אין לבוט כלי לשליחת פנייה לצוות התמיכה או לפתיחת קריאת שירות, ולכן לא נשלחה פנייה ולא נפתחה קריאה.',
    ];

    public function definition(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'סיום הבקשה בהסבר מאומת על יכולת שאינה נתמכת. בחר reason שמתאים לפעולה שבעל האתר ביקש; הכלי שולח הסבר קבוע בלבד, ללא שינוי באתר וללא פנייה לתמיכה. אין לבחור בו כאשר חסר פרט לפעולה נתמכת: אז שאל על הפרט. אין להציע חלופה שנשללה או דרך עקיפה. אחרי הקריאה התשובה נשלחת כפי שהיא והסבב מסתיים.',
            'input_schema' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['reason' => ['type' => 'string', 'enum' => array_keys(self::REASONS)]],
                'required' => ['reason'],
            ],
        ];
    }

    public function reply(mixed $reason): ?string
    {
        if (! is_string($reason) || ! isset(self::REASONS[$reason])) {
            return null;
        }

        $support = trim((string) config('billing.email.support_address', ''));
        $contact = filter_var($support, FILTER_VALIDATE_EMAIL)
            ? 'אפשר לפנות ישירות לצוות התמיכה: '.$support.'.'
            : 'אפשר לפנות ישירות לצוות התמיכה דרך פרטי הקשר שלכם.';

        return self::REASONS[$reason]."\n\n".'לא שיניתי דבר באתר. '.$contact;
    }
}
