<?php

namespace App\Jobs;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use App\Services\Waha\InboundDiagnosis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Watch the inbound WhatsApp path and say something when it breaks.
 *
 * A broken inbound path has no symptom: outbound keeps working, no error is
 * raised, and the ticket queue simply stops filling. It looks exactly like a
 * quiet day — which is why it can run for weeks before anyone notices that
 * customers have been writing into silence.
 *
 * Alerts ONLY on a definite fault (nothing registered, registered elsewhere,
 * never delivered, wrong event type). A quiet week with a healthy registration
 * is not an alert: an alert that fires on slow weeks is one people learn to
 * ignore, and then they ignore the real one too.
 */
class CheckWhatsappInboundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** The last state we alerted about, so a standing fault isn't re-sent hourly. */
    private const STATE_KEY = 'waha.inbound_alert_state';

    /**
     * The last verdict, kept so the dashboard can show a fault that is STILL
     * standing. Without it the only trace of a broken WhatsApp was one message
     * at the moment it broke — and a message can be missed, not least because
     * one of the two channels it goes out on is the WhatsApp that just died.
     */
    public const RESULT_KEY = 'waha.inbound_last_result';

    /** When the standing fault was last said out loud. */
    private const ALERTED_AT_KEY = 'waha.inbound_alerted_at';

    /**
     * How long a standing fault stays quiet before it is said again.
     *
     * Not hourly — that trains people to filter it out. Not never either: while
     * this is broken, customers are writing to a number that answers nobody,
     * and a day is about how long that is tolerable without a reminder.
     */
    private const REPEAT_AFTER_HOURS = 24;

    public function handle(InboundDiagnosis $diagnosis, TeamNotifier $notifier): void
    {
        // Nothing configured yet — an install that never had WhatsApp should not
        // be told daily that WhatsApp is broken.
        if (blank(config('billing.waha.base_url')) || blank(config('billing.waha.api_key'))) {
            return;
        }

        $result = $diagnosis->run();
        $state = (string) $result['state'];
        $previous = (string) (Setting::map()[self::STATE_KEY] ?? '');
        $isFault = in_array($state, InboundDiagnosis::FAULTS, true);

        // Recorded on every run, before any decision about alerting. This is
        // what the dashboard reads, and it has to describe now — not the last
        // time something changed enough to be worth a message.
        $this->remember($result, $isFault);

        if ($isFault) {
            // Same fault as last time — already reported. Repeating it hourly
            // trains people to filter the alert out, so it is said again only
            // once a day, and only while it is still broken.
            if ($state === $previous && ! $this->dueForRepeat()) {
                return;
            }

            Setting::put(self::STATE_KEY, $state);
            Setting::put(self::ALERTED_AT_KEY, now()->toIso8601String());

            SystemLog::record('warning', 'waha.inbound', 'קליטת וואטסאפ: '.$result['title'], [
                'state' => $state,
                'detail' => $result['detail'],
            ]);

            // The two faults are not the same outage and must not be announced
            // as if they were. A logged-out session stops sending too, so the
            // "outbound still works" line — which is the reason the other kind
            // goes unnoticed — would be a false reassurance here.
            $bothDirections = $state === 'session_down';

            $notifier->alert(
                $bothDirections
                    ? '⚠️ וואטסאפ מנותק — לא נכנסות פניות ולא יוצאות הודעות'
                    : '⚠️ פניות מוואטסאפ לא נקלטות',
                $result['detail']."\n\n".($bothDirections
                    ? 'עד שזה יטופל, גם פניות של לקוחות לא מגיעות וגם הודעות הצוות (פניות חדשות, אישורי פעולה, תזכורות תשלום) לא נשלחות.'
                    : 'שליחה החוצה ממשיכה לעבוד — זה למה זה לא בולט.'),
                ManageIntegrations::getUrl(),
            );

            return;
        }

        // Recovered. Worth exactly one message: it's how you learn the fix took.
        if ($previous !== '' && in_array($previous, InboundDiagnosis::FAULTS, true)) {
            Setting::put(self::STATE_KEY, $state);
            Setting::forget(self::ALERTED_AT_KEY);

            $notifier->alert('✓ קליטת הפניות מוואטסאפ חזרה לעבוד', $result['detail']);

            return;
        }

        Setting::put(self::STATE_KEY, $state);
        Setting::forget(self::ALERTED_AT_KEY);
    }

    /**
     * Keep the current verdict where the dashboard can read it.
     *
     * Stored rather than recomputed, because the health report is not allowed
     * to call WAHA: a report that waits on somebody else's server reports their
     * weather, and does it inside every page load.
     */
    private function remember(array $result, bool $isFault): void
    {
        Setting::put(self::RESULT_KEY, json_encode([
            'state' => $result['state'],
            'title' => $result['title'],
            'detail' => $result['detail'],
            'fault' => $isFault,
            'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Has the standing fault gone unmentioned long enough to say again? */
    private function dueForRepeat(): bool
    {
        $last = Setting::map()[self::ALERTED_AT_KEY] ?? null;

        if (blank($last)) {
            return true;
        }

        return rescue(
            fn (): bool => Carbon::parse($last)->addHours(self::REPEAT_AFTER_HOURS)->isPast(),
            // An unreadable timestamp must not silence the alert for ever.
            true,
            report: false,
        );
    }
}
