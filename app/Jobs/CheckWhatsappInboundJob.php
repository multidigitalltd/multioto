<?php

namespace App\Jobs;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use App\Services\Waha\InboundDiagnosis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        if ($isFault) {
            // Same fault as last time — already reported, and repeating it every
            // hour trains people to filter the alert out.
            if ($state === $previous) {
                return;
            }

            Setting::put(self::STATE_KEY, $state);

            SystemLog::record('warning', 'waha.inbound', 'קליטת וואטסאפ: '.$result['title'], [
                'state' => $state,
                'detail' => $result['detail'],
            ]);

            $notifier->alert(
                '⚠️ פניות מוואטסאפ לא נקלטות',
                $result['detail']."\n\nשליחה החוצה ממשיכה לעבוד — זה למה זה לא בולט.",
                ManageIntegrations::getUrl(),
            );

            return;
        }

        // Recovered. Worth exactly one message: it's how you learn the fix took.
        if ($previous !== '' && in_array($previous, InboundDiagnosis::FAULTS, true)) {
            Setting::put(self::STATE_KEY, $state);

            $notifier->alert('✓ קליטת הפניות מוואטסאפ חזרה לעבוד', $result['detail']);

            return;
        }

        Setting::put(self::STATE_KEY, $state);
    }
}
