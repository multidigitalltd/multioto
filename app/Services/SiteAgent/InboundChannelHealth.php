<?php

namespace App\Services\SiteAgent;

use App\Enums\WebhookSource;
use App\Models\Setting;
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

    /**
     * The durable "this channel has carried a message" marker.
     *
     * Stored, not cached, and deliberately absent from SettingsServiceProvider's
     * allow-list: that map is what the settings page may override in config, and
     * this is a recorded fact, not a setting anybody edits.
     */
    private const SEEN_KEY = 'siteagent.inbound_seen_at';

    public function __construct(private readonly SiteAgentProduct $product) {}

    /**
     * @return array{accepted: ?Carbon, rejected: ?Carbon, everCarried: bool, verdict: 'unready'|'rejected'|'ok'|'silent'}
     */
    public function read(): array
    {
        $accepted = $this->lastAccepted();
        $rejected = WebhookRejections::lastAt(self::CHANNEL);
        $everCarried = $accepted !== null || $this->remembered() || $this->everVerifiedByReply();

        if ($accepted !== null) {
            $this->remember();
        }

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
     * The durable fact: this channel has carried a real inbound message.
     *
     * `webhook_events` is pruned (60 days by default), so its absence does not
     * mean "never" — and reading it as "never" would turn every channel that
     * worked and then had a quiet season into a daily alert, which is precisely
     * the alert a team learns to skip.
     *
     * So the fact is written down once, the first time it is observed, in a row
     * nothing prunes and nothing else rewrites. Written from the read because
     * the hourly watch is what observes it, and the write is idempotent: it is
     * one sentence that only ever goes from unknown to true.
     */
    private function remembered(): bool
    {
        return rescue(
            fn (): bool => filled(Setting::map()[self::SEEN_KEY] ?? null),
            false,
            report: false,
        );
    }

    private function remember(): void
    {
        if ($this->remembered()) {
            return;
        }

        rescue(
            fn () => Setting::put(self::SEEN_KEY, now()->toIso8601String()),
            report: false,
        );
    }

    /**
     * The same fact for installations that predate the marker.
     *
     * An upgrade arrives with the marker unwritten and possibly with the audit
     * rows already pruned, so there has to be a second way to recognise a
     * channel that has worked. A verified subscriber is one: `verified_at` is
     * written in one place only, HandleSiteAgentMessageJob, which runs solely
     * from an accepted delivery — somebody replied with their code over
     * WhatsApp, and that reply reached us. Revoked rows count too, since the
     * question is historical.
     *
     * Not sufficient on its own, which is why it is not the marker: the model
     * clears `verified_at` whenever the phone is edited (deliberately — proof
     * about one number must not carry to another), so this can go from true
     * back to false.
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
