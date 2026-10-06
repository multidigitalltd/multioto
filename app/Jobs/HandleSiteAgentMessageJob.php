<?php

namespace App\Jobs;

use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentMessageCap;
use App\Services\SiteAgent\SiteAgentPitch;
use App\Services\SiteAgent\SiteAgentUsageMeter;
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
     * Added up, and with room over: two model calls (the shop planner and the
     * page planner), the page list, PAGE_LIMIT content reads one after another
     * at the MCP timeout, a 120-second image upload, and up to ninety seconds
     * queueing behind the message before this one. Roughly 660 seconds of
     * budget on the slowest path, so 600 was under it.
     *
     * The margin matters because being killed here is not a retry: a worker cut
     * off mid-flight runs no catch and no finally, the request stays `applying`
     * for ever, the customer's photograph is never cleaned up, and with one
     * attempt the instruction is simply lost with nothing to say so.
     *
     * The assistant adds its own turn in front: reads and model calls capped
     * by siteagent.assistant.budget_seconds (240), one more model call to
     * answer (90), and — when it hands page text over — the planner path above
     * on top. Roughly 1,000 seconds on the slowest path, so 1,200.
     */
    public int $timeout = 1200;

    public function __construct(public int $webhookEventId) {}

    public function handle(
        SiteAgentAccess $access,
        WhatsAppCloudClient $whatsapp,
        SiteAgentConversation $conversation,
        SiteChoice $choice,
        SiteAgentUsageMeter $meter,
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

        // A tapped "כן" / "לא" button is the same answer as typing it.
        if (in_array($type, ['interactive', 'button'], true)) {
            $text = WhatsAppCloudClient::buttonText($payload);
            $type = $text !== '' ? 'text' : $type;
        }

        if ($from === '') {
            $event->markProcessed();

            return;
        }

        // Everything that decides WHO this message is from happens under one
        // lock, held per phone number.
        //
        // Choosing a site, holding the instruction and taking it back are one
        // decision about one conversation, and they are keyed by the PHONE —
        // taken before any subscriber is known, so the per-subscriber lock
        // inside the conversation is too late to cover them. Two messages
        // arriving together would otherwise both find no choice made, overwrite
        // each other's held instruction, send two questions, and answering
        // either would replay whichever won the race.
        //
        // Matching a verification code belongs under the SAME lock, and the
        // bindings must be read inside it. The five-attempt limit is the only
        // thing standing between a stranger and a six-digit code, and it is
        // read off a loaded model: workers that all loaded their copy before
        // any of them charged would each see a budget that was already spent.
        // Six guesses sent at once would then get six checks against live
        // codes, however many attempts the row had left — a limit that holds
        // only when nobody is in a hurry is not a limit.
        $lock = Cache::lock("site-agent:routing:{$from}", 120);

        try {
            $routed = $lock->block(60, fn (): array => $this->identify(
                $access, $choice, $whatsapp, $from, $text, $mediaId,
                (string) ($payload['_profile_name'] ?? ''),
                (int) ($payload['timestamp'] ?? 0),
            ));
        } catch (LockTimeoutException) {
            $whatsapp->sendText($from, 'אני עדיין מטפל בהודעה הקודמת — נסו שוב בעוד רגע.');
            $event->markProcessed();

            return;
        }

        // Verified, or asked which site. Either way the answer already went
        // out and there is nothing to carry out for this message.
        if ($routed['done']) {
            $event->markProcessed();

            return;
        }

        $subscriber = $routed['subscriber'];
        $text = $routed['text'];
        $mediaId = $routed['media_id'];

        // Nothing usable: fall back to any binding at all, so the refusal can
        // say WHICH refusal it is — unverified, revoked, or unheard of.
        $decision = $access->forSubscriber($subscriber ?? $routed['fallback']);
        $subscriber = $decision['subscriber'];

        // Whether this reply counts towards the bill. A refusal, or the notice
        // that the owner's own ceiling was reached, is the system talking about
        // itself.
        $billable = false;
        $subscription = null;

        if ($decision['status'] === SiteAgentAccess::ALLOWED && $subscriber !== null) {
            $subscriber->forceFill(['last_seen_at' => now()])->save();
            $subscription = app(SiteAgentBilling::class)->subscriptionForSite($subscriber->customer, $subscriber->site_id);

            if ($meter->capReached($subscription)) {
                $reply = $this->atCeiling($subscription, $text);
            } else {
                $billable = true;
                $reply = $type !== 'text' && $type !== 'image'
                    ? 'אני יודע לקרוא הודעות טקסט ותמונות. אפשר לכתוב לי מה לשנות באתר?'
                    : $conversation->handle(
                        $subscriber,
                        $text,
                        (string) ($payload['id'] ?? '') ?: null,
                        $mediaId !== '' ? $mediaId : null,
                    );
            }
        } else {
            $reply = $this->answerFor($decision['status'], $subscriber, $from);
        }

        $delivered = $reply !== '' ? $this->deliver($whatsapp, $from, $reply) : null;

        if ($reply !== '' && $delivered === null) {
            // The customer is holding a phone that shows their message
            // delivered and no answer. Nothing else in the system would notice.
            Log::warning('SiteAgent: reply could not be delivered', [
                'webhook_event_id' => $event->id,
                'status' => $decision['status'],
            ]);
        }

        // Billed per reply the service delivered — and only to a number that
        // is entitled to it. A refusal to an unpaid or unknown number is the
        // system talking about itself, and nobody is billed for that.
        if ($delivered !== null && $billable && $subscriber !== null) {
            $meter->record($subscriber, $delivered);
            $this->warnNearCeiling($whatsapp, $meter, $from, $subscription);
        }

        $event->markProcessed();
    }

    /**
     * Who is this message from, and which of their sites is it about?
     *
     * Runs with this number's routing lock held, and reads the bindings itself
     * so that it reads them under that lock — a verification attempt counted by
     * another worker has to be visible here before this one tests a code
     * against it.
     *
     * `done` means the message is fully answered: they verified a number, or
     * they were asked which site and the instruction is held until they say.
     * `fallback` is any binding this number has, so that a refusal can name
     * which refusal it is even when none of them is usable.
     *
     * @return array{subscriber: SiteAgentSubscriber|null, text: string, media_id: string, done: bool, fallback: SiteAgentSubscriber|null}
     */
    private function identify(
        SiteAgentAccess $access,
        SiteChoice $choice,
        WhatsAppCloudClient $whatsapp,
        string $from,
        string $text,
        string $mediaId,
        string $profileName,
        int $sentAt,
    ): array {
        $bindings = $access->bindings($from);
        $fallback = $bindings->first();

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
            // A six-digit message that opened nothing is a wrong guess, and
            // EVERY code it was tried against pays for it.
            //
            // Charging only one of them leaves the others with untouched
            // counters: once the charged binding is spent its charge becomes a
            // no-op, while codeIs() keeps happily testing the rest — which is
            // unlimited guessing against live codes. The guesser is the phone,
            // not the binding, so the whole phone runs out of attempts at once.
            $pending->each(fn (SiteAgentSubscriber $binding) => $binding->chargeAttempt());
        }

        if ($awaiting !== null) {
            $this->completeVerification($awaiting, $whatsapp, $profileName);
            // The site they just proved is the one they are talking about.
            $choice->remember($from, (int) $awaiting->site_id);

            return ['subscriber' => null, 'text' => $text, 'media_id' => $mediaId, 'done' => true, 'fallback' => $fallback];
        }

        $usable = $bindings->filter(fn (SiteAgentSubscriber $binding): bool => $binding->isUsable())->values();

        $routed = $this->route($choice, $whatsapp, $from, $text, $mediaId, $usable, $sentAt);

        return [
            'subscriber' => $routed['subscriber'],
            'text' => $routed['text'],
            'media_id' => $routed['media_id'],
            'done' => $routed['asked'],
            'fallback' => $fallback,
        ];
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
     * The owner's own ceiling is reached: only raising or removing it is
     * answered; anything else gets the notice. Neither is billed.
     */
    private function atCeiling(Subscription $subscription, string $text): string
    {
        $cap = app(SiteAgentMessageCap::class);
        $command = $cap->command($text);

        if ($command === null) {
            return $cap->reachedNotice($subscription);
        }

        return $cap->set($subscription, $command['cap']) ?? $cap->confirmation($subscription->refresh());
    }

    /** The once-a-cycle notice at 80% of the owner's ceiling — not billed. */
    private function warnNearCeiling(WhatsAppCloudClient $whatsapp, SiteAgentUsageMeter $meter, string $to, ?Subscription $subscription): void
    {
        if ($subscription === null || ! $meter->shouldWarn($subscription)) {
            return;
        }

        $subscription->forceFill(['site_agent_cap_warned_at' => now()])->save();
        $whatsapp->sendText($to, app(SiteAgentMessageCap::class)->warning($subscription));
    }

    /**
     * The reply, with "כן" / "לא" buttons when it is an offer.
     *
     * Falls back to the plain text if the buttons are refused, so an offer is
     * never lost to a formatting problem — typing still works.
     */
    private function deliver(WhatsAppCloudClient $whatsapp, string $to, string $reply): ?string
    {
        $suffix = "\n\n".SiteAgentConversation::CONFIRM_PROMPT;

        if (str_ends_with($reply, $suffix)) {
            $sent = $whatsapp->sendConfirmation($to, mb_substr($reply, 0, mb_strlen($reply) - mb_strlen($suffix)));

            if ($sent !== null) {
                return $sent;
            }
        }

        return $whatsapp->sendText($to, $reply);
    }

    /**
     * What to say to this sender.
     *
     * Every case says something true and actionable. The one thing never said
     * is silence: a person who wrote to a number a business published, and got
     * nothing back, concludes the business is broken — and they are not wrong
     * to.
     */
    private function answerFor(string $status, ?SiteAgentSubscriber $subscriber, string $from): string
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

            // Somebody who found the bot's number and is not a customer yet:
            // what it does and where to join. Every registered number got its
            // own answer above, so this tells a stranger nothing about anyone.
            SiteAgentAccess::UNKNOWN_NUMBER => app(SiteAgentPitch::class)->message($from),

            default => 'שירות ניהול האתר אינו פעיל כרגע.'.$contact,
        };
    }
}
