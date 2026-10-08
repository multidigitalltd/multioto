<?php

namespace App\Services\SiteAgent;

/**
 * The model-facing vocabulary for companion-plugin capabilities that use
 * explicit snapshots. The bridge still validates live schemas and identities;
 * these JSON schemas describe proposals, never permission to execute a write.
 */
final class SiteAgentExtendedCatalogue
{
    /** @return array<string, array{0: string, 1: string, 2: array, 3: array}> */
    public static function reads(): array
    {
        $id = self::id();
        $type = self::cctType();

        return [
            'list_cct_types' => ['jet_cct_types', 'גילוי סוגי JetEngine CCT, מפתחות השדות, אפשרויות והרשאות עריכה. יש לקרוא לפני חיפוש או הצעה. CCT נשמר בטבלאות נפרדות מפוסטים.', [], []],
            'find_cct' => ['jet_cct_list', 'חיפוש פריטי CCT מסוג שהתגלה, כולל טיוטות. filters הוא מיפוי מפתח שדה נתמך לערך מדויק. יש לעקוב אחרי has_more ולדפדף; אין להסיק שהעמוד הראשון מכיל הכול.', [
                'type' => $type,
                'filters' => ['type' => 'object', 'maxProperties' => 10, 'description' => 'שמות שדות מתוך הסכמה וערכים מדויקים בלבד; אין SQL או אופרטורים.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], ['type']],
            'get_cct' => ['jet_cct_get', 'קריאת פריט CCT לפי הסוג והמזהה שנמצאו; values מכיל את הערכים לסקירה ולשינוי.', ['type' => $type, 'id' => $id], ['type', 'id']],
            'get_media_details' => ['wp_media_get', 'פרטי קובץ מדיה: כותרת, טקסט חלופי, כיתוב, תיאור, שיוך לתוכן ומונחי מיון זמינים. שינוי שם משמעו כותרת בספרייה; שם הקובץ הפיזי וכתובת הקובץ אינם משתנים.', ['id' => $id], ['id']],
            'get_optimole' => ['wp_optimole_get', 'מצב חיבור Optimole והגדרות האופטימיזציה הזמינות. אין להציע שינוי אם התוסף אינו פעיל ומחובר. אין גישה לסודות או שינוי קובצי מקור.', [], []],
            'get_content_details' => ['wp_content_details', 'פרטי תזמון וסידור פוסט או עמוד: מצב, מועד פרסום, אזור זמן האתר, אב, סדר ו-slug. תזמון נבדק לפי אזור הזמן שמוחזר.', ['id' => $id], ['id']],
            'get_seo' => ['wp_seo_get', 'כותרת ותיאור SEO מהתוסף הפעיל, Yoast או Rank Math. null פירושו שאין התאמה אישית. אם שני התוספים פעילים יש לבחור provider בקריאה.', ['id' => $id, 'provider' => ['type' => 'string', 'enum' => ['yoast', 'rank_math']]], ['id']],
            'get_internal_links' => ['wp_internal_links_get', 'התוכן והקישורים הפנימיים בעמוד, לצורך קישור טקסט קיים לפריט אחר באותו אתר. עמודי Elementor אינם נתמכים בפעולה זו.', ['id' => $id], ['id']],
            'get_site_settings' => ['wp_site_settings_get', 'הגדרות תצוגת האתר: שם, תיאור, אזור זמן, תאריכים, עמוד הבית, עמוד הפוסטים וכמות הפוסטים בעמוד.', [], []],
            'get_user_profile' => ['wp_user_profile_get', 'פרטי פרופיל משתמש שניתן לערוך: שם תצוגה, שם פרטי, משפחה ותיאור. אין שינוי סיסמאות, הרשאות או חשבונות מוגנים.', ['id' => $id], ['id']],
            'get_active_theme' => ['wp_theme_active_get', 'ערכת הנושא הפעילה כעת לצורך סקירת מעבר לערכה מותקנת. את היעד בוחרים רק מרשימת ערכות הנושא באתר.', [], []],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function actions(): array
    {
        $id = self::id();
        $type = self::cctType();
        $text = ['type' => 'string'];
        $enabled = ['type' => 'string', 'enum' => ['enabled', 'disabled']];
        $cctValues = ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 100,
            'description' => 'מפתחות וערכים מסכמת CCT החיה בלבד. שדות מורכבים ומוגנים חסומים. cct_status יכול להיות publish או draft; אין מחיקה סופית.'];

        return [
            'propose_cct_create' => [
                'operation' => 'cct_create', 'read' => 'jet_cct_types', 'write' => 'jet_cct_create',
                'title' => 'יצירת פריט CCT',
                'description' => 'הצעת יצירת פריט בסוג CCT שהתגלה. ברירת המחדל טיוטה. ביטול פרסום משאיר טיוטה ואינו מוחק את הרשומה; תצוגות מותאמות עשויות להציג גם טיוטות.',
                'properties' => ['type' => $type, 'values' => $cctValues], 'required' => ['type', 'values'], 'fields' => ['*'], 'identity' => ['type'],
            ],
            'propose_cct_update' => [
                'operation' => 'cct_update', 'read' => 'jet_cct_get', 'write' => 'jet_cct_update',
                'title' => 'עדכון פריט CCT',
                'description' => 'הצעת שינוי שדות רשומים בפריט CCT שנקרא, או מעבר הפיך בין publish ל-draft. אין מחיקה סופית או עריכת עמודות מערכת.',
                'properties' => ['type' => $type, 'id' => $id, 'values' => $cctValues], 'required' => ['type', 'id', 'values'], 'fields' => ['*'], 'identity' => ['type', 'id'],
            ],
            'propose_media_update' => self::action('media_update', 'wp_media_get', 'wp_media_update', 'עדכון פרטי מדיה',
                'עריכת כותרת בספריית המדיה, טקסט חלופי, כיתוב, תיאור ושיוך לתוכן. ארגון לפי מונחים רק בטקסונומיות שהאתר מספק. אין שינוי שם הקובץ הפיזי או כתובתו.', [
                    'title' => ['type' => 'string', 'maxLength' => 1000],
                    'alt' => ['type' => 'string', 'maxLength' => 2000],
                    'caption' => ['type' => 'string', 'maxLength' => 20000],
                    'description' => ['type' => 'string', 'maxLength' => 50000],
                    'parent_id' => ['type' => 'integer', 'minimum' => 0],
                    'terms' => ['type' => 'object', 'description' => 'מפתחות רק מתוך editable_taxonomies; כל ערך רשימת מזהי מונחים קיימים.', 'additionalProperties' => ['type' => 'array', 'items' => $id]],
                ], ['id']),
            'propose_optimole_update' => self::action('optimole_update', 'wp_optimole_get', 'wp_optimole_update', 'עדכון אופטימיזציית תמונות ב-Optimole',
                'הצעת שינוי הגדרות רק כאשר Optimole פעיל ומחובר ורק בשדות editable_fields שנקראו. איכות ידנית משפיעה כאשר autoquality=disabled. אין שינוי מפתחות API, קובצי מקור או offload.', [
                    'quality' => ['type' => 'integer', 'minimum' => 50, 'maximum' => 100],
                    'image_replacer' => $enabled, 'autoquality' => $enabled, 'lazyload' => $enabled,
                    'lazyload_placeholder' => $enabled, 'retina_images' => $enabled,
                    'resize_smart' => $enabled, 'native_lazyload' => $enabled,
                ], []),
            'propose_content_manage' => self::action('content_manage', 'wp_content_details', 'wp_content_manage', 'תזמון וסידור תוכן',
                'הצעת תזמון, שינוי מצב, עמוד אב, סדר או slug לפוסט/עמוד שנקרא. לפרסום עתידי יש לשלוח status=future ו-date לפי אזור זמן האתר. date_gmt מחושב בשרת.', [
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish', 'private', 'future']],
                    'date' => ['type' => 'string', 'description' => 'תאריך ושעה מקומיים באזור זמן האתר בפורמט YYYY-MM-DD HH:mm:ss.'],
                    'parent' => ['type' => 'integer', 'minimum' => 0],
                    'menu_order' => ['type' => 'integer', 'minimum' => -100000, 'maximum' => 100000],
                    'slug' => ['type' => 'string', 'maxLength' => 200],
                ], ['id']),
            'propose_seo_update' => self::action('seo_update', 'wp_seo_get', 'wp_seo_update', 'עדכון כותרת ותיאור SEO',
                'הצעת עריכת כותרת ותיאור SEO בתוסף הפעיל שנקרא. null מסיר התאמה אישית ומחזיר את ברירת המחדל של התוסף. ספק SEO נקבע מהמידע החי.', [
                    'title' => ['type' => ['string', 'null']],
                    'description' => ['type' => ['string', 'null']],
                ], ['id']),
            'propose_internal_link' => self::action('internal_link', 'wp_internal_links_get', 'wp_internal_link_update', 'הוספת קישור פנימי',
                'הצעת קישור הופעה יחידה של טקסט קיים לפריט מפורסם שנקרא באותו אתר. text חייב להיות טקסט גלוי מדויק ולא HTML; target_id הוא מזהה פריט היעד. אין החלפת תוכן חופשי.', [
                    'text' => ['type' => 'string', 'minLength' => 1],
                    'target_id' => $id,
                ], ['id'], ['text', 'target_id']),
            'propose_site_settings' => self::action('site_settings', 'wp_site_settings_get', 'wp_site_settings_update', 'עדכון הגדרות תצוגת האתר',
                'הצעת שינוי הגדרות תצוגה ידועות בלבד. עמוד בית/בלוג נבחרים מתוך עמודים שנקראו. אין שינוי כתובות האתר, הרשאות, סודות או אפשרויות תוספים חופשיות.', [
                    'blogname' => $text, 'blogdescription' => $text,
                    'timezone_string' => ['type' => 'string', 'description' => 'מזהה IANA, למשל Asia/Jerusalem. שינוי אזור זמן ו-gmt_offset נעשים בפעולות נפרדות.'],
                    'gmt_offset' => ['type' => 'number', 'minimum' => -14, 'maximum' => 14],
                    'date_format' => $text, 'time_format' => $text,
                    'start_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
                    'show_on_front' => ['type' => 'string', 'enum' => ['posts', 'page']],
                    'page_on_front' => ['type' => 'integer', 'minimum' => 0],
                    'page_for_posts' => ['type' => 'integer', 'minimum' => 0],
                    'posts_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ], []),
            'propose_user_profile' => self::action('user_profile', 'wp_user_profile_get', 'wp_user_profile_update', 'עדכון פרופיל משתמש',
                'הצעת שינוי פרטי פרופיל של משתמש שנקרא ומותר לעריכה. אין שינוי סיסמה, אימייל התחברות, הרשאות או מנהלי אתר.', [
                    'display_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 250],
                    'first_name' => ['type' => 'string', 'maxLength' => 250],
                    'last_name' => ['type' => 'string', 'maxLength' => 250],
                    'description' => ['type' => 'string', 'maxLength' => 10000],
                ], ['id']),
            'propose_theme_switch' => self::action('theme_switch', 'wp_theme_active_get', 'wp_theme_active_set', 'החלפת ערכת נושא פעילה',
                'הצעת הפעלת ערכת נושא מותקנת ותקינה מתוך הרשימה שנקראה. stylesheet הוא המזהה המדויק, לא שם התצוגה. המעבר משנה את עיצוב האתר; אפשר לחזור לערכה הקודמת כל עוד היא זמינה.', [
                    'stylesheet' => ['type' => 'string', 'minLength' => 1],
                ], [], ['stylesheet']),
        ];
    }

    private static function action(string $operation, string $read, string $write, string $title, string $description, array $fields, array $identity, array $requiredValues = []): array
    {
        $properties = [];
        foreach ($identity as $key) {
            $properties[$key] = $key === 'type' ? self::cctType() : self::id();
        }
        $values = ['type' => 'object', 'properties' => $fields, 'additionalProperties' => false, 'minProperties' => 1];
        if ($requiredValues !== []) {
            $values['required'] = $requiredValues;
        }
        $properties['values'] = $values;

        return compact('operation', 'read', 'write', 'title', 'description', 'properties', 'identity')
            + ['required' => [...$identity, 'values'], 'fields' => array_keys($fields)];
    }

    private static function id(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    private static function cctType(): array
    {
        return ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$', 'description' => 'slug של סוג CCT כפי שחזר ב-list_cct_types, לעולם לא שם טבלה.'];
    }
}
