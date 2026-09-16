<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What one message means, given what is already waiting.
 *
 * The whole product is three moves — ask, confirm, undo — and the order they
 * are checked in is the design. A "כן" is read as an answer to the offer on the
 * table BEFORE it is read as a new instruction, because a customer typing yes
 * is answering a question, and treating it as a fresh request would send the
 * model off to plan an edit called "כן".
 */
class SiteAgentConversation
{
    /** Words that mean yes to the offer on the table. */
    private const YES = ['כן', 'אשר', 'אישור', 'מאשר', 'מאשרת', 'בצע', 'תבצע', 'אוקיי', 'אוקי', 'ok', 'yes', 'כן.', '👍'];

    /** Words that mean no. */
    private const NO = ['לא', 'ביטול', 'בטל', 'עזוב', 'לא תודה', 'no', 'cancel'];

    /** Words that ask to put the last change back. */
    private const UNDO = ['בטל', 'תבטל', 'תחזיר', 'החזר', 'שחזר', 'undo'];

    public function __construct(
        private SiteChangePlanner $planner,
        private SiteChangeApplier $applier,
    ) {}

    /**
     * Read the message and act.
     *
     * @return string the reply to send back
     */
    public function handle(SiteAgentSubscriber $subscriber, string $text, ?string $messageId): string
    {
        $text = trim($text);

        if ($text === '') {
            return 'לא הבנתי מה לשנות. כתבו לי מה תרצו לעדכן באתר.';
        }

        $pending = SiteAgentRequest::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->awaitingConfirmation()
            ->latest('id')
            ->first();

        if ($pending !== null) {
            if ($this->matches($text, self::YES)) {
                return $this->confirm($pending);
            }

            if ($this->matches($text, self::NO)) {
                $pending->update(['state' => SiteAgentRequest::CANCELED]);

                return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
            }

            // Anything else replaces the offer: they changed their mind about
            // what they want, and leaving the old one alive would let a "כן"
            // three messages later confirm something they have moved on from.
            $pending->update(['state' => SiteAgentRequest::CANCELED]);
        }

        // "בטל" with nothing on the table means the last thing that went live.
        if ($this->matches($text, self::UNDO)) {
            return $this->revertLast($subscriber);
        }

        return $this->propose($subscriber, $text, $messageId);
    }

    /**
     * Plan one change and show it, exactly.
     *
     * The preview is the contract: it names the page and quotes the text before
     * and after, so "כן" is consent to something specific rather than to the
     * agent's good intentions.
     */
    private function propose(SiteAgentSubscriber $subscriber, string $text, ?string $messageId): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        $plan = $this->planner->plan($site, $text);

        if ($plan === null) {
            return implode("\n", [
                'לא הצלחתי להבין בוודאות מה לשנות, ולכן לא נגעתי בכלום.',
                '',
                'עוזר לי אם תכתבו באיזה עמוד מדובר ומה בדיוק להחליף — למשל:',
                '"בעמוד צור קשר, תחליף את הטלפון 03-1234567 ב-03-7654321".',
            ]);
        }

