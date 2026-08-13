<?php

namespace App\Services\Support;

use App\Enums\WebhookSource;
use App\Models\NotificationLog;
use App\Models\WebhookEvent;
use App\Support\WebhookRejections;
use Illuminate\Support\Carbon;

/**
 * Why the open figures are empty.
 *
 * "No opens" is the same screen for at least five different faults, and each
 * has a different fix:
 *
 *   1. mail is not going through Postmark at all;
 *   2. Postmark calls us and we refuse it — the address is missing the secret;
 *   3. Postmark never calls us — the webhook is on a different server or stream,
 *      or was never saved;
 *   4. Postmark calls us with deliveries and bounces but never an Open — open
 *      tracking is off for the stream the broadcast actually went through;
 *   5. Opens arrive and match no message of ours — the id we stored is not the
 *      id Postmark reports.
 *
 * Postmark's own "check" button proves none of this: it posts a sample event to
 * the address and any 200 satisfies it. Ours returns 200 for an event that
 * matched nothing, because refusing it would make the provider retry an event
 * there is nothing to do with.
 *
 * So this reads the trail we already keep — every accepted call is a row in
 * webhook_events — and names the broken link.
 */
class DeliveryTrackingDiagnosis
{
    /** How far back to look. Long enough to cover a monthly send. */
    private const DAYS = 45;

    /** Opens examined for a match. Bounded: this is a diagnosis, not a report. */
    private const SAMPLE = 300;

    /**
     * @return array{configured: bool, since: Carbon, events: array<string, int>, total: int,
     *     lastEventAt: ?Carbon, rejectedAt: ?Carbon, opens: int, openMatched: int,
     *     sent: int, tracked: int, verdict: string, fix: string}
     */
    public function run(): array
    {
        $since = now()->subDays(self::DAYS);
        $events = $this->eventCounts($since);
        $opens = $this->openMatching($since);
        $sent = $this->sentCounts($since);

        $report = [
            'configured' => $this->sendsThroughPostmark(),
            'since' => $since,
            'events' => $events,
            'total' => array_sum($events),
            'lastEventAt' => $this->lastEventAt($since),
            'rejectedAt' => WebhookRejections::lastAt('email.delivery'),
            'opens' => $opens['seen'],
            'openMatched' => $opens['matched'],
            'sent' => $sent['sent'],
            'tracked' => $sent['tracked'],
        ];

        return $report + $this->verdict($report);
    }

    /**
     * The one sentence that says which link is broken, and the one that says
     * what to do about it. Ordered from the outside in: there is no point
     * discussing open tracking with somebody whose webhook never arrives.
     *
     * @param  array<string, mixed>  $report
     * @return array{verdict: string, fix: string}
     */
    private function verdict(array $report): array
    {
        if (! $report['configured']) {
            return [
                'verdict' => 'המערכת אינה שולחת מייל דרך Postmark, ולכן לא יגיעו ממנה דיווחי פתיחה.',
                'fix' => 'הגדרות ← מייל: הזינו Server Token של Postmark.',
            ];
        }

        if ($report['total'] === 0 && $report['rejectedAt'] !== null) {
            return [
                'verdict' => 'Postmark פונה אלינו וכל פנייה נדחית — הכתובת שהוגדרה שם חסרה את הסוד או שהוא שגוי.',
                'fix' => 'העתיקו את הכתובת המלאה מהגדרות ← מייל, כולל החלק שאחרי ?secret=, והדביקו אותה ב-Postmark במקום זו שיש שם.',
            ];
        }

        if ($report['total'] === 0) {
            return [
                'verdict' => 'Postmark מעולם לא פנה לכתובת הזו. שום אירוע לא התקבל — גם לא מסירה או החזרה.',
                'fix' => 'ודאו שהוובהוק מוגדר על אותו Server ואותו Message Stream שדרכו נשלח הדיוור בפועל, '
                    .'ושהוא מסוג Delivery/Bounce/Spam Complaint/Open. "Check" ב-Postmark מאשר את הכתובת בלבד ולא את ההגדרה.',
            ];
        }

        if (($report['events']['Open'] ?? 0) === 0) {
            return [
                'verdict' => 'אירועים מ-Postmark כן מגיעים ('.$report['total'].' בתקופה), אבל אין ביניהם אף פתיחה. '
                    .'זה אומר ש-Postmark אינו מודד פתיחות במסלול שדרכו נשלח הדיוור.',
                'fix' => 'ב-Postmark: Open Tracking חייב להיות פעיל על אותו Message Stream שדרכו יוצא הדיוור, '
                    .'ובוובהוק עצמו חייב להיות מסומן גם האירוע Open — הוא אינו נכלל ב-Delivery.',
            ];
        }

        if ($report['openMatched'] === 0) {
            return [
                'verdict' => 'פתיחות מגיעות מ-Postmark ('.$report['opens'].' בתקופה) אך אף אחת אינה משויכת להודעה שלנו — '
                    .'המזהה ששמור אצלנו אינו זהה למזהה שמדווח. '
                    .$report['tracked'].' מתוך '.$report['sent'].' הודעות דיוור נשמרו עם מזהה.',
                'fix' => 'זו תקלה אצלנו ולא בהגדרות — שלחו את השורה הזו לפיתוח.',
            ];
        }

        return [
            'verdict' => 'המדידה עובדת: '.$report['openMatched'].' מתוך '.$report['opens'].' הפתיחות שהגיעו שויכו להודעות שלנו.',
            'fix' => 'אם המספרים למעלה עדיין ריקים — הפתיחות ישנות מחלון המדידה, או שייכות להודעות שאינן דיוור.',
        ];
    }

