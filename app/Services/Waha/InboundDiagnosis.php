<?php

namespace App\Services\Waha;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\WebhookSource;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use App\Services\Automation\ApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answers one question: why is nothing arriving from WhatsApp?
 *
 * Outbound WhatsApp working proves the session is connected — and proves
 * nothing at all about inbound, which depends on a webhook registered on the
 * WAHA session pointing back at us. When it is missing, or points somewhere
 * unreachable, the panel simply stays quiet: no error, no ticket, no clue.
 * Silence is the failure mode, so the diagnosis has to be asked for explicitly.
 *
 * Every check is read-only and reported in plain Hebrew with the fix.
 */
class InboundDiagnosis
{
    public function __construct(private WahaClient $waha) {}

    /**
     * States that are a definite fault — someone must fix something. A quiet
     * week is NOT one of them: alerting on silence that might just be a slow
     * week is how an alert becomes noise, and then the real one is ignored too.
     */
    public const FAULTS = ['unreachable', 'not_registered', 'wrong_target', 'never_delivered', 'no_messages', 'not_processed', 'no_tickets', 'stalled'];

    /**
     * @return array{ok: bool, state: string, title: string, detail: string, variant: string}
     */
    public function run(): array
    {
        $ours = route('webhooks.waha');
        $last = $this->lastEvent();

        try {
            $registered = $this->registeredWebhooks();
        } catch (Throwable $e) {
            return $this->result(false, 'unreachable', 'לא ניתן לקרוא את הגדרות WAHA', 'החיבור לשרת WAHA נכשל: '.$this->short($e).' בדקו את כתובת השרת וה-API Key בסעיף הזה.');
        }

        if ($registered === []) {
            return $this->result(
                false,
                'not_registered',
                'וואטסאפ לא מדווח למערכת על הודעות נכנסות',
                'לא רשום ב-WAHA שום יעד לדיווח, ולכן הודעות של לקוחות לא מגיעות לכאן — שליחה החוצה עובדת בלי קשר. לחצו "הפעלת האזנה להודעות נכנסות".'
                    .$this->lastSeen($last),
            );
        }

        $matching = array_filter($registered, fn (array $hook): bool => $this->pointsAtUs((string) ($hook['url'] ?? ''), $ours));

        if ($matching === []) {
            $urls = implode(', ', array_map(fn (array $hook): string => $this->redact((string) ($hook['url'] ?? '')), $registered));

            return $this->result(
                false,
                'wrong_target',
                'וואטסאפ מדווח ליעד אחר',
                "ב-WAHA רשום דיווח ל: {$urls} — ולא לכתובת של המערכת ({$ours}). לחצו \"הפעלת האזנה להודעות נכנסות\" כדי לרשום מחדש."
                    .$this->lastSeen($last),
            );
        }

        // Registered and pointing at us. If nothing ever arrived, WAHA cannot
        // actually reach that address — the usual cause is an APP_URL that is
        // right for a browser and meaningless from inside another container.
        if ($last === null) {
            return $this->result(
                false,
                'never_delivered',
                'הרישום תקין אבל אף הודעה לא הגיעה',
                "WAHA מכוון לכתובת הנכונה ({$ours}), אך מעולם לא התקבלה ממנו הודעה. סימן שהוא לא מצליח להגיע לכתובת הזאת מהמקום שבו הוא רץ — בדקו שכתובת המערכת (APP_URL) נגישה מתוך WAHA, ולא כתובת שנכונה רק בדפדפן.",
            );
        }

        $events = $this->recentEventTypes();
        $messages = $events['message'] ?? 0;

        // Events arriving, none of them messages: the registration exists but
        // subscribes to the wrong event. A definite fault.
        if ($events !== [] && $messages === 0) {
            $types = implode(', ', array_keys($events));

            return $this->result(
                false,
                'no_messages',
                'מגיעים אירועים, אבל לא הודעות',
                "התקבלו אירועים מסוג: {$types}. אירוע ההודעות עצמו (message) לא מגיע — לחצו \"הפעלת האזנה להודעות נכנסות\" כדי לרשום את סוג האירוע הנכון."
                    .$this->lastSeen($last),
            );
        }

        if ($messages === 0) {
            // A channel that used to deliver on most days and has now been
            // silent for a week did not get quiet — it stopped. Treating that as
            // "maybe a slow week" is what let a real outage sit unnoticed for
            // eight days: the registration looked fine, so the screen said fine.
            if ($this->deliveredRegularly()) {
                return $this->result(
                    false,
                    'stalled',
                    'הקליטה פעלה ונפסקה',
                    'הודעות הגיעו כאן באופן סדיר ואז נפסקו לגמרי. הרישום מול WAHA קיים, ולכן ההסבר הסביר הוא ש-WAHA עלה מחדש ואיבד את ההגדרה, או שהסשן נוצר מחדש. לחצו "הפעלת האזנה להודעות נכנסות" כדי לרשום שוב, ושלחו הודעת בדיקה.'
                        .$this->lastSeen($last),
                );
            }

            // Never had a steady rhythm to lose: this really may be a slow week.
            // Reported, not raised — an alert that fires on quiet weeks is one
            // people learn to ignore.
            return $this->result(
                false,
                'quiet',
                'ההגדרות תקינות, אך לא התקבלו הודעות השבוע',
                'הרישום מול WAHA תקין והודעות כבר הגיעו בעבר, ולכן ייתכן ששבוע פשוט היה שקט. אם ידוע לכם על הודעה שנשלחה ולא הגיעה — לחצו "הפעלת האזנה להודעות נכנסות" ושלחו הודעת בדיקה.'
                    .$this->lastSeen($last),
                'warning',
            );
        }

        // Messages ARE arriving. That still does not mean tickets are opening —
        // the message can be claimed by the management chat, dropped as a group,
        // or sit in a queue nobody is working. A check that stops at "delivered"
        // reports everything healthy while the queue stays empty, which is the
        // most misleading answer of all.
        $outcome = $this->outcomeOfRecentMessages();

        if ($outcome['unprocessed'] > 0) {
            return $this->result(
                false,
                'not_processed',
                'הודעות מתקבלות אך לא מעובדות',
                "{$outcome['unprocessed']} הודעות התקבלו ולא עובדו. סימן שתור העבודות אינו רץ בשרת — הפעילו את Horizon (docker compose ps / restart) והן ייקלטו."
                    .$this->lastSeen($last),
            );
        }

        if ($outcome['tickets'] === 0 && $outcome['owner'] === $outcome['total'] && $outcome['total'] > 0) {
            return $this->result(
                false,
                'owner_only',
                'כל ההודעות הגיעו מצ׳אט הניהול',
                'ההודעות שהתקבלו הגיעו כולן ממספר/קבוצת האישורים — צ׳אט התפעול של הצוות, שלעולם אינו פותח פניות לקוח (שם מריצים פקודות ניהול). שלחו הודעת בדיקה ממספר אחר, שאינו מספר האישורים.'
                    .$this->lastSeen($last),
                'warning',
            );
        }

        if ($outcome['tickets'] === 0 && $outcome['group'] === $outcome['total'] && $outcome['total'] > 0) {
            return $this->result(
                false,
                'groups_only',
                'כל ההודעות הגיעו מקבוצות',
                'ההודעות שהתקבלו הגיעו מקבוצות, ערוצים או סטטוסים — המערכת מקשיבה רק לצ׳אטים ישירים של לקוחות. שלחו הודעה ישירה למספר העסק.'
                    .$this->lastSeen($last),
                'warning',
            );
        }

        if ($outcome['tickets'] === 0) {
            return $this->result(
                false,
                'no_tickets',
                'הודעות מתקבלות אך לא נפתחות פניות',
                "התקבלו {$messages} הודעות ואף פנייה לא נפתחה מהן. בדקו ביומן האירועים אם ההודעות נדחו (הודעה ללא טקסט, קבוצה, או צ׳אט הניהול)."
                    .$this->lastSeen($last),
            );
        }

        $note = $outcome['owner'] > 0
            ? " ({$outcome['owner']} מתוכן מצ׳אט הניהול, שאינו פותח פניות)"
            : '';

        return $this->result(
            true,
            'ok',
            'הקליטה מוואטסאפ תקינה',
            "התקבלו {$messages} הודעות נכנסות ב-7 הימים האחרונים{$note}, ונפתחו מהן {$outcome['tickets']} הודעות בפניות.".$this->lastSeen($last),
        );
    }

