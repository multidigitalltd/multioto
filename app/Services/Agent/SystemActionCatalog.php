<?php

namespace App\Services\Agent;

/**
 * The catalog of internal system operations the AI agent may PROPOSE from the
 * command console — the single source of truth shared by the interpreter (to
 * understand an instruction and know which parameters it still needs) and the
 * runner (to validate and execute an approved action). Adding a new operation
 * is a single entry here plus a handler in SystemActionRunner.
 *
 * Nothing here executes on its own: every operation flows through the approval
 * gate, and the money-touching ones also wait on the system-actions kill-switch.
 */
class SystemActionCatalog
{
    /**
     * key => [label, category, needs (required params), desc (for the AI prompt)].
     *
     * @var array<string, array{label: string, category: string, needs: list<string>, desc: string}>
     */
    public const OPERATIONS = [
        'open_task' => [
            'label' => 'פתיחת משימה',
            'category' => 'משימות',
            'needs' => ['title'],
            'desc' => 'פתח משימה חדשה לצוות. פרמטרים: title (כותרת המשימה), customer_name (לקוח קשור — אופציונלי).',
        ],
        'send_payment_request' => [
            'label' => 'דרישת תשלום / לינק תשלום',
            'category' => 'גבייה',
            'needs' => ['customer_name', 'amount_ils', 'description'],
            'desc' => 'שלח ללקוח דרישת תשלום עם לינק לתשלום. פרמטרים: customer_name, amount_ils (סכום בשקלים, מספר), description (על מה החיוב).',
        ],
        'mark_collected' => [
            'label' => 'סימון תשלום בוצע + חשבונית',
            'category' => 'גבייה',
            'needs' => ['customer_name'],
            'desc' => 'סמן שמנוי בגבייה ידנית (העברה/הו״ק) שולם עבור התקופה — מפיק חשבונית ומגלגל לתקופה הבאה. פרמטר: customer_name.',
        ],
        'suspend_site' => [
            'label' => 'השעיית אתר',
            'category' => 'אתרים',
            'needs' => ['site'],
            'desc' => 'השעה אתר של לקוח. פרמטר: site_domain (דומיין) או customer_name.',
        ],
        'restore_site' => [
            'label' => 'שחזור אתר מהשעיה',
            'category' => 'אתרים',
            'needs' => ['site'],
            'desc' => 'החזר אתר מושעה לפעילות. פרמטר: site_domain (דומיין) או customer_name.',
        ],
    ];

    /** Whether a money-touching operation (needs the extra kill-switch + care). */
    public const FINANCIAL = ['send_payment_request', 'mark_collected'];

    public static function has(string $operation): bool
    {
        return array_key_exists($operation, self::OPERATIONS);
    }

    public static function label(string $operation): string
    {
        return self::OPERATIONS[$operation]['label'] ?? $operation;
    }

    /** A compact bullet list of operations for the classifier prompt. */
    public static function promptList(): string
    {
        return collect(self::OPERATIONS)
            ->map(fn (array $meta, string $key): string => "- \"{$key}\": {$meta['desc']}")
            ->implode("\n");
    }
}
