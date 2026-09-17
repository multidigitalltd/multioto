<?php

namespace App\Jobs;

use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChoice;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Longer than everything it can wait on, added up.
     *
     * The image upload alone allows a 120-second call to the site, planning
     * reads a bounded set of pages one after another (SiteChangePlanner's
     * PAGE_LIMIT, each at the MCP timeout), and the turn may queue behind
     * another message for ninety seconds. A worker killed mid-flight runs no
     * catch and no finally: the request stays `applying` for ever, the
     * customer's photograph is never cleaned up, and with one attempt there is
     * no retry to put any of it right.
     */
    public int $timeout = 600;

    public function __construct(public int $webhookEventId) {}

    public function handle(
        SiteAgentAccess $access,
        WhatsAppCloudClient $whatsapp,
        SiteAgentConversation $conversation,
        SiteChoice $choice,
    ): void {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = (array) $event->payload;
        $from = $whatsapp->normalize((string) ($payload['from'] ?? ''));

        // An image carries its instruction in the caption; a text message in
        // its body. Anything else (voice, a document, a location) has no
        // meaning to this agent and is answered as such rather than ignored.
        $type = (string) ($payload['type'] ?? 'text');
        $mediaId = $type === 'image' ? (string) data_get($payload, 'image.id', '') : '';
        $text = trim((string) ($type === 'image'
            ? data_get($payload, 'image.caption', '')
            : data_get($payload, 'text.body', '')));

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        $bindings = $access->bindings($from);

        // Somebody sending the code they were asked for is answering a question
        // we asked, not issuing an instruction — checked before anything else,
        // and against EVERY binding awaiting one. The code is what identifies
        // which binding they are proving, so an owner already managing one site
        // can still verify a second.
        //
        // Only a message SHAPED like a code is offered to the matcher. A wrong
        // answer spends one of the five attempts, and an owner already managing
        // one site goes on sending ordinary instructions while a second binding
        // waits — five of those would burn the second site's code before they
        // ever got round to typing it.
        $pending = $this->looksLikeCode($text)
            ? $bindings->filter(fn (SiteAgentSubscriber $binding): bool => $binding->verified_at === null
                && $binding->revoked_at === null)
            : collect();

        // Asked without charging, then charged once. Testing each binding with
        // codeMatches() would spend an attempt on every one it asked before
        // finding the right one — so entering the correct code for a second
        // site would quietly burn the first site's remaining guesses.
        $awaiting = $pending->first(fn (SiteAgentSubscriber $binding): bool => $binding->codeIs($text));

        if ($awaiting === null) {
            // A six-digit message that opened nothing IS a wrong guess. One
            // binding pays for it — the newest, which is the one a code was
            // most recently sent for.
            $pending->sortByDesc('verification_sent_at')->first()?->chargeAttempt();
        }

        if ($awaiting !== null) {
            $this->completeVerification($awaiting, $whatsapp, (string) ($payload['_profile_name'] ?? ''));
            // The site they just proved is the one they are talking about.
            $choice->remember($from, (int) $awaiting->site_id);
            $event->markProcessed();

            return;
        }

        $usable = $bindings->filter(fn (SiteAgentSubscriber $binding): bool => $binding->isUsable())->values();

        // Choosing a site, holding the instruction and taking it back are one
        // decision about one conversation, and they are keyed by the PHONE —
        // taken before any subscriber is known, so the per-subscriber lock
        // inside the conversation is too late to cover them. Two messages
        // arriving together would otherwise both find no choice made, overwrite
        // each other's held instruction, send two questions, and answering
        // either would replay whichever won the race.
        $lock = Cache::lock("site-agent:routing:{$from}", 120);

        try {
            $routed = $lock->block(60, fn (): array => $this->route(
                $choice, $whatsapp, $from, $text, $mediaId, $usable,
                (int) ($payload['timestamp'] ?? 0),
            ));
        } catch (LockTimeoutException) {
            $whatsapp->sendText($from, 'אני עדיין מטפל בהודעה הקודמת — נסו שוב בעוד רגע.');
            $event->markProcessed();

            return;
        }

        // The question about which site went out; what they asked for is held
        // until they answer it, and there is nothing else to do with this one.
        if ($routed['asked']) {
            $event->markProcessed();

            return;
        }

        $subscriber = $routed['subscriber'];
        $text = $routed['text'];
        $mediaId = $routed['media_id'];

        // Nothing usable: fall back to any binding at all, so the refusal can
        // say WHICH refusal it is — unverified, revoked, or unheard of.
        $decision = $access->forSubscriber($subscriber ?? $bindings->first());
        $subscriber = $decision['subscriber'];

        if ($decision['status'] === SiteAgentAccess::ALLOWED && $subscriber !== null) {
            $subscriber->forceFill(['last_seen_at' => now()])->save();

            $reply = $type !== 'text' && $type !== 'image'
                ? 'אני יודע לקרוא הודעות טקסט ותמונות. אפשר לכתוב לי מה לשנות באתר?'
                : $conversation->handle(
                    $subscriber,
                    $text,
                    (string) ($payload['id'] ?? '') ?: null,
                    $mediaId !== '' ? $mediaId : null,
                );
        } else {
            $reply = $this->answerFor($decision['status'], $subscriber);
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
     * Which site is this message for, and what is the instruction?
     *
     * Runs with this number's routing lock held. Returns the binding to act on,
     * together with the text and image to act WITH — which may be the ones held
     * from before the question rather than the ones in this message.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $usable
     * @return array{subscriber: SiteAgentSubscriber|null, text: string, media_id: string, asked: bool}
     */
    private function route(
        SiteChoice $choice,
        WhatsAppCloudClient $whatsapp,
        string $from,
        string $text,
        string $mediaId,
        Collection $usable,
        int $sentAt,
    ): array {
        $subscriber = $choice->resolve($from, $text, $usable);

        // More than one site and nothing in the message says which. Asking is
        // the only safe answer: an instruction meant for one business carried
        // out on another is the worst thing this product can do, and it would
        // be done silently.
        //
        // What they asked for is kept while we ask. Otherwise their next
        // message is "1", and "1" is all the planner ever sees — the customer
        // watches their instruction vanish into a question.
        if ($subscriber === null && $usable->count() > 1) {
            $choice->hold($from, $text, $mediaId !== '' ? $mediaId : null, $sentAt);
            $whatsapp->sendText($from, $choice->question($usable));

            return ['subscriber' => null, 'text' => $text, 'media_id' => $mediaId, 'asked' => true];
        }

        // They answered the question and nothing more, so the instruction they
        // gave before it is the one to carry out.
        if ($subscriber !== null && $choice->isBareAnswer($text, $usable)) {
            [$text, $mediaId] = $choice->take($from) ?? [$text, $mediaId];
        }

        return ['subscriber' => $subscriber, 'text' => $text, 'media_id' => $mediaId, 'asked' => false];
    }

    /**
     * Could this message be a verification code at all?
     *
     * Six digits and nothing else. The check exists so that an ordinary
     * instruction is never counted as a wrong guess — the attempt counter is
     * what stops somebody guessing a code, and spending it on messages nobody
     * meant as a guess turns a safety measure into a way to lock customers out.
     */
    private function looksLikeCode(string $text): bool
    {
        return preg_match('/^\d{6}$/', trim($text)) === 1;
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
    private function answerFor(string $status, ?SiteAgentSubscriber $subscriber): string
    {
        $support = (string) config('billing.email.support_address');
        $contact = $support !== '' ? "\nלכל שאלה: {$support}" : '';

        return match ($status) {
            SiteAgentAccess::UNVERIFIED => 'כדי להתחיל, שלחו כאן את קוד האימות בן 6 הספרות שנשלח אליכם. '
                .'אם הקוד פג — פנו אלינו ונשלח חדש.'.$contact,

            // The same words the pause notification used, from the same place:
            // somebody who writes a week after being told the agent stopped
            // should get the same explanation and the same way to fix it, not a
            // vaguer version of it.
            SiteAgentAccess::NO_SUBSCRIPTION => $subscriber !== null
                ? app(SiteAgentBilling::class)->pausedMessage($subscriber)
                : 'מנוי ניהול האתר אינו פעיל כרגע.'.$contact,

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
