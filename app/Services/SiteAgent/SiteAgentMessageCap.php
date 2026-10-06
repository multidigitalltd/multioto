<?php

namespace App\Services\SiteAgent;

use App\Models\Subscription;

/**
 * The ceiling a customer puts on their own message bill.
 *
 * Messages are billed one by one, and an owner who chats with the bot all
 * month should never open an invoice they did not expect. So they may set
 * the most messages per billing cycle they are willing to pay for. At 80%
 * they are told once; at the ceiling the bot stops sending — replies,
 * reports and lead alerts alike — until the next renewal, and nothing above
 * the ceiling is ever billed.
 *
 * Raising or removing the ceiling works even when it has been reached, with
 * a plain "תקרה 800" / "בטל תקרה" that is handled here, without the AI and
 * without being billed — otherwise the one message that would unblock them
 * is the one the ceiling refuses.
 */
class SiteAgentMessageCap
{
    /** The highest ceiling accepted — far beyond any real month, short of a typo's worth of zeros. */
    public const MAX = 100000;

    public function __construct(private SiteAgentUsageMeter $usage) {}

    /**
     * A ceiling command in the owner's words, or null when the text is not one.
     *
     * @return array{cap: int|null}|null cap null = remove the ceiling
     */
    public function command(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/^(?:בטל|הסר|תבטל|תסיר|בלי|ללא)\s+(?:את\s+)?(?:ה)?תקרה$/u', $text) === 1) {
            return ['cap' => null];
        }

        if (preg_match('/^(?:(?:ת|ה)?עלה\s+(?:את\s+)?)?(?:ה)?תקרה(?:\s+(?:ל|ל-|ל־))?\s*(\d{1,6})(?:\s+הודעות)?$/u', $text, $match) === 1) {
            return ['cap' => (int) $match[1]];
        }

        return null;
    }

    /**
     * Set or remove the ceiling.
     *
     * @return string|null why it was refused
     */
    public function set(Subscription $subscription, ?int $cap): ?string
    {
        if ($cap !== null && ($cap < 1 || $cap > self::MAX)) {
            return 'תקרה צריכה להיות מספר הודעות בין 1 ל-'.number_format(self::MAX).'.';
        }

        // A new ceiling is a new 80% mark: the notice may be due again.
        $subscription->forceFill([
            'site_agent_message_cap' => $cap,
            'site_agent_cap_warned_at' => null,
        ])->save();

        return null;
    }

    /** What the owner is told about the ceiling they just set. */
    public function confirmation(Subscription $subscription): string
    {
        $cap = $subscription->site_agent_message_cap;
        $used = $this->usage->unbilled($subscription, now());

        return $cap === null
            ? 'התקרה הוסרה — הבוט ימשיך לענות בלי הגבלה.'
            : 'התקרה נקבעה ל-'.number_format($cap).' הודעות במחזור. עד עכשיו נשלחו '.number_format($used)
                .($used >= $cap ? '. התקרה כבר מלאה, ולכן הבוט ימתין לחידוש הבא — או העלו אותה.' : '.');
    }

    /** The answer to anything the owner sends while the ceiling is reached. */
    public function reachedNotice(Subscription $subscription): string
    {
        $cap = (int) $subscription->site_agent_message_cap;
        $next = $subscription->next_charge_at?->format('d/m/Y');

        return 'הגעתם לתקרה שהגדרתם: '.number_format($cap).' הודעות במחזור הזה, ולכן הבוט לא שולח עוד הודעות'
            .($next !== null ? " עד החידוש ב-{$next}" : '').'.'
            ."\nלהעלאת התקרה כתבו למשל \"תקרה ".number_format($cap * 2, 0, '', '').'", ולהסרה — "בטל תקרה". ההודעה הזאת לא מחויבת.';
    }

    /**
     * Send the once-a-cycle 80% notice if it is due — after any billed
     * message, a reply, a report or a lead alert alike. Not billed itself.
     */
    public function warnIfDue(WhatsAppCloudClient $whatsapp, string $to, ?Subscription $subscription): void
    {
        if ($subscription === null || ! $this->usage->claimWarning($subscription)) {
            return;
        }

        if ($whatsapp->sendText($to, $this->warning($subscription)) === null) {
            $this->usage->releaseWarning($subscription);
        }
    }

    /** The once-a-cycle notice at 80%. */
    public function warning(Subscription $subscription): string
    {
        $cap = (int) $subscription->site_agent_message_cap;
        $used = $this->usage->unbilled($subscription, now());

        return '⚠️ נשלחו '.number_format($used).' מתוך '.number_format($cap).' ההודעות שהגדרתם למחזור הזה. '
            .'בתקרה הבוט יפסיק לשלוח עד החידוש. להעלאה כתבו "תקרה" ומספר.';
    }
}
