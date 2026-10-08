<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentRequest;

/**
 * What the site agent is allowed to change — set by the team on the product
 * settings screen.
 *
 * Every change the bot can make belongs to one permission. A permission that
 * is switched off is enforced three times, so no single path can slip past
 * it: the model is not given the tool, a proposal for it is refused, and an
 * offer already waiting is refused at the "כן".
 *
 * Off is stored, not on: a permission added in a later release starts
 * allowed rather than silently disabled everywhere.
 */
class SiteAgentPermissions
{
    /**
     * key => [label, operations, assistant tools].
     *
     * @var array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public const GROUPS = [
        'products_create' => ['יצירת מוצרים חדשים', [SiteAgentRequest::OP_PRODUCT_CREATE], ['propose_product_create']],
        'products_update' => ['עדכון מוצרים (מחיר, מבצע, מלאי, שם, תיאור)', [SiteAgentRequest::OP_PRODUCT, SiteAgentRequest::OP_PRICE, SiteAgentRequest::OP_STOCK], ['propose_product_update']],
        'products_delete' => ['מחיקת מוצרים (לפח, עם ביטול)', [SiteAgentRequest::OP_PRODUCT_TRASH], ['propose_product_trash']],
        'orders' => ['הזמנות — שינוי סטטוס והערות', [SiteAgentRequest::OP_ORDER_STATUS, SiteAgentRequest::OP_ORDER_NOTE], ['propose_order_status', 'propose_order_note']],
        'subscriptions' => ['מנויים מתחדשים — השהיה, חידוש וביטול', [SiteAgentRequest::OP_SUBSCRIPTION_STATUS], ['propose_subscription_status']],
        'coupons' => ['קופונים — יצירה וסיום', [SiteAgentRequest::OP_COUPON, SiteAgentRequest::OP_COUPON_EXPIRE], ['propose_coupon', 'propose_coupon_expire']],
        'content' => ['תוכן — כתיבה, עריכה, תזמון וסידור', [SiteAgentRequest::OP_APPEND, SiteAgentRequest::OP_REPLACE, SiteAgentRequest::OP_TITLE, SiteAgentRequest::OP_POST_CREATE, SiteAgentRequest::OP_POST_UPDATE, SiteAgentRequest::OP_CONTENT_MANAGE], ['propose_post_create', 'propose_post_update', 'propose_text_edit', 'edit_page_text', 'propose_content_manage']],
        'images' => ['החלפת תמונות מתמונה שנשלחה', [SiteAgentRequest::OP_IMAGE], []],
        'content_trash' => ['העברת פוסטים ועמודים לפח', [SiteAgentRequest::OP_TRASH], ['propose_trash']],
        'users' => ['משתמשים — יצירה, פרופיל ותפקיד', [SiteAgentRequest::OP_USER_CREATE, SiteAgentRequest::OP_USER_ROLE, SiteAgentRequest::OP_USER_PROFILE], ['propose_user_create', 'propose_user_role', 'propose_user_profile']],
        'comments' => ['תגובות — אישור, ספאם ופח', [SiteAgentRequest::OP_COMMENT], ['propose_comment_moderation']],
        'structure' => ['קטגוריות, שדות מותאמים ותפריטים', [SiteAgentRequest::OP_TERM_CREATE, SiteAgentRequest::OP_POST_TERMS, SiteAgentRequest::OP_FIELDS, SiteAgentRequest::OP_ACF, SiteAgentRequest::OP_MENU_ADD, SiteAgentRequest::OP_MENU_UPDATE, SiteAgentRequest::OP_MENU_REMOVE], ['propose_term_create', 'propose_item_terms', 'propose_fields_update', 'propose_acf_update', 'propose_menu_item_add', 'propose_menu_item_update', 'propose_menu_item_remove']],
        'plugins' => ['תוספים ותבנית — הפעלה, כיבוי והחלפת תבנית מותקנת', [SiteAgentRequest::OP_PLUGIN_UPDATE, SiteAgentRequest::OP_THEME_UPDATE, SiteAgentRequest::OP_PLUGIN_TOGGLE, SiteAgentRequest::OP_THEME_SWITCH], ['propose_plugin_update', 'propose_theme_update', 'propose_plugin_toggle', 'propose_theme_switch']],
        'media' => ['מדיה — העלאת תמונות, כותרות, תיאורים וארגון', [SiteAgentRequest::OP_MEDIA_UPLOAD, SiteAgentRequest::OP_MEDIA_UPDATE], ['propose_media_update']],
        'optimole' => ['Optimole — איכות ואופטימיזציה של תמונות', [SiteAgentRequest::OP_OPTIMOLE], ['propose_optimole_update']],
        'seo' => ['SEO — כותרות, תיאורים וקישורים פנימיים', [SiteAgentRequest::OP_SEO, SiteAgentRequest::OP_INTERNAL_LINK], ['propose_seo_update', 'propose_internal_link']],
        'cct' => ['JetEngine CCT — יצירה ועריכת רשומות', [SiteAgentRequest::OP_CCT_CREATE, SiteAgentRequest::OP_CCT_UPDATE], ['propose_cct_create', 'propose_cct_update']],
        'settings' => ['הגדרות האתר — שם, תצוגה, אזור זמן ועמוד בית', [SiteAgentRequest::OP_SITE_SETTINGS], ['propose_site_settings']],
        'cache' => ['ניקוי מטמון', [SiteAgentRequest::OP_CACHE_FLUSH], ['propose_cache_flush']],
    ];

    /** @return array<string, string> key => label, for the settings form */
    public static function options(): array
    {
        return array_map(fn (array $group): string => $group[0], self::GROUPS);
    }

    /** @return list<string> the keys the team switched off */
    public function disabled(): array
    {
        $raw = config('siteagent.assistant.disabled_permissions', '');
        $keys = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_intersect(array_map('trim', $keys), array_keys(self::GROUPS)));
    }

    /** May the assistant be offered this tool? Tools outside every group are not ours to block. */
    public function allowsTool(string $tool): bool
    {
        foreach ($this->disabled() as $key) {
            if (in_array($tool, self::GROUPS[$key][2], true)) {
                return false;
            }
        }

        return true;
    }

    /** May a change of this kind be carried out? */
    public function allowsOperation(?string $operation): bool
    {
        foreach ($this->disabled() as $key) {
            if (in_array($operation, self::GROUPS[$key][1], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> labels of what is switched off, for the model and the owner */
    public function disabledLabels(): array
    {
        return array_map(fn (string $key): string => self::GROUPS[$key][0], $this->disabled());
    }

    /** What the owner is told when they ask for something switched off. */
    public static function refusal(): string
    {
        return 'הפעולה הזו כבויה בחשבון שלכם ולכן לא בוצעה. אם תרצו שנפעיל אותה — פנו אלינו.';
    }
}