        $minutes = max(1, (int) config('siteagent.confirmation_minutes', 30));

        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'customer_id' => $subscriber->customer_id,
            'message' => Str::limit($text, 2000),
            'inbound_message_id' => $messageId,
            'operation' => $plan['operation'],
            'plan' => $plan,
            'preview' => $this->preview($plan),
            'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return $request->preview."\n\n".'לביצוע השיבו "כן". לביטול — "לא".';
    }

    /**
     * The words the customer sees before they agree.
     *
     * Before and after are quoted in full rather than summarised. A summary is
     * where a wrong edit hides: "עדכון שעות הפתיחה" reads fine whether the new
     * text is right or nonsense.
     *
     * @param  array<string, mixed>  $plan
     */
    private function preview(array $plan): string
    {
        $page = (string) $plan['page_title'];

        return match ($plan['operation']) {
            SiteAgentRequest::OP_TITLE => implode("\n", [
                "📄 עמוד: {$page}",
                'שינוי הכותרת ל:',
                '"'.$plan['text'].'"',
            ]),
            SiteAgentRequest::OP_APPEND => implode("\n", [
                "📄 עמוד: {$page}",
                'להוסיף בסוף העמוד:',
                '"'.$plan['text'].'"',
            ]),
            default => implode("\n", [
                "📄 עמוד: {$page}",
                'להחליף את:',
                '"'.$plan['find'].'"',
                '',
                'ב:',
                '"'.$plan['text'].'"',
            ]),
        };
    }

    /** They said yes. Carry it out, and say plainly whether it worked. */
    private function confirm(SiteAgentRequest $request): string
    {
        $result = $this->applier->apply($request);

        if (! $result['ok']) {
            $request->update([
                'state' => SiteAgentRequest::FAILED,
                'failure_reason' => $result['reason'],
            ]);

            // The page moved under us between the preview and the yes.
            if ($result['reason'] === SiteChangeApplier::STALE) {
                return 'העמוד השתנה מאז שהצגתי לכם את השינוי, ולכן לא ביצעתי אותו — כדי לא למחוק עריכה של מישהו אחר. '
                    .'בקשו שוב ואציג הצעה מעודכנת.';
            }

            return 'לא הצלחתי לבצע את השינוי: '.$result['reason'];
        }

        $request->update([
            'state' => SiteAgentRequest::APPLIED,
            'restore' => $result['restore'],
            'applied_at' => now(),
        ]);

        SystemLog::record('info', 'site-agent',
            "שינוי באתר בוצע לבקשת הלקוח: {$request->plan['summary']}",
            ['request_id' => $request->id, 'site_id' => $request->site_id]);

        $window = max(1, (int) config('siteagent.undo_minutes', 1440));

        return '✅ בוצע.'."\n\n".'אם משהו לא נראה טוב — כתבו "בטל" ואחזיר לקדמותו (עד '
            .($window >= 60 ? intdiv($window, 60).' שעות' : $window.' דקות').').';
    }

    /** Put the last applied change back. */
    private function revertLast(SiteAgentSubscriber $subscriber): string
    {
        $last = SiteAgentRequest::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->revertable()
            ->latest('applied_at')
            ->first();

        if ($last === null) {
            return 'אין שינוי אחרון שאפשר להחזיר.';
        }

        // One undo per change. Without the lock, two "בטל" in quick succession
        // both read the same applied row and both write the old content back —
        // the second one over an edit the customer may have made in between.
        $lock = Cache::lock("site-agent:revert:{$last->id}", 30);

        if (! $lock->get()) {
            return 'אני כבר מחזיר את השינוי — רגע אחד.';
        }

        try {
            $last->refresh();

            if (! $last->isRevertable()) {
                return 'השינוי הזה כבר הוחזר.';
            }

            $result = $this->applier->revert($last);

            if (! $result['ok']) {
                return 'לא הצלחתי להחזיר את השינוי: '.$result['reason'];
            }

            $last->update(['state' => SiteAgentRequest::REVERTED, 'reverted_at' => now()]);

            return '↩️ הוחזר לקדמותו.';
        } finally {
            $lock->release();
        }
    }

    /**
     * Does the message mean one of these words, and nothing more?
     *
     * Exact match after trimming punctuation, never "contains". A message like
     * "לא, תחליף את זה במשהו אחר" contains "לא" and is plainly not a refusal —
     * reading it as one would throw away what they actually asked for.
     *
     * @param  list<string>  $words
     */
    private function matches(string $text, array $words): bool
    {
        $normalized = Str::lower(trim($text, " \t\n\r\0\x0B.!?,־-"));

        return in_array($normalized, array_map(fn (string $w): string => Str::lower(trim($w, ' .')), $words), true);
    }
}
