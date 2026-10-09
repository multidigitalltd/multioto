<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Services\Ai\AiUsageAttribution;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
    /**
     * The line every offer ends with. The channel recognises it and turns it
     * into "כן" / "לא" buttons; typed answers keep working either way.
     */
    public const CONFIRM_PROMPT = 'לביצוע השיבו "כן". לביטול — "לא".';

    public const NO_PENDING_PROPOSAL = 'אין כרגע הצעה שמורה ותקפה לביצוע, ולכן לא שיניתי דבר. כתבו שוב את השינוי המבוקש ואכין הצעה חדשה לאישור.';

    private const YES = ['כן', 'אשר', 'אישור', 'מאשר', 'מאשרת', 'בצע', 'תבצע', 'אוקיי', 'אוקי', 'ok', 'yes', 'כן.', '👍'];

    /** Words that mean no. */
    private const NO = ['לא', 'ביטול', 'בטל', 'עזוב', 'לא תודה', 'no', 'cancel'];

    /** Words that ask to put the last change back. */
    private const UNDO = ['בטל', 'תבטל', 'תחזיר', 'החזר', 'שחזר', 'undo'];

    public function __construct(
        private SiteChangePlanner $planner,
        private ProductChangePlanner $products,
        private ImageChangePlanner $images,
        private SiteChangeApplier $applier,
        private WhatsAppCloudClient $whatsapp,
        private SiteAgentAssistant $assistant,
        private SiteActionProposer $proposer,
    ) {}

    /**
     * Read the message and act.
     *
     * @param  Closure(): void|null  $beforeTurn  captures the alert history boundary after any preceding turn completes
     * @return string the reply to send back
     */
    public function handle(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, ?string $mediaId = null, ?Closure $beforeTurn = null): string
    {
        // Every AI call this message causes is booked to this customer, so the
        // usage screen can set what they cost against what they pay.
        return app(AiUsageAttribution::class)->for(
            $subscriber->customer_id,
            fn (): string => $this->handleFor($subscriber, $text, $messageId, $mediaId, $beforeTurn),
        );
    }

    private function handleFor(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, ?string $mediaId, ?Closure $beforeTurn): string
    {
        $text = trim($text);

        if ($text === '' && $mediaId === null) {
            $beforeTurn?->__invoke();

            return 'לא הבנתי מה לשנות. כתבו לי מה תרצו לעדכן באתר.';
        }

        // One message from this number at a time.
        //
        // Reading the offer on the table and then replacing it is two steps,
        // and production runs several workers: two messages arriving together
        // both see no offer pending and both create one, so the customer is
        // shown two previews and their "כן" answers only the newer. Everything
        // that reads the conversation and then writes it belongs inside here.
        // Held for as long as one turn can run. The assistant's turn reads the
        // site and calls the model several times; a lock that lapsed in the
        // middle of it would let the next message read a conversation this one
        // is still writing.
        // Held as long as the job may run (1,200s): a confirmed batch of plugin
        // updates can take most of that, and a lock that expires mid-turn lets
        // a "בטל" in through the middle of it.
        $lock = Cache::lock("site-agent:conversation:{$subscriber->id}", 1250);

        try {
            // WAITS for its turn rather than giving up on it. The job runs once
            // and its webhook event is marked processed either way, so a turn
            // dropped here is the customer's instruction — or their "כן" —
            // thrown away in silence. Queueing behind the message before it is
            // what they expect; being ignored is not.
            return $lock->block(90, function () use ($subscriber, $text, $messageId, $mediaId, $beforeTurn): string {
                // Capture diagnostic history only after the preceding turn
                // finished, while the same lock excludes later messages.
                $beforeTurn?->__invoke();
                $reply = $this->act($subscriber, $text, $messageId, $mediaId);

                // Every turn, whichever path answered it — a "כן" and its
                // "בוצע" are as much a part of what the assistant must know
                // next time as a question about orders.
                $this->assistant->remember($subscriber, SiteAgentMessage::USER, $mediaId !== null ? trim('[תמונה] '.$text) : $text);
                $this->assistant->remember($subscriber, SiteAgentMessage::ASSISTANT, $reply);

                return $reply;
            });
        } catch (LockTimeoutException) {
            // Ninety seconds behind a turn that is still running. Saying so is
            // the honest answer, and the customer can repeat themselves.
            return 'אני עדיין מטפל בהודעה הקודמת — נסו שוב בעוד רגע.';
        }
    }

    /** The conversation itself, with this number's turn held. */
    private function act(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, ?string $mediaId): string
    {
        $pending = SiteAgentRequest::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->where('site_id', $subscriber->site_id)
            ->where('customer_id', $subscriber->customer_id)
            ->awaitingConfirmation()
            ->latest('id')
            ->first();

        if ($pending !== null) {
            // A stored image question retains its own draft. The planner
            // distinguishes an answer from a topic change; a bare yes cannot
            // approve a question that has no verified preview.
            //
            // Without this the reply would fall through to the text planner —
            // which has no image — and they would be asked to send the
            // photograph again for no reason they could see.
            if ($mediaId === null && $this->isImageQuestion($pending)) {
                if ($this->matches($text, self::NO)) {
                    $this->settle($pending, SiteAgentRequest::CANCELED);

                    return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
                }

                return $this->answerImageQuestion($subscriber, $pending, $text, $messageId);
            }

            // The same thing for the shop: "איזה מוצר?" is a question, and the
            // product name they answer with is half of an instruction whose
            // other half — the price, the stock, the action — was in the
            // message before it.
            if ($mediaId === null && $this->isProductQuestion($pending)) {
                if ($this->matches($text, self::NO)) {
                    $this->settle($pending, SiteAgentRequest::CANCELED);

                    return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
                }

                return $this->answerProductQuestion($subscriber, $pending, $text, $messageId);
            }

            if ($mediaId === null && $this->isPageQuestion($pending)) {
                if ($this->matches($text, self::NO)) {
                    $this->settle($pending, SiteAgentRequest::CANCELED);

                    return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
                }

                return $this->answerPageQuestion($subscriber, $pending, $text, $messageId);
            }

            if ($mediaId === null && $this->matches($text, self::YES)) {
                return $this->confirm($pending);
            }

            if ($mediaId === null && $this->matches($text, self::NO)) {
                $this->settle($pending, SiteAgentRequest::CANCELED);

                return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
            }

            // A correction to an image offer still refers to its staged file.
            // Re-plan it in place and require approval of the revised preview.
            if ($mediaId === null && $this->hasStagedImage($pending)) {
                return $this->answerImageQuestion($subscriber, $pending, $text, $messageId);
            }

            if ($mediaId === null && $this->assistant->available()
                && app(SiteAgentOfferQuestion::class)->relatesTo($pending, $text)) {
                $answer = $this->converse($subscriber, $text, $messageId, $pending);

                return app(SiteAgentOfferQuestion::class)->reply($pending, $answer);
            }

            // Anything else replaces the offer: they changed their mind about
            // what they want, and leaving the old one alive would let a "כן"
            // three messages later confirm something they have moved on from.
            $this->settle($pending, SiteAgentRequest::CANCELED);
        }

        // An image is unambiguous about which planner it needs.
        if ($mediaId !== null) {
            return $this->proposeImage($subscriber, $text, $mediaId, $messageId);
        }

        // "בטל" with nothing on the table means the last thing that went live.
        if ($this->matches($text, self::UNDO)) {
            return $this->revertLast($subscriber);
        }

        $isApproval = $this->matches($text, self::YES);
        if ($isApproval && ! $this->previousReplyAllowsClarification($subscriber)) {
            // Older versions could emit a model-written preview without saving
            // a request. An expired or missing offer cannot be approved by prose.
            return self::NO_PENDING_PROPOSAL;
        }

        $reply = $this->converse($subscriber, $text, $messageId);

        // A yes can answer an ordinary clarification, so the assistant may use
        // its context. It must never reach a planner as a standalone edit.
        if ($reply !== null) {
            return $reply;
        }

        return $isApproval ? self::NO_PENDING_PROPOSAL : $this->propose($subscriber, $text, $messageId);
    }

    private function previousReplyAllowsClarification(SiteAgentSubscriber $subscriber): bool
    {
        $previous = SiteAgentMessage::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->where('site_id', $subscriber->site_id)
            ->where('role', SiteAgentMessage::ASSISTANT)
            ->latest('id')
            ->value('body');

        return is_string($previous)
            && preg_match('/[?؟]/u', $previous) === 1
            && ! app(SiteAgentReplyGuard::class)->asksForApproval($previous);
    }

    /**
     * Hand the message to the assistant, if it can take it.
     *
     * Null means the assistant is disabled. Once the full assistant accepts
     * a turn, failure cannot be rerouted to a narrower planner which could
     * misinterpret an ACF, SEO or internal-link request as a plain text edit.
     * Page text stays with the page planner even when the assistant runs: it
     * knows Elementor and how to quote a page exactly, and the assistant hands
     * those requests over rather than re-learning that.
     */
    private function converse(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, ?SiteAgentRequest $pendingOffer = null): ?string
    {
        $site = $subscriber->site;

        if ($site === null || ! $this->assistant->available()) {
            return null;
        }

        return $this->assistant->handle(
            $subscriber,
            $site,
            $text,
            $messageId,
            function (string $instruction) use ($subscriber, $text, $messageId): string {
                if ($this->planner->mentionsFrontPage($text) && ! $this->planner->mentionsFrontPage($instruction)) {
                    // The user's actual target survives a model rewriting the
                    // homepage as a page title such as "login".
                    $instruction = "בקשת בעל האתר בהודעה הנוכחית:\n".$text
                        ."\nפירוט שהוכן לעורך; יעד הבקשה המקורית נשאר מחייב:\n".$instruction;
                }

                return $this->propose($subscriber, $instruction, $messageId, tryShop: false);
            },
            pendingOffer: $pendingOffer,
        ) ?? ProductChangePlanner::AI_UNAVAILABLE;
    }

    /**
     * Plan one change and show it, exactly.
     *
     * The preview is the contract: it names the page and quotes the text before
     * and after, so "כן" is consent to something specific rather than to the
     * agent's good intentions.
     */
    private function propose(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, bool $tryShop = true): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        // The shop is asked first. A price is a number a business charges, and
        // "תוריד את החולצה ל-90" landing in the text planner would append a
        // sentence about ninety shekels to a page instead of changing a price.
        //
        // Except when the shop has already been asked and had nothing to say —
        // asking it the same question twice is a second call to the model for
        // an answer we are holding.
        $plan = $tryShop ? $this->products->plan($site, $text) : null;

        if (is_array($plan) && isset($plan['question'])) {
            // Held, not just asked. "איזה מוצר?" is answered with a name, and
            // the price they wanted was in the message before it — planning
            // that name on its own would look for an instruction that is not
            // in it and come back with nothing.
            $this->holdQuestion($subscriber, $site, $text, $messageId, [
                'kind' => 'product',
                'question' => $plan['question'],
            ]);

            return $plan['question'];
        }

        $plan ??= $this->planner->plan($site, $text);

        if (is_array($plan) && isset($plan['question'])) {
            $this->holdQuestion($subscriber, $site, $text, $messageId, [
                'kind' => 'page',
                'question' => $plan['question'],
            ]);

            return $plan['question'];
        }

        // A refusal the planner can explain — an Elementor page it can replace
        // text in but not append to. Saying which page and what IS possible
        // beats the generic "I did not understand".
        if (is_array($plan) && isset($plan['refusal'])) {
            return $plan['refusal'];
        }

        if ($plan === null) {
            return implode("\n", [
                'לא הצלחתי להבין בוודאות מה לשנות, ולכן לא נגעתי בכלום.',
                '',
                'עוזר לי אם תכתבו באיזה עמוד מדובר ומה בדיוק להחליף — למשל:',
                '"בעמוד צור קשר, תחליף את הטלפון 03-1234567 ב-03-7654321".',
            ]);
        }

        if (! app(SiteAgentPermissions::class)->allowsOperation($plan['operation'])) {
            return SiteAgentPermissions::refusal();
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

        return $request->preview."\n\n".self::CONFIRM_PROMPT;
    }

    /**
     * An image arrived. Fetch it, work out where it goes, and show the plan.
     *
     * The bytes are downloaded and validated BEFORE the customer is asked to
     * approve anything. Meta's media links are short-lived, so an offer made
     * first and fetched on confirmation would be an offer that expires into a
     * failure — and a file that turns out not to be an image at all should
     * never have produced a preview in the first place.
     */
    private function proposeImage(SiteAgentSubscriber $subscriber, string $caption, string $mediaId, ?string $messageId): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        $media = $this->whatsapp->downloadMedia($mediaId);

        if ($media === null) {
            return 'לא הצלחתי לקרוא את התמונה. אפשר לשלוח אותה שוב כקובץ JPG או PNG, עד '
                .(int) config('siteagent.media.max_megabytes', 8).'MB.';
        }

        $plan = $this->images->plan($site, $caption, $this->planner->targets($site, $caption), [
            'recent_conversation' => $this->imageHistory($subscriber),
        ]);

        if ($plan === null) {
            return 'קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.';
        }

        if (($plan['cancel'] ?? false) === true) {
            return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
        }
        $topicSwitch = ($plan['topic_switch'] ?? false) === true;
        if ($topicSwitch) {
            $plan = ['question' => 'קיבלתי את התמונה. לאיזה עמוד או מוצר היא מיועדת, ואיך לתאר אותה?', 'image_draft' => []];
        }

        [$plan, $offer] = $this->newProductOffer($site, $plan);
        $operation = $offer !== null ? SiteAgentRequest::OP_PRODUCT_CREATE : ($plan['operation'] ?? SiteAgentRequest::OP_IMAGE);

        if (! app(SiteAgentPermissions::class)->allowsOperation($operation)) {
            return SiteAgentPermissions::refusal();
        }

        $minutes = max(1, (int) config('siteagent.confirmation_minutes', 30));

        // Held on a private disk, not in the database row: an eight-megabyte
        // image base64'd into a json column is a row nobody can read quickly
        // and a table that grows in a way nothing else here does. The path
        // rides with the plan; the file is removed once the offer is settled.
        $path = 'site-agent/'.$subscriber->id.'/'.Str::random(32).'.'.$media['extension'];
        Storage::disk('local')->put($path, $media['bytes']);

        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'customer_id' => $subscriber->customer_id,
            'message' => Str::limit($caption !== '' ? $caption : '[תמונה]', 2000),
            'inbound_message_id' => $messageId,
            'operation' => $operation,
            'plan' => $offer !== null
                ? [...$offer['plan'], 'image_path' => $path, 'extension' => $media['extension'], 'caption' => $caption]
                : [
                    ...$plan,
                    'image_path' => $path,
                    'extension' => $media['extension'],
                    'caption' => $caption,
                    // What is on the target now, so the execution can tell whether
                    // somebody put a different picture there in the meantime.
                    'thumbnail_id' => isset($plan['target_id'])
                        ? $this->planner->thumbnailOf($site, (int) $plan['target_id'])
                        : null,
                ],
            // A question is not an offer, so there is nothing to preview and
            // nothing a "כן" could confirm — the row exists to hold the picture
            // and the caption while we wait for the missing half.
            'preview' => $offer['preview'] ?? (isset($plan['question']) ? null : $this->preview($plan)),
            'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        if ($topicSwitch) {
            return $this->imageTopicSwitch($subscriber, $request, $caption, $messageId);
        }

        return isset($plan['question']) ? $plan['question'] : $request->preview."\n\n".self::CONFIRM_PROMPT;
    }

    /**
     * Park a question on the record so the answer has something to join.
     *
     * The same row an offer would have used, with no preview: a question is not
     * an offer, so there is nothing for a "כן" to confirm — and it carries the
     * customer's own words, which is the half of the instruction the answer
     * does not repeat.
     *
     * @param  array<string, mixed>  $plan
     */
    private function holdQuestion(SiteAgentSubscriber $subscriber, Site $site, string $text, ?string $messageId, array $plan): void
    {
        SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id,
            'site_id' => $site->id,
            'customer_id' => $subscriber->customer_id,
            'message' => Str::limit($text, 2000),
            'inbound_message_id' => $messageId,
            'plan' => [...$plan, 'text' => $text],
            'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        ]);
    }

    /** A parked question about the shop, waiting for which product they meant. */
    private function isProductQuestion(SiteAgentRequest $request): bool
    {
        return data_get($request->plan, 'kind') === 'product'
            && filled(data_get($request->plan, 'question'));
    }

    private function isPageQuestion(SiteAgentRequest $request): bool
    {
        return data_get($request->plan, 'kind') === 'page'
            && filled(data_get($request->plan, 'question'));
    }

    /** Keep the named page and all earlier answers until there is an exact preview. */
    private function answerPageQuestion(SiteAgentSubscriber $subscriber, SiteAgentRequest $request, string $answer, ?string $messageId): string
    {
        if ($subscriber->site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        // A bare yes is not the missing text and cannot consent to a question.
        if ($this->matches($answer, self::YES)) {
            return (string) data_get($request->plan, 'question');
        }

        $context = trim((string) data_get($request->plan, 'text', ''));
        if (mb_strlen($context) > 3000) {
            // Retain the original target and the latest corrections, not only
            // the oldest answers when a clarification takes several turns.
            $context = Str::limit($context, 1000)."\n[…]\n".mb_substr($context, -1900);
        }

        $combined = $context
            ."\nשאלת הבהרה: ".data_get($request->plan, 'question')
            ."\nתשובת בעל האתר: ".Str::limit($answer, 2000);
        $plan = $this->planner->plan($subscriber->site, $combined);

        if (isset($plan['refusal'])) {
            // A temporary service/read failure must not discard their context.
            $request->update(['plan' => [...$request->plan, 'text' => $combined]]);

            return $plan['refusal'];
        }

        if ($plan === null) {
            // A different subject is not another answer to the old question.
            $this->settle($request, SiteAgentRequest::CANCELED);

            return $this->converse($subscriber, $answer, $messageId)
                ?? $this->propose($subscriber, $answer, $messageId);
        }

        if (isset($plan['question'])) {
            $question = $plan['question'];
            $request->update([
                'plan' => ['kind' => 'page', 'question' => $question, 'text' => Str::limit($combined, 6000)],
                'message' => Str::limit($combined, 2000),
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $question;
        }

        if (! app(SiteAgentPermissions::class)->allowsOperation($plan['operation'])) {
            return SiteAgentPermissions::refusal();
        }

        $request->update([
            'operation' => $plan['operation'],
            'plan' => $plan,
            'preview' => $this->preview($plan),
            'message' => Str::limit($combined, 2000),
            'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        ]);

        return $request->refresh()->preview."\n\n".self::CONFIRM_PROMPT;
    }

    /**
     * Their answer, planned together with what they asked for in the first place.
     *
     * "תוריד את המחיר ל-90" then "חולצה כחולה" is one instruction in two
     * messages. Planning only the second finds a product and no price.
     */
    private function answerProductQuestion(SiteAgentSubscriber $subscriber, SiteAgentRequest $request, string $answer, ?string $messageId): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        if ($this->matches($answer, self::YES)) {
            return (string) data_get($request->plan, 'question');
        }

        $context = trim((string) data_get($request->plan, 'text', ''));
        if (mb_strlen($context) > 3000) {
            $context = Str::limit($context, 1000)."\n[…]\n".mb_substr($context, -1900);
        }
        $context .= "\nשאלת הבהרה: ".Str::limit((string) data_get($request->plan, 'question'), 500);
        $combined = $context."\nתשובת בעל האתר: ".Str::limit($answer, 2000);
        $plan = $this->products->plan($site, $context, latestAnswer: $answer);

        if (isset($plan['refusal'])) {
            $request->update(['plan' => [...$request->plan, 'text' => Str::limit($combined, 6000)]]);

            return $plan['refusal'];
        }

        if (is_array($plan) && isset($plan['question'])) {
            $request->update([
                'plan' => ['kind' => 'product', 'question' => $plan['question'], 'text' => Str::limit($combined, 6000)],
                'message' => Str::limit($combined, 2000),
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $plan['question'];
        }

        // A new subject belongs to the current message, not the old price request.
        if ($plan === null) {
            $this->settle($request, SiteAgentRequest::CANCELED);

            return $this->converse($subscriber, $answer, $messageId)
                ?? $this->propose($subscriber, $answer, $messageId, tryShop: false);
        }

        if (! app(SiteAgentPermissions::class)->allowsOperation($plan['operation'])) {
            return SiteAgentPermissions::refusal();
        }

        $request->update([
            'operation' => $plan['operation'],
            'plan' => $plan,
            'preview' => $this->preview($plan),
            'message' => Str::limit($combined, 2000),
            'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        ]);

        return $request->refresh()->preview."\n\n".self::CONFIRM_PROMPT;
    }

    /**
     * A photograph meant as a new product, turned into the same offer a typed
     * request would make.
     *
     * Returns the image plan unchanged and no offer when the photograph is
     * for an existing page or product. When the proposer refuses — a price
     * that is not a price, publishing without one — its reason becomes the
     * question, and the photograph waits for the answer instead of being
     * sent again.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: array{plan: array<string, mixed>, preview: string}|null}
     */
    private function newProductOffer(Site $site, array $plan): array
    {
        if (! isset($plan['new_product'])) {
            return [$plan, null];
        }

        $offer = $this->proposer->newProduct($site, [...(array) $plan['new_product'], 'image_alt' => (string) $plan['alt']]);

        if (isset($offer['error'])) {
            return [['question' => $offer['error'], 'image_draft' => $plan['image_draft'] ?? []], null];
        }

        return [$plan, [
            'plan' => [...$offer['plan'], 'image_alt' => (string) $plan['alt'], 'image_draft' => $plan['image_draft'] ?? []],
            'preview' => $offer['preview'],
        ]];
    }

    /** Read questions preserve the photo; another proposal supersedes and cleans it. */
    private function imageTopicSwitch(SiteAgentSubscriber $subscriber, SiteAgentRequest $request, string $text, ?string $messageId): string
    {
        $reply = $this->converse($subscriber, $text, $messageId)
            ?? $this->propose($subscriber, $text, $messageId);
        if (SiteAgentRequest::query()->where('site_agent_subscriber_id', $subscriber->id)
            ->where('site_id', $subscriber->site_id)->where('customer_id', $subscriber->customer_id)
            ->where('id', '>', $request->id)->awaitingConfirmation()->exists()) {
            $this->settle($request, SiteAgentRequest::CANCELED);
        }

        return $reply;
    }

    /** Recent words help resolve "this product"; only a live site read validates its identity. */
    private function imageHistory(SiteAgentSubscriber $subscriber): array
    {
        return SiteAgentMessage::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->where('site_id', $subscriber->site_id)
            ->latest('id')->limit(6)->get(['role', 'body'])->reverse()
            ->map(fn (SiteAgentMessage $message): array => [
                'role' => $message->role,
                'body' => Str::limit($message->body, 700, ''),
            ])->values()->all();
    }

    /**
     * Is this row a question we asked about an image, rather than an offer?
     *
     * An offer has a preview and can be confirmed; this has neither, and the
     * next thing the customer types is the answer to it.
     */
    private function isImageQuestion(SiteAgentRequest $request): bool
    {
        return $this->hasStagedImage($request) && filled(data_get($request->plan, 'question'));
    }

    private function hasStagedImage(SiteAgentRequest $request): bool
    {
        return in_array($request->operation, [SiteAgentRequest::OP_IMAGE, SiteAgentRequest::OP_MEDIA_UPLOAD, SiteAgentRequest::OP_PRODUCT_CREATE], true)
            && filled(data_get($request->plan, 'image_path'));
    }

    /**
     * Their answer, read together with what they originally said.
     *
     * Both halves go back to the planner: "תשים את זה בדף הבית" followed by
     * "כיכר לחם על שולחן עץ" is one instruction the customer gave in two
     * messages, and planning only the second would lose the page.
     */
    private function answerImageQuestion(SiteAgentSubscriber $subscriber, SiteAgentRequest $request, string $answer, ?string $messageId): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        $plan = (array) $request->plan;
        if ($this->matches($answer, self::YES)) {
            return (string) $plan['question'];
        }
        if (! Storage::disk('local')->exists((string) $plan['image_path'])) {
            $this->settle($request, SiteAgentRequest::EXPIRED);

            return 'התמונה הקודמת כבר אינה זמינה. שלחו אותה שוב כדי להכין הצעה חדשה; לא שיניתי דבר באתר.';
        }

        // Keep the question and latest answer separate: a description answers
        // the alt question without replacing the already verified product.
        $next = $this->images->plan($site, $answer, $this->planner->targets($site, $answer), [
            ...$plan,
            'recent_conversation' => $this->imageHistory($subscriber),
        ]);
        if ($next === null) {
            $request->update([
                'preview' => null,
                'plan' => [...$plan, 'question' => ImageChangePlanner::UNRESOLVED_IMAGE.' מה לתקן בהצעה?'],
            ]);

            return 'קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.';
        }
        if (($next['cancel'] ?? false) === true) {
            $this->settle($request, SiteAgentRequest::CANCELED);

            return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
        }
        if (($next['topic_switch'] ?? false) === true) {
            return $this->imageTopicSwitch($subscriber, $request, $answer, $messageId);
        }
        $caption = Str::limit((string) ($plan['caption'] ?? ''), 1000, '')
            ."\nהצעה או שאלת הבהרה: ".(string) ($plan['question'] ?? $request->preview ?? '')."\nתשובת בעל האתר: ".$answer;
        $caption = Str::limit($caption, 6000, '');

        [$next, $offer] = $this->newProductOffer($site, $next);
        $operation = $offer !== null ? SiteAgentRequest::OP_PRODUCT_CREATE : ($next['operation'] ?? SiteAgentRequest::OP_IMAGE);

        if (! app(SiteAgentPermissions::class)->allowsOperation($operation)) {
            $this->settle($request, SiteAgentRequest::CANCELED);

            return SiteAgentPermissions::refusal();
        }

        if ($offer !== null) {
            $request->update([
                'operation' => SiteAgentRequest::OP_PRODUCT_CREATE,
                'plan' => [...$offer['plan'], 'image_path' => $plan['image_path'], 'extension' => $plan['extension'] ?? 'jpg', 'caption' => $caption],
                'message' => Str::limit($caption, 2000),
                'preview' => $offer['preview'],
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $request->refresh()->preview."\n\n".self::CONFIRM_PROMPT;
        }

        // Still short of something. The picture stays where it is and the
        // question is asked again, refined — the customer never resends it.
        if (isset($next['question'])) {
            $request->update([
                'operation' => $operation,
                'preview' => null,
                'plan' => [...$next, 'image_path' => $plan['image_path'], 'extension' => $plan['extension'] ?? 'jpg', 'caption' => $caption],
                'message' => Str::limit($caption, 2000),
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $next['question'];
        }

        $request->update([
            'operation' => $operation,
            'plan' => [
                ...$next,
                'image_path' => $plan['image_path'],
                'extension' => $plan['extension'] ?? 'jpg',
                'caption' => $caption,
                'thumbnail_id' => isset($next['target_id'])
                    ? $this->planner->thumbnailOf($site, (int) $next['target_id'])
                    : null,
            ],
            'message' => Str::limit($caption, 2000),
            'preview' => $this->preview($next),
            'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        ]);

        return $request->refresh()->preview."\n\n".self::CONFIRM_PROMPT;
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
        // Only the page operations have a page. Reading it unconditionally is
        // how a price change crashed on its own preview.
        $page = (string) ($plan['page_title'] ?? '');

        return match ($plan['operation']) {
            SiteAgentRequest::OP_PRICE => implode("\n", array_filter([
                "🛒 מוצר: {$plan['product_name']}",
                isset($plan['fields']['regular_price'])
                    ? 'מחיר רגיל: '.($plan['current']['regular_price'] ?: '—')." ← {$plan['fields']['regular_price']}"
                    : null,
                array_key_exists('sale_price', $plan['fields'])
                    ? ($plan['fields']['sale_price'] === ''
                        ? 'סיום המבצע (המחיר חוזר למחיר הרגיל)'
                        : 'מחיר מבצע: '.($plan['current']['sale_price'] ?: '—')." ← {$plan['fields']['sale_price']}")
                    : null,
            ])),
            SiteAgentRequest::OP_STOCK => implode("\n", [
                "🛒 מוצר: {$plan['product_name']}",
                isset($plan['fields']['stock_quantity'])
                    ? "מלאי: {$plan['fields']['stock_quantity']}"
                    : 'מצב מלאי: '.match ($plan['fields']['stock_status'] ?? '') {
                        'instock' => 'במלאי',
                        'outofstock' => 'אזל מהמלאי',
                        'onbackorder' => 'בהזמנה מראש',
                        default => (string) ($plan['fields']['stock_status'] ?? ''),
                    },
            ]),
            SiteAgentRequest::OP_IMAGE => implode("\n", [
                "🖼️ {$plan['target_title']}",
                'התמונה ששלחתם תוגדר כתמונה הראשית.',
                'תיאור לנגישות: "'.$plan['alt'].'"',
            ]),
            SiteAgentRequest::OP_MEDIA_UPLOAD => implode("\n", [
                '🖼️ העלאת התמונה ששלחתם לספריית המדיה',
                'כותרת: "'.$plan['title'].'"',
                'תיאור לנגישות: "'.$plan['alt'].'"',
                'הקובץ יישמר בספרייה ויהיה זמין בקישור ציבורי; הוא לא יוצב בעמוד או במוצר.',
                'אחרי האישור הקובץ נשאר בספרייה — הבוט אינו מוחק אותו באמצעות "בטל".',
            ]),
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

    /**
     * They said yes. Carry it out, and say plainly whether it worked.
     *
     * Two "כן" messages are two different inbound messages, so the webhook's
     * own deduplication never sees them as the same thing: both jobs can load
     * the same awaiting offer and both can carry it out — a paragraph appended
     * twice, an image uploaded twice. The conditional UPDATE is what actually
     * prevents it, because it is one statement and exactly one caller can move
     * the row out of AWAITING; the lock is there so the second caller finds out
     * before it has done any work rather than after.
     */
    private function confirm(SiteAgentRequest $request): string
    {
        $lock = Cache::lock("site-agent:confirm:{$request->id}", 180);

        if (! $lock->get()) {
            return 'אני כבר מבצע את השינוי — רגע אחד.';
        }

        try {
            $claimed = SiteAgentRequest::query()
                ->whereKey($request->id)
                ->where('state', SiteAgentRequest::AWAITING)
                ->update(['state' => SiteAgentRequest::APPLYING]);

            if ($claimed === 0) {
                return 'השינוי הזה כבר טופל.';
            }

            $request->refresh();

            return $this->carryOut($request);
        } finally {
            $lock->release();
        }
    }

    /** The claimed request, executed. */
    private function carryOut(SiteAgentRequest $request): string
    {
        // Switched off after the offer was made: the "כן" is to something no
        // longer allowed.
        if (! app(SiteAgentPermissions::class)->allowsOperation($request->operation)) {
            $this->settle($request, SiteAgentRequest::FAILED, 'disabled by the team');

            return SiteAgentPermissions::refusal();
        }

        try {
            $result = $this->applier->apply($request);
        } catch (\Throwable $e) {
            // Nothing may leave the row stuck in APPLYING: the image would sit
            // on our disk forever and the team would see a change that never
            // finished.
            $this->settle($request, SiteAgentRequest::FAILED, Str::limit($e->getMessage(), 490));

            return 'לא הצלחתי לבצע את השינוי באתר. נסו שוב, ואם זה חוזר — נשמח לעזור.';
        }

        if (! $result['ok']) {
            // settle(), not a bare state change: a failed image change used to
            // leave the customer's photograph on our disk indefinitely, because
            // the pruning job only ever visits offers still awaiting an answer.
            $this->settle($request, SiteAgentRequest::FAILED, (string) $result['reason']);

            // The page moved under us between the preview and the yes.
            if ($result['reason'] === SiteChangeApplier::STALE) {
                return ($this->isPageOperation($request) ? 'העמוד השתנה' : 'זה השתנה באתר')
                    .' מאז שהצגתי לכם את השינוי, ולכן לא ביצעתי אותו — כדי לא למחוק עריכה של מישהו אחר. '
                    .'בקשו שוב ואציג הצעה מעודכנת.';
            }

            // The customer gets the message, never the reason: a reason may be
            // an error from their site's API, and that is not theirs to read in
            // a WhatsApp message.
            return 'לא הצלחתי לבצע את השינוי: '.$result['message'];
        }

        $partial = ($result['partial'] ?? false) === true;
        $plan = (array) $request->plan;
        if (is_int($result['created_id'] ?? null) && $result['created_id'] > 0) {
            $plan['created_id'] = $result['created_id'];
        }
        if ($partial) {
            $plan['execution_outcome'] = [
                'status' => 'partial',
                'message' => Str::limit((string) ($result['done'] ?? 'הבקשה לא הושלמה במלואה.'), 1000),
            ];
        }

        $request->update([
            'state' => SiteAgentRequest::APPLIED,
            'restore' => $result['restore'],
            'applied_at' => now(),
            'plan' => $plan,
        ]);

        SystemLog::record($partial ? 'warning' : 'info', 'site-agent',
            ($partial ? 'בקשה באתר בוצעה חלקית: ' : 'שינוי באתר בוצע לבקשת הלקוח: ').$request->plan['summary'],
            ['request_id' => $request->id, 'site_id' => $request->site_id]);

        $window = max(1, (int) config('siteagent.undo_minutes', 1440));
        $done = isset($result['done']) ? "\n".$result['done'] : '';

        // A created draft must not be created again, but incomplete required
        // fields must not be described as a fully successful owner request.
        if ($partial) {
            $path = (string) ($plan['image_path'] ?? '');
            $directory = 'site-agent/'.$request->site_agent_subscriber_id.'/';
            if (str_starts_with($path, $directory)
                && preg_match('/^[A-Za-z0-9]{32}\.(?:jpg|png|gif|webp)$/D', substr($path, strlen($directory))) === 1) {
                // Only a staged image owned by this conversation. A partial
                // creation is consumed and will not visit pending cleanup.
                Storage::disk('local')->delete($path);
            }

            return '⚠️ הבקשה בוצעה חלקית.'.$done;
        }

        // An undo is promised only where there is one. A note already emailed
        // or a user already invited cannot be taken back, and the owner was
        // told so in the preview — saying "כתבו בטל" now would be a promise
        // the next message breaks.
        if ($result['restore'] === null && ($request->operation === SiteAgentRequest::OP_MEDIA_UPLOAD
                || in_array($request->operation, SiteAgentRequest::MANAGEMENT_OPERATIONS, true))) {
            return '✅ בוצע.'.$done;
        }

        return '✅ בוצע.'.$done."\n\n".'אם משהו לא נראה טוב — כתבו "בטל" ואחזיר לקדמותו (עד '
            .($window >= 60 ? intdiv($window, 60).' שעות' : $window.' דקות').').';
    }

    /** A text change on a page or post, as opposed to the shop or the site's records. */
    private function isPageOperation(SiteAgentRequest $request): bool
    {
        return in_array($request->operation, [SiteAgentRequest::OP_APPEND, SiteAgentRequest::OP_REPLACE, SiteAgentRequest::OP_TITLE], true);
    }

    /** Put the last applied change back. */
    private function revertLast(SiteAgentSubscriber $subscriber): string
    {
        // An upload intentionally stays in the library. Do not silently undo
        // an unrelated earlier page edit when "בטל" follows that upload.
        $recent = SiteAgentRequest::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->where('site_id', $subscriber->site_id)
            ->where('customer_id', $subscriber->customer_id)
            ->revertable()
            ->latest('applied_at')->latest('id');
        $latest = (clone $recent)->first();

        if ($latest?->operation === SiteAgentRequest::OP_MEDIA_UPLOAD) {
            return 'התמונה נשארת בספריית המדיה. כדי להגן על קבצי האתר, הבוט אינו מוחק את הקובץ באמצעות "בטל".';
        }

        if ($latest !== null && $latest->restore === null && $latest->operation !== SiteAgentRequest::OP_CACHE_FLUSH) {
            return $latest->operation === SiteAgentRequest::OP_CCT_CREATE
                ? 'הרשומה נשמרה כטיוטה. הבוט אינו מוחק אותה באמצעות "בטל". אפשר להמשיך לערוך אותה.'
                : 'לפעולה האחרונה אין שחזור אוטומטי. אפשר לומר לי איזה פרט לשנות ואכין הצעה לאישור.';
        }

        // A cache flush has nothing to put back; find the previous change only
        // when that is necessary. The usual case needs a single query.
        $last = $latest?->restore !== null ? $latest : (clone $recent)->whereNotNull('restore')->first();

        if ($last === null) {
            return 'אין שינוי אחרון שאפשר להחזיר.';
        }

        if (! app(SiteAgentPermissions::class)->allowsOperation($last->operation)) {
            return SiteAgentPermissions::refusal();
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
                // Something happened after our change. An undo writes the
                // whole page — or the whole set of product fields — back, so
                // carrying on would erase it, which is the one thing an undo
                // must never do.
                if ($result['reason'] === SiteChangeApplier::STALE) {
                    $kind = (string) data_get($last->restore, 'kind', 'page');

                    if (! in_array($kind, ['page', 'elementor', 'thumbnail', 'product'], true)) {
                        return 'זה השתנה באתר אחרי השינוי שביצעתי, ולכן לא החזרתי אותו — שחזור היה מוחק את השינוי החדש. '
                            .'אפשר לומר לי בדיוק מה להחזיר ואציג הצעה.';
                    }

                    return $kind === 'product'
                        ? 'המוצר השתנה אחרי השינוי שביצעתי — ייתכן שנמכר ממנו משהו או שמישהו עדכן אותו. '
                            .'לא החזרתי, כדי לא למחוק את השינוי החדש. אפשר לומר לי בדיוק מה להחזיר.'
                        : 'העמוד נערך אחרי השינוי שביצעתי, ולכן לא החזרתי אותו — שחזור היה מוחק את העריכה החדשה. '
                            .'אפשר לומר לי בדיוק מה להחזיר ואציג הצעה.';
                }

                return 'לא הצלחתי להחזיר את השינוי: '.$result['message'];
            }

            $last->update(['state' => SiteAgentRequest::REVERTED, 'reverted_at' => now()]);

            return '↩️ הוחזר לקדמותו.';
        } finally {
            $lock->release();
        }
    }

    /**
     * Close an offer, and take its image with it.
     *
     * An uploaded picture that nobody approved has no reason to stay on our
     * disk — it is a customer's file, held only for as long as the question
     * about it is open.
     */
    private function settle(SiteAgentRequest $request, string $state, ?string $reason = null): void
    {
        $path = (string) data_get($request->plan, 'image_path', '');

        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }

        $request->update(array_filter([
            'state' => $state,
            'failure_reason' => $reason,
        ], fn ($value): bool => $value !== null));
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
