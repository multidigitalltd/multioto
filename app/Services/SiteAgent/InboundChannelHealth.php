<?php

namespace App\Services\SiteAgent;

use App\Enums\WebhookSource;
use App\Models\SiteAgentSubscriber;
use App\Models\WebhookEvent;
use App\Support\WebhookRejections;
use Illuminate\Support\Carbon;

/**
 * האם הודעה של לקוח בכלל מגיעה אלינו.
 *
 * זה הכישלון היחיד במוצר שאינו מייצר שגיאה בשום מקום: הלקוח כותב, לא קורה כלום,
 * ואין שורה באף יומן. מבחינת המערכת פשוט לא נשלחה הודעה.
 *
 * ההבחנה היא כל הערך, כי לשתי השתיקות שנראות זהות מבחוץ יש תיקונים שונים לגמרי:
 *
 *  - **מסירות נדחות** — סוד האפליקציה כאן אינו זהה לזה שבמטא. שדה אחד, בפאנל.
 *  - **מעולם לא הגיע דבר** — שום דבר לא הגיע עד הדלת. הבעיה אצל מטא.
 *
 * סוד האפליקציה הוא גם הערך היחיד ששום דבר אחר אינו מאמת: טוקן האימות מוכח
 * בלחיצת Verify של מטא, המספר והטוקן בשליחה הראשונה — והסוד רק במסירה נכנסת
 * אמיתית. כלומר הוא הכי סביר להיות שגוי בזמן שהכול נראה תקין.
 *
 * הקריאה יושבת במקום אחד כדי שהמסך בפאנל והניטור השעתי יגידו תמיד את אותו דבר.
 * מסך שעונה "תקין" בזמן שההתראה אומרת "דחוף" הוא מסך שלא בודקים יותר.
 */
class InboundChannelHealth
{
    /** The rejection counter the inbound controller writes to. */
    public const CHANNEL = 'site-agent-whatsapp';

    public function __construct(private readonly SiteAgentProduct $product) {}

    /**
     * @return array{accepted: ?Carbon, rejected: ?Carbon, everCarried: bool, verdict: 'unready'|'rejected'|'ok'|'silent'}
     */
    public function read(): array
    {
        $accepted = $this->lastAccepted();
        $rejected = WebhookRejections::lastAt(self::CHANNEL);
        $everCarried = $accepted !== null || $this->everVerifiedByReply();

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'everCarried' => $everCarried,
            'verdict' => match (true) {
                // Half-configured or switched off: the readiness section already
                // says what is missing, and "nothing reached the door, so the
                // problem is not here" would send the admin to Meta to look for
                // a field that is blank on this very screen.
                ! $this->product->ready() => 'unready',

                // A rejection NEWER than the last accepted delivery. Also the
                // case when the accepted row has since been pruned: the
                // rejection marker lives 30 days, the audit rows 60, so a
                // rejection with no surviving acceptance is the recent event.
                $rejected !== null && ($accepted === null || $rejected->gt($accepted)) => 'rejected',

                $everCarried => 'ok',

                default => 'silent',
            },
        ];
    }

    /**
     * When Meta last delivered something we accepted and recorded.
     *
     * Only ever as old as the webhook audit retention — see everCarried() for
     * the question this one cannot answer.
     */
    private function lastAccepted(): ?Carbon
    {
        return rescue(
            fn (): ?Carbon => WebhookEvent::query()
                ->where('source', WebhookSource::WhatsappCloud)
                ->latest('created_at')
                ->value('created_at'),
            null,
            report: false,
        );
    }

    /**
     * Durable proof that the channel has carried a real inbound message.
     *
     * `webhook_events` is pruned (60 days by default), so its absence does not
     * mean "never" — and reading it as "never" would turn every channel that
     * worked and then had a quiet season into a daily alert, which is precisely
     * the alert a team learns to skip.
     *
     * A verified subscriber is the unpruned answer: `verified_at` is written in
     * one place only, HandleSiteAgentMessageJob, which runs solely from an
     * accepted delivery. Somebody replied with their code over WhatsApp, and
     * that reply reached us. Revoked rows count too — the question is historical.
     */
    private function everVerifiedByReply(): bool
    {
        return rescue(
            fn (): bool => SiteAgentSubscriber::query()->whereNotNull('verified_at')->exists(),
            false,
            report: false,
        );
    }
}
