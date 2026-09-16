<?php

namespace App\Jobs;

use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One message to the agent's number: work out who sent it, whether they may use
 * the product, and answer.
 *
 * Identity first, always. Nothing about the message is even read until this
 * number has been bound to a site, proved it holds the number, and the customer
 * behind it is paying — because every later step edits a live website, and the
 * only thing standing between a stranger's message and that website is this
 * order of checks.
 */
class HandleSiteAgentMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Single attempt. A retry would answer the same message twice, and once
     * this job carries out instructions it would carry them out twice. The
     * failure is logged instead.
     */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $webhookEventId) {}

    public function handle(
        SiteAgentAccess $access,
        WhatsAppCloudClient $whatsapp,
        SiteAgentConversation $conversation,
    ): void {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = (array) $event->payload;
        $from = $whatsapp->normalize((string) ($payload['from'] ?? ''));
        $text = trim((string) data_get($payload, 'text.body', ''));

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        $decision = $access->for($from);
        $subscriber = $decision['subscriber'];

        // Somebody sending the code they were asked for is answering a question
        // we asked, not issuing an instruction — checked before anything else.
        if ($subscriber !== null && $subscriber->verified_at === null && $subscriber->codeMatches($text)) {
            $this->completeVerification($subscriber, $whatsapp, (string) ($payload['_profile_name'] ?? ''));
            $event->markProcessed();

            return;
        }

        if ($decision['status'] === SiteAgentAccess::ALLOWED && $subscriber !== null) {
            $subscriber->forceFill(['last_seen_at' => now()])->save();

            $reply = $conversation->handle($subscriber, $text, (string) ($payload['id'] ?? '') ?: null);
        } else {
            $reply = $this->answerFor($decision['status']);
        }

        if ($reply !== '' && $whatsapp->sendText($from, $reply) === null) {
            // The customer is holding a phone that shows their message
            // delivered and no answer. Nothing else in the system would notice.
            Log::warning('SiteAgent: reply could not be delivered', [
                'webhook_event_id' => $event->id,
                'status' => $decision['status'],
            ]);
        }

        $event->markProcessed();
    }

    /**
     * They sent back the code, so they hold the number.
     *
     * The code is cleared as it is spent: a code that keeps working after it
     * was used is a second key left under the mat.
     */
    private function completeVerification(SiteAgentSubscriber $subscriber, WhatsAppCloudClient $whatsapp, string $profileName): void
    {
        $subscriber->forceFill([
            'verified_at' => now(),
            'verification_code' => null,
            'last_seen_at' => now(),
            'name' => $subscriber->name ?: ($profileName !== '' ? $profileName : null),
        ])->save();

        SystemLog::record('info', 'site-agent',
            "מספר אומת לניהול האתר {$subscriber->site?->domain}",
            ['subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id]);

        $whatsapp->sendText($subscriber->phone, implode("\n", [
            '✅ המספר אומת.',
            '',
            "מעכשיו אפשר לנהל מכאן את האתר {$subscriber->site?->domain}.",
            '',
            'כתבו לי מה לשנות — למשל "בעמוד צור קשר, תחליף את הטלפון 03-1234567 ב-03-7654321".',
            'אציג לכם בדיוק מה ישתנה, וזה יקרה רק אחרי שתאשרו.',
        ]));
    }

    /**
     * What to say to this sender.
     *
     * Every case says something true and actionable. The one thing never said
     * is silence: a person who wrote to a number a business published, and got
     * nothing back, concludes the business is broken — and they are not wrong
     * to.
     */
    private function answerFor(string $status): string
    {
        $support = (string) config('billing.email.support_address');
        $contact = $support !== '' ? "\nלכל שאלה: {$support}" : '';

        return match ($status) {
            SiteAgentAccess::UNVERIFIED => 'כדי להתחיל, שלחו כאן את קוד האימות בן 6 הספרות שנשלח אליכם. '
                .'אם הקוד פג — פנו אלינו ונשלח חדש.'.$contact,

            SiteAgentAccess::NO_SUBSCRIPTION => implode("\n", array_filter([
                'המנוי לשירות ניהול האתר אינו פעיל כרגע, ולכן איני יכול לבצע שינויים.',
                '',
                'האתר עצמו ממשיך לעבוד כרגיל — רק הסוכן מושהה.',
                'לחידוש המנוי דברו איתנו ונפעיל מחדש.'.$contact,
            ])),

            SiteAgentAccess::REVOKED => 'ההרשאה של המספר הזה לניהול האתר הוסרה.'.$contact,

            SiteAgentAccess::SITE_DISCONNECTED => 'המנוי פעיל, אבל אין כרגע חיבור לאתר ולכן איני יכול לפעול בו. '
                .'הצוות שלנו קיבל התראה ויטפל.'.$contact,

            // An unknown number and a switched-off product get the same answer
            // on purpose: neither tells a stranger whether a given number is
            // registered here, which is not theirs to learn by probing.
            default => 'המספר הזה אינו רשום לשירות ניהול האתר.'.$contact,
        };
    }
}
