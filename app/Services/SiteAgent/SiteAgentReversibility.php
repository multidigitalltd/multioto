<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentRequest;

/**
 * Customer-bot restrictions for changes that cannot be recovered.
 *
 * This is an additional guard, not an authorization or operation allowlist.
 * A null reason does not promise an automatic undo: a new user can have their
 * access revoked and a new product can be unpublished, even when the bot has
 * no one-step restoration for its creation. Likewise, restoring an order's
 * status cannot recall email already delivered by WooCommerce.
 */
final class SiteAgentReversibility
{
    /**
     * Accept either a proposal's input or a persisted plan so the same guard
     * can run before confirmation and immediately before execution. Persisted
     * proposals from before this policy must not bypass it.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function reasonFor(string $operation, array $fields = []): ?string
    {
        return match (self::operation($operation)) {
            SiteAgentRequest::OP_MEDIA_DELETE => 'מחיקה סופית של קובץ מדיה אינה אפשרית דרך הבוט, כי אי אפשר לשחזר את הקובץ. אפשר להסיר אותו מהעמוד או להחליף אותו.',
            SiteAgentRequest::OP_SUBSCRIPTION_STATUS => self::targets($fields, ['cancelled'])
                ? 'ביטול מיידי של מנוי הוא סופי ולכן אינו אפשרי דרך הבוט. אפשר להשהות את המנוי או לקבוע ביטול בסוף התקופה.'
                : null,
            SiteAgentRequest::OP_ORDER_NOTE => self::customerNote($fields)
                ? 'אי אפשר לשלוח מכאן הערה ללקוח באימייל, כי הודעה שנשלחה אינה ניתנת להחזרה. אפשר להוסיף הערה פנימית להזמנה.'
                : null,
            SiteAgentRequest::OP_PLUGIN_UPDATE, SiteAgentRequest::OP_THEME_UPDATE => 'עדכון תוסף או תבנית דרך הבוט דורש גיבוי שניתן לשחזר של הקבצים ושל מסד הנתונים. כרגע אין לבוט מנגנון שחזור מאומת כזה, ולכן העדכון נעשה בניהול האתר לאחר בדיקת הגיבוי.',
            SiteAgentRequest::OP_MENU_REMOVE => 'הסרת פריט תפריט במנגנון הקיים מוחקת אותו לצמיתות, ולכן אינה אפשרית דרך הבוט עד שניתן יהיה לשחזר אותו. אפשר לערוך את הפריט או לשנות את הסדר שלו.',
            'delete_content', 'delete_product', 'delete_user', 'delete_cct', 'delete_cct_item' => 'מחיקה סופית של נתונים אינה אפשרית דרך הבוט. אפשר להעביר לפח או לטיוטה במקום, אם סוג הפריט מאפשר זאת.',
            SiteAgentRequest::OP_COMMENT => self::targets($fields, ['delete', 'deleted'])
                ? 'מחיקה סופית של תגובה אינה אפשרית דרך הבוט. אפשר להעביר אותה לפח.'
                : null,
            default => null,
        };
    }

    /**
     * Disclose effects outside the reversible state itself. Do not describe
     * these operations as completely reversible just because state has undo.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function sideEffectNoticeFor(string $operation, array $fields = []): ?string
    {
        return match (self::operation($operation)) {
            SiteAgentRequest::OP_ORDER_STATUS => 'שינוי סטטוס ההזמנה עשוי לשלוח הודעות ולהפעיל תהליכים בחנות. החזרת הסטטוס אינה מבטלת הודעות שכבר נשלחו או פעולות שכבר בוצעו.',
            SiteAgentRequest::OP_USER_CREATE => 'אפשר לשנות או להסיר את הרשאות המשתמש בהמשך. אימייל לקביעת סיסמה שכבר נשלח אינו ניתן להחזרה, ואין ביטול אוטומטי ליצירת המשתמש.',
            SiteAgentRequest::OP_SUBSCRIPTION_STATUS => self::targets($fields, ['pending-cancel'])
                ? 'אפשר לבטל את בקשת הביטול כל עוד המנוי לא הסתיים. אחרי סיום התקופה הביטול נעשה סופי.'
                : 'שינוי מצב המנוי אינו מבטל הודעות שכבר נשלחו או חיובים שכבר בוצעו.',
            default => null,
        };
    }

    /** Normalize tool names without accepting arbitrary tools as authorized. */
    private static function operation(string $operation): string
    {
        return match ($operation) {
            'propose_media_delete', 'wp_media_delete' => SiteAgentRequest::OP_MEDIA_DELETE,
            'propose_subscription_status', 'wcs_subscription_status_set' => SiteAgentRequest::OP_SUBSCRIPTION_STATUS,
            'propose_order_note', 'wc_order_note_add' => SiteAgentRequest::OP_ORDER_NOTE,
            'propose_order_status', 'wc_order_status_set' => SiteAgentRequest::OP_ORDER_STATUS,
            'propose_user_create', 'wp_user_create' => SiteAgentRequest::OP_USER_CREATE,
            'propose_plugin_update', 'wp_plugin_update' => SiteAgentRequest::OP_PLUGIN_UPDATE,
            'propose_theme_update', 'wp_theme_update' => SiteAgentRequest::OP_THEME_UPDATE,
            'propose_menu_item_remove', 'wp_menu_item_unlink' => SiteAgentRequest::OP_MENU_REMOVE,
            'propose_comment_moderation', 'wp_comment_moderate' => SiteAgentRequest::OP_COMMENT,
            'wp_content_delete' => 'delete_content',
            'wc_product_delete' => 'delete_product',
            'wp_user_delete' => 'delete_user',
            'jet_cct_delete' => 'delete_cct',
            default => $operation,
        };
    }

    /** @param array<string, mixed> $fields */
    private static function customerNote(array $fields): bool
    {
        // Both names occur: proposals/plans use to_customer; the plugin tool
        // uses customer_note. A contradictory pair must not conceal an email.
        return filter_var($fields['to_customer'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($fields['customer_note'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $statuses
     */
    private static function targets(array $fields, array $statuses): bool
    {
        foreach (['to', 'status'] as $key) {
            if (! is_string($fields[$key] ?? null)) {
                continue;
            }

            $status = strtolower(trim($fields[$key]));
            $status = str_starts_with($status, 'wc-') ? substr($status, 3) : $status;

            if (in_array($status, $statuses, true)) {
                return true;
            }
        }

        return false;
    }
}