    /**
     * What became of the messages that did arrive.
     *
     * Reads the last 50 recorded message events and asks who sent them and
     * whether they were handled at all — the difference between "WhatsApp is
     * reaching us" and "customers are reaching us", which is exactly the gap a
     * delivery-only check reports as healthy.
     *
     * @return array{total: int, owner: int, group: int, unprocessed: int, tickets: int}
     */
    private function outcomeOfRecentMessages(): array
    {
        $ownerChat = app(ApprovalGate::class)->ownerChatId();

        $events = WebhookEvent::query()
            ->where('source', WebhookSource::Waha)
            ->where('event_type', 'message')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->limit(50)
            ->get(['payload', 'processed_at', 'created_at']);

        $owner = 0;
        $group = 0;
        $unprocessed = 0;

        foreach ($events as $event) {
            $from = (string) data_get($event->payload, 'payload.from', data_get($event->payload, 'from', ''));

            if ($ownerChat !== null && $from === $ownerChat) {
                $owner++;
            }

            if (Str::endsWith($from, ['@g.us', '@newsletter', '@broadcast'])) {
                $group++;
            }

            // A message from the last few minutes may legitimately still be in
            // flight; older than that and nothing is working the queue.
            if ($event->processed_at === null && $event->created_at?->lt(now()->subMinutes(5))) {
                $unprocessed++;
            }
        }

        return [
            'total' => $events->count(),
            'owner' => $owner,
            'group' => $group,
            'unprocessed' => $unprocessed,
            'tickets' => $this->ticketMessagesThisWeek(),
        ];
    }

