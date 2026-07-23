<?php

namespace App\Services\Customers;

use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\NotificationLog;

/**
 * צ'ק-ליסט קליטת לקוח: אילו שלבי-קליטה כבר בוצעו. כל פריט מזוהה אוטומטית
 * מהנתונים כשאפשר (אתר מקושר, מנוי פעיל, כרטיס שמור, תוסף מחובר, ניטור,
 * מייל ברוכים הבאים, אישור תנאים), וכל פריט שאינו מזוהה אוטומטית ניתן לסימון
 * ידני — הסימונים הידניים נשמרים על הלקוח (onboarding_checklist).
 */
class OnboardingChecklist
{
    /** key => [label, hint] — the checklist definition, in display order. */
    public const ITEMS = [
        'terms_signed' => ['אישור תנאים וחתימה', 'הלקוח אישר את התנאים (בטופס ההצטרפות או ידנית).'],
        'site_linked' => ['אתר מקושר', 'לפחות אתר אחד משויך ללקוח.'],
        'subscription_active' => ['מנוי פעיל', 'ללקוח מנוי בסטטוס פעיל או בניסיון.'],
        'card_captured' => ['אמצעי תשלום', 'כרטיס שמור בקארדקום (או הסדר תשלום אחר — סמנו ידנית).'],
        'plugin_connected' => ['תוסף מחובר', 'תוסף האתר התחבר לפחות פעם אחת (חיבור AI).'],
        'monitoring_on' => ['ניטור פעיל', 'לפחות אתר אחד של הלקוח מנוטר.'],
        'welcome_sent' => ['נשלח "ברוכים הבאים"', 'הודעת הפתיחה נשלחה ללקוח.'],
    ];

    /**
     * The checklist for one customer: each item with its effective state.
     *
     * @return list<array{key: string, label: string, hint: string, done: bool, auto: bool, manual: bool}>
     */
    public function items(Customer $customer): array
    {
        $auto = $this->autoDetected($customer);
        $manual = (array) ($customer->onboarding_checklist ?? []);

        return collect(self::ITEMS)->map(function (array $meta, string $key) use ($auto, $manual): array {
            $isAuto = (bool) ($auto[$key] ?? false);
            $isManual = (bool) data_get($manual, "{$key}.done", false);

            return [
                'key' => $key,
                'label' => $meta[0],
                'hint' => $meta[1],
                'done' => $isAuto || $isManual,
                'auto' => $isAuto,
                'manual' => $isManual,
            ];
        })->values()->all();
    }

    /** @return array{done: int, total: int} */
    public function progress(Customer $customer): array
    {
        $items = $this->items($customer);

        return [
            'done' => count(array_filter($items, fn (array $i): bool => $i['done'])),
            'total' => count($items),
        ];
    }

    /**
     * Flip a manual tick. Only items from the definition are accepted, and an
     * auto-detected item can't be manually un-done (the data says it's done).
     */
    public function toggle(Customer $customer, string $key): void
    {
        if (! array_key_exists($key, self::ITEMS)) {
            return;
        }

        $manual = (array) ($customer->onboarding_checklist ?? []);
        $now = ! (bool) data_get($manual, "{$key}.done", false);

        $manual[$key] = ['done' => $now, 'at' => now()->toIso8601String()];

        $customer->update(['onboarding_checklist' => $manual]);
    }

    /** @return array<string, bool> */
    protected function autoDetected(Customer $customer): array
    {
        return [
            'terms_signed' => $customer->terms_accepted_at !== null,
            'site_linked' => $customer->sites()->exists(),
            'subscription_active' => $customer->subscriptions()
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
                ->exists(),
            'card_captured' => $customer->default_token_id !== null,
            'plugin_connected' => $customer->sites()->whereNotNull('mcp_last_seen_at')->exists(),
            'monitoring_on' => $customer->sites()->where('monitor_enabled', true)->exists(),
            'welcome_sent' => NotificationLog::query()
                ->where('customer_id', $customer->id)
                ->where('type', NotificationType::Welcome)
                ->exists(),
        ];
    }
}
