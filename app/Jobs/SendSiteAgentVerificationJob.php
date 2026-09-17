<?php

namespace App\Jobs;

use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;

/**
 * Send the six-digit code that proves a number belongs to the person claiming
 * it, and stamp it on the binding.
 *
 * Queued rather than done in the screen that asks for it, for the ordinary
 * reason — an external API call has no business inside an HTTP request — and
 * for a specific one: this is the same code path whether it is triggered by the
 * activation wizard or by a "resend" on the list, and two copies of a
 * credential-minting routine is one copy too many.
 *
 * The code is generated here and never returned anywhere. A code the team can
 * read is a code the team can use, and the entire point of it is to establish
 * that the PHONE is held by the person we think holds it.
 */
class SendSiteAgentVerificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Single attempt. A retry mints a SECOND code and invalidates the first, so
     * a customer who already received one would be typing in a code that has
     * just been replaced behind their back.
     */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $subscriberId) {}

    public function handle(WhatsAppCloudClient $whatsapp, TeamNotifier $team): void
    {
        $subscriber = SiteAgentSubscriber::with('site')->find($this->subscriberId);

        // Verified in the meantime (they answered an earlier code), or the
        // access was taken away between queueing and running. Either way there
        // is nothing to prove any more.
        if ($subscriber === null || $subscriber->verified_at !== null || $subscriber->revoked_at !== null) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(1, (int) config('siteagent.binding.verification_ttl_minutes', 30));

        $sent = $whatsapp->sendText($subscriber->phone, implode("\n", [
            'קוד האימות שלכם לניהול האתר '.($subscriber->site?->domain ?? '').':',
            $code,
            '',
            "שלחו אותו חזרה כאן כדי להתחיל. הקוד תקף ל-{$ttl} דקות.",
        ]));

        if ($sent === null) {
            // Stamping a code nobody received leaves the team waiting for a
            // reply to a message that was never delivered, and the customer
            // waiting for a service they paid for. Somebody has to be told.
            SystemLog::record('error', 'site-agent', 'קוד האימות לא נשלח', [
                'subscriber_id' => $subscriber->id,
                'site_id' => $subscriber->site_id,
            ]);

            $team->alert(
                'קוד אימות לסוכן האתר לא נשלח',
                'לא הצלחנו לשלוח קוד אימות ל'.($subscriber->site?->domain ?? 'לקוח').'. בדקו את חיבור הוואטסאפ ושלחו שוב.',
            );

            return;
        }

        $subscriber->forceFill([
            'verification_code' => Hash::make($code),
            'verification_sent_at' => now(),
            // A fresh code is a fresh chance: carrying the counter over would
            // mean resending to a customer who mistyped hands them a code that
            // is already spent.
            'verification_attempts' => 0,
        ])->save();
    }
}
