<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use Illuminate\Support\Facades\Log;

/**
 * The first message after a number is verified, and a tip a week for the
 * first month.
 *
 * A new owner looking at an empty chat does not know what to ask, and a bot
 * nobody asks anything is a subscription that gets cancelled. So the welcome
 * gives five things to try that fit THIS site — a shop is asked about orders
 * and prices, a site without one about leads and pages — and the tips teach
 * one more thing each week.
 *
 * Tips ride on a conversation the owner already started: one is sent right
 * after a reply, when the WhatsApp window is open and free text is allowed,
 * never as an unprompted message that would need a paid template. Not billed:
 * it is the product explaining itself.
 */
class SiteAgentWelcome
{
    /** Days after verification during which tips are offered. */
    private const TIP_DAYS = 35;

    /** Days between two tips. */
    private const TIP_INTERVAL_DAYS = 7;

    /** The welcome, fitted to what this site is. */
    public function message(SiteAgentSubscriber $subscriber): string
    {
        $site = $subscriber->site;
        $domain = (string) $site?->domain;

        return implode("\n", [
            '✅ המספר אומת.',
            '',
            "מעכשיו מנהלים מכאן את האתר {$domain} — פשוט כותבים לי, כמו לעובד. כמה דברים לנסות:",
            '',
            ...array_map(fn (string $example): string => "• {$example}", $this->examples($site)),
            '',
            'כל שינוי אני מציג לפני הביצוע, והוא קורה רק אחרי שתאשרו. ואם משהו לא נראה טוב — כתבו "בטל".',
            'ולכל רגע: "מה אתה יודע לעשות?"',
        ]);
    }

    /**
     * Send this week's tip, if one is due — once, claimed before sending so
     * two replies at the same moment do not both send it.
     */
    public function sendTipIfDue(WhatsAppCloudClient $whatsapp, SiteAgentSubscriber $subscriber): void
    {
        $tips = $this->tips($subscriber->site);
        $sent = (int) $subscriber->tips_sent;

        if (! $this->tipDue($subscriber, count($tips))) {
            return;
        }

        $claimed = SiteAgentSubscriber::query()
            ->whereKey($subscriber->id)
            ->where('tips_sent', $sent)
            ->update(['tips_sent' => $sent + 1, 'last_tip_at' => now()]) === 1;

        if (! $claimed) {
            return;
        }

        if ($whatsapp->sendText($subscriber->phone, '💡 טיפ השבוע: '.$tips[$sent]) === null) {
            // Not delivered: give it back, the next reply tries again.
            SiteAgentSubscriber::query()->whereKey($subscriber->id)
                ->update(['tips_sent' => $sent, 'last_tip_at' => $subscriber->last_tip_at]);
            Log::info('SiteAgentWelcome: tip not delivered', ['subscriber_id' => $subscriber->id]);
        }
    }

    public function tipDue(SiteAgentSubscriber $subscriber, int $available): bool
    {
        $verified = $subscriber->verified_at;

        if ($verified === null || (int) $subscriber->tips_sent >= $available
            || $verified->lt(now()->subDays(self::TIP_DAYS))) {
            return false;
        }

        $since = $subscriber->last_tip_at ?? $verified;

        return $since->lte(now()->subDays(self::TIP_INTERVAL_DAYS));
    }

    /** @return list<string> */
    private function examples(?Site $site): array
    {
        if ($this->isShop($site)) {
            return [
                '"כמה הזמנות היו היום?"',
                '"תוריד את המחיר של החולצה הכחולה ל-99"',
                '"תעלה מוצר חדש: כד קרמיקה, 120 ש״ח" — או שלחו תמונה עם הכיתוב הזה',
                '"תסמן את הזמנה 1052 כהושלמה"',
                '"תשלח לי כל בוקר דוח מכירות של אתמול"',
            ];
        }

        return [
            '"מי השאיר פרטים השבוע?"',
            '"תודיע לי על כל ליד חדש"',
            '"בעמוד צור קשר, תחליף את הטלפון ל-03-7654321"',
            '"יש תגובות שמחכות לאישור?"',
            '"תשלח לי כל יום ראשון סיכום שבועי"',
        ];
    }

    /** @return list<string> */
    private function tips(?Site $site): array
    {
        return array_values(array_filter([
            'אפשר לשלוח לי תמונה עם כיתוב — "לעמוד הבית" או "למוצר X" — והיא עולה לאתר, עם תיאור נגיש.',
            $this->isShop($site)
                ? 'דוח קבוע חוסך את השאלה כל בוקר: "תשלח לי כל בוקר בשמונה את המכירות והלידים של אתמול".'
                : 'דוח קבוע חוסך את השאלה: "תשלח לי כל יום ראשון את הלידים של השבוע".',
            'כל שינוי אפשר להחזיר: כתבו "בטל" אחרי הביצוע, ואחזיר את מה שהיה.',
            'אפשר לשאול אותי על החשבון: "כמה הודעות שלחתי החודש?" או "מתי החיוב הבא?" — ולקבוע תקרה: "תקרה 500".',
        ]));
    }

    /** A WooCommerce shop, as far as the plugin reported its tools. */
    private function isShop(?Site $site): bool
    {
        $tools = collect((array) data_get($site?->mcp_capabilities, 'tools', []))->pluck('name')->filter();

        return $tools->contains('wc_order_list');
    }
}