    /**
     * Did this channel have a rhythm to lose?
     *
     * Messages on three or more separate days over the last two months means
     * the silence now is a change in behaviour, not a slow week — the
     * difference between "nobody wrote" and "we stopped hearing".
     */
    private function deliveredRegularly(): bool
    {
        $days = WebhookEvent::query()
            ->where('source', WebhookSource::Waha)
            ->where('event_type', 'message')
            ->where('created_at', '>=', now()->subDays(60))
            ->get(['created_at'])
            ->map(fn (WebhookEvent $event): string => (string) $event->created_at?->toDateString())
            ->unique()
            ->count();

        return $days >= 3;
    }

    /** Inbound WhatsApp messages that actually landed in a ticket this week. */
    private function ticketMessagesThisWeek(): int
    {
        return TicketMessage::query()
            ->where('direction', MessageDirection::Inbound)
            ->where('channel', MessageChannel::Whatsapp)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * The webhooks WAHA currently has on our session.
     *
     * @return list<array<string, mixed>>
     */
    private function registeredWebhooks(): array
    {
        $session = $this->waha->sessionStatus();

        return array_values(array_filter(
            (array) data_get($session, 'config.webhooks', []),
            'is_array',
        ));
    }

    /**
     * Does a registered URL point at our inbound endpoint?
     *
     * Compared on host+path only: the secret rides in the query string, and a
     * rotated secret is a different problem than a wrong destination — reporting
     * it as "pointing elsewhere" would send someone looking in the wrong place.
     */
    private function pointsAtUs(string $url, string $ours): bool
    {
        $a = parse_url($url);
        $b = parse_url($ours);

        return ($a['path'] ?? null) === ($b['path'] ?? null)
            && ($a['host'] ?? null) === ($b['host'] ?? null);
    }

    /** Never echo the shared secret back onto the screen. */
    private function redact(string $url): string
    {
        return (string) preg_replace('/secret=[^&]*/', 'secret=…', $url);
    }

    private function lastEvent(): ?Carbon
    {
        $at = WebhookEvent::query()
            ->where('source', WebhookSource::Waha)
            ->max('created_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /**
     * Inbound WAHA event types over the last week, with counts.
     *
     * @return array<string, int>
     */
    private function recentEventTypes(): array
    {
        return WebhookEvent::query()
            ->where('source', WebhookSource::Waha)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private function lastSeen(?Carbon $last): string
    {
        return $last === null
            ? ' מעולם לא התקבל אירוע מוואטסאפ.'
            : ' האירוע האחרון מוואטסאפ התקבל ב-'.$last->format('d/m/Y H:i').'.';
    }

    private function short(Throwable $e): string
    {
        return Str::limit(trim($e->getMessage()) ?: class_basename($e), 120);
    }

    /**
     * @return array{ok: bool, state: string, title: string, detail: string, variant: string}
     */
    private function result(bool $ok, string $state, string $title, string $detail, ?string $variant = null): array
    {
        return [
            'ok' => $ok,
            'state' => $state,
            'title' => $title,
            'detail' => $detail,
            'variant' => $variant ?? ($ok ? 'success' : 'danger'),
        ];
    }
}
