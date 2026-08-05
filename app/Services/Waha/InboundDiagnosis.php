<?php

namespace App\Services\Waha;

use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
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
     * @return array{ok: bool, title: string, detail: string, variant: string}
     */
    public function run(): array
    {
        $ours = route('webhooks.waha');
        $last = $this->lastEvent();

        try {
            $registered = $this->registeredWebhooks();
        } catch (Throwable $e) {
            return $this->result(false, 'לא ניתן לקרוא את הגדרות WAHA', 'החיבור לשרת WAHA נכשל: '.$this->short($e).' בדקו את כתובת השרת וה-API Key בסעיף הזה.');
        }

        if ($registered === []) {
            return $this->result(
                false,
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
                'הרישום תקין אבל אף הודעה לא הגיעה',
                "WAHA מכוון לכתובת הנכונה ({$ours}), אך מעולם לא התקבלה ממנו הודעה. סימן שהוא לא מצליח להגיע לכתובת הזאת מהמקום שבו הוא רץ — בדקו שכתובת המערכת (APP_URL) נגישה מתוך WAHA, ולא כתובת שנכונה רק בדפדפן.",
            );
        }

        $events = $this->recentEventTypes();
        $messages = $events['message'] ?? 0;

        if ($messages === 0) {
            $types = implode(', ', array_keys($events)) ?: 'ללא';

            return $this->result(
                false,
                'מגיעים אירועים, אבל לא הודעות',
                "התקבלו אירועים מסוג: {$types}. אירוע ההודעות עצמו (message) לא מגיע — לחצו \"הפעלת האזנה להודעות נכנסות\" כדי לרשום את סוג האירוע הנכון."
                    .$this->lastSeen($last),
            );
        }

        return $this->result(
            true,
            'הקליטה מוואטסאפ תקינה',
            "התקבלו {$messages} הודעות נכנסות ב-7 הימים האחרונים.".$this->lastSeen($last),
        );
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
     * @return array{ok: bool, title: string, detail: string, variant: string}
     */
    private function result(bool $ok, string $title, string $detail): array
    {
        return ['ok' => $ok, 'title' => $title, 'detail' => $detail, 'variant' => $ok ? 'success' : 'danger'];
    }
}