    /** Do we even send through the provider that reports these events. */
    private function sendsThroughPostmark(): bool
    {
        return config('mail.default') === 'postmark' && filled(config('services.postmark.token'));
    }

    /**
     * Accepted calls by record type. Read off the stored type rather than the
     * payload so a malformed body still counts as "something arrived".
     *
     * @return array<string, int>
     */
    private function eventCounts(Carbon $since): array
    {
        $counts = [];

        WebhookEvent::query()
            ->where('source', WebhookSource::Email)
            ->where('event_type', 'like', 'delivery_%')
            ->where('created_at', '>=', $since)
            ->selectRaw('event_type, COUNT(*) AS total')
            ->groupBy('event_type')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[$this->recordType((string) $row->event_type)] = (int) $row->total;
            });

        ksort($counts);

        return $counts;
    }

    /** `delivery_spamcomplaint` back to the name Postmark uses. */
    private function recordType(string $eventType): string
    {
        return match (substr($eventType, strlen('delivery_'))) {
            'delivery' => 'Delivery',
            'open' => 'Open',
            'bounce' => 'Bounce',
            'spamcomplaint' => 'SpamComplaint',
            'subscriptionchange' => 'SubscriptionChange',
            'click' => 'Click',
            default => substr($eventType, strlen('delivery_')),
        };
    }

    private function lastEventAt(Carbon $since): ?Carbon
    {
        $at = WebhookEvent::query()
            ->where('source', WebhookSource::Email)
            ->where('event_type', 'like', 'delivery_%')
            ->where('created_at', '>=', $since)
            ->max('created_at');

        return filled($at) ? Carbon::parse($at) : null;
    }

    /**
     * How many of the opens that arrived belong to a message we can name.
     *
     * Matched in PHP rather than in SQL: the id lives inside the stored payload,
     * and reaching into a JSON column is written differently on Postgres and on
     * SQLite. A few hundred rows is nothing, and this runs when somebody opens a
     * diagnosis screen.
     *
     * @return array{seen: int, matched: int}
     */
    private function openMatching(Carbon $since): array
    {
        $opens = WebhookEvent::query()
            ->where('source', WebhookSource::Email)
            ->where('event_type', 'delivery_open')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(self::SAMPLE)
            ->pluck('payload');

        $ids = $opens
            ->map(fn ($payload): string => trim((string) (($payload['MessageID'] ?? ''))))
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return ['seen' => $opens->count(), 'matched' => 0];
        }

        $known = NotificationLog::query()
            ->whereIn('provider_message_id', $ids->all())
            ->pluck('provider_message_id')
            ->all();

        return ['seen' => $opens->count(), 'matched' => count($known)];
    }

    /**
     * Broadcast emails sent in the window, and how many of them carry the
     * provider id an open is matched by. A gap between the two is the fault
     * itself: those messages could never be credited with an open.
     *
     * @return array{sent: int, tracked: int}
     */
    private function sentCounts(Carbon $since): array
    {
        $base = NotificationLog::query()
            ->where('channel', 'email')
            ->whereNotNull('broadcast_id')
            ->where('created_at', '>=', $since);

        return [
            'sent' => (clone $base)->count(),
            'tracked' => (clone $base)->whereNotNull('provider_message_id')->count(),
        ];
    }
}
