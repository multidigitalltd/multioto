<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
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
    ) {}

    /**
     * Read the message and act.
     *
     * @return string the reply to send back
     */
    public function handle(SiteAgentSubscriber $subscriber, string $text, ?string $messageId, ?string $mediaId = null): string
    {
        $text = trim($text);

        if ($text === '' && $mediaId === null) {
            return 'לא הבנתי מה לשנות. כתבו לי מה תרצו לעדכן באתר.';
        }

        // One message from this number at a time.
        //
        // Reading the offer on the table and then replacing it is two steps,
        // and production runs several workers: two messages arriving together
        // both see no offer pending and both create one, so the customer is
        // shown two previews and their "כן" answers only the newer. Everything
        // that reads the conversation and then writes it belongs inside here.
        $lock = Cache::lock("site-agent:conversation:{$subscriber->id}", 180);

        try {
            // WAITS for its turn rather than giving up on it. The job runs once
            // and its webhook event is marked processed either way, so a turn
            // dropped here is the customer's instruction — or their "כן" —
            // thrown away in silence. Queueing behind the message before it is
            // what they expect; being ignored is not.
            return $lock->block(90, fn (): string => $this->act($subscriber, $text, $messageId, $mediaId));
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
            ->awaitingConfirmation()
            ->latest('id')
            ->first();

        if ($pending !== null) {
            // A question WE asked about an image already in hand. Everything
            // that is not a plain refusal is the answer to it — checked before
            // the yes/no branches, because "כן" is not an answer to "how should
            // I describe the picture?" and confirming an offer that was never
            // made is not what the customer meant by it.
            //
            // Without this the reply would fall through to the text planner —
            // which has no image — and they would be asked to send the
            // photograph again for no reason they could see.
            if ($mediaId === null && $this->isImageQuestion($pending)) {
                if ($this->matches($text, self::NO)) {
                    $this->settle($pending, SiteAgentRequest::CANCELED);

                    return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
                }

                return $this->answerImageQuestion($subscriber, $pending, $text);
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

            if ($this->matches($text, self::YES)) {
                return $this->confirm($pending);
            }

            if ($this->matches($text, self::NO)) {
                $this->settle($pending, SiteAgentRequest::CANCELED);

                return 'בוטל, לא שיניתי כלום. אפשר לבקש משהו אחר.';
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

        return $this->propose($subscriber, $text, $messageId);
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

        return $request->preview."\n\n".'לביצוע השיבו "כן". לביטול — "לא".';
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

        $plan = $this->images->plan($site, $caption, $this->planner->targets($site, $caption));

        if ($plan === null) {
            return 'קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.';
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
            'operation' => SiteAgentRequest::OP_IMAGE,
            'plan' => [
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
            'preview' => isset($plan['question']) ? null : $this->preview($plan),
            'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return isset($plan['question'])
            ? $plan['question']
            : $request->preview."\n\n".'לביצוע השיבו "כן". לביטול — "לא".';
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

        $combined = trim(trim((string) data_get($request->plan, 'text', '')).' '.$answer);
        $plan = $this->products->plan($site, $combined);

        if (is_array($plan) && isset($plan['question'])) {
            $request->update([
                'plan' => ['kind' => 'product', 'question' => $plan['question'], 'text' => $combined],
                'message' => Str::limit($combined, 2000),
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $plan['question'];
        }

        // Not a shop request after all — let the page planner have the whole of
        // what they said, rather than answering "I could not find the product"
        // to somebody who was never talking about one.
        if ($plan === null) {
            $this->settle($request, SiteAgentRequest::CANCELED);

            return $this->propose($subscriber, $combined, $messageId, tryShop: false);
        }

        $request->update([
            'operation' => $plan['operation'],
            'plan' => $plan,
            'preview' => $this->preview($plan),
            'message' => Str::limit($combined, 2000),
            'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        ]);

        return $request->refresh()->preview."\n\n".'לביצוע השיבו "כן". לביטול — "לא".';
    }

    /**
     * Is this row a question we asked about an image, rather than an offer?
     *
     * An offer has a preview and can be confirmed; this has neither, and the
     * next thing the customer types is the answer to it.
     */
    private function isImageQuestion(SiteAgentRequest $request): bool
    {
        return $request->operation === SiteAgentRequest::OP_IMAGE
            && filled(data_get($request->plan, 'question'))
            && filled(data_get($request->plan, 'image_path'));
    }

    /**
     * Their answer, read together with what they originally said.
     *
     * Both halves go back to the planner: "תשים את זה בדף הבית" followed by
     * "כיכר לחם על שולחן עץ" is one instruction the customer gave in two
     * messages, and planning only the second would lose the page.
     */
    private function answerImageQuestion(SiteAgentSubscriber $subscriber, SiteAgentRequest $request, string $answer): string
    {
        $site = $subscriber->site;

        if ($site === null) {
            return 'אין לי כרגע חיבור לאתר.';
        }

        $plan = (array) $request->plan;
        $caption = trim(trim((string) ($plan['caption'] ?? '')).' '.$answer);

        // Planned on both halves, but the shop is looked up with the ANSWER
        // alone. The plugin hands the term straight to WooCommerce's text
        // search, so a whole sentence — "שים את זה כמוצר הראשי של חולצה כחולה"
        // — matches nothing, while the two words they just typed find it.
        $next = $this->images->plan($site, $caption, $this->planner->targets($site, $answer));

        if ($next === null) {
            return 'קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.';
        }

        // Still short of something. The picture stays where it is and the
        // question is asked again, refined — the customer never resends it.
        if (isset($next['question'])) {
            $request->update([
                'plan' => [...$next, 'image_path' => $plan['image_path'], 'extension' => $plan['extension'] ?? 'jpg', 'caption' => $caption],
                'message' => Str::limit($caption, 2000),
                'expires_at' => now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
            ]);

            return $next['question'];
        }

        $request->update([
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

        return $request->refresh()->preview."\n\n".'לביצוע השיבו "כן". לביטול — "לא".';
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
                return 'העמוד השתנה מאז שהצגתי לכם את השינוי, ולכן לא ביצעתי אותו — כדי לא למחוק עריכה של מישהו אחר. '
                    .'בקשו שוב ואציג הצעה מעודכנת.';
            }

            // The customer gets the message, never the reason: a reason may be
            // an error from their site's API, and that is not theirs to read in
            // a WhatsApp message.
            return 'לא הצלחתי לבצע את השינוי: '.$result['message'];
        }

        $request->update([
            'state' => SiteAgentRequest::APPLIED,
            'restore' => $result['restore'],
            'applied_at' => now(),
        ]);

        SystemLog::record('info', 'site-agent',
            "שינוי באתר בוצע לבקשת הלקוח: {$request->plan['summary']}",
            ['request_id' => $request->id, 'site_id' => $request->site_id]);

        $window = max(1, (int) config('siteagent.undo_minutes', 1440));

        return '✅ בוצע.'."\n\n".'אם משהו לא נראה טוב — כתבו "בטל" ואחזיר לקדמותו (עד '
            .($window >= 60 ? intdiv($window, 60).' שעות' : $window.' דקות').').';
    }

    /** Put the last applied change back. */
    private function revertLast(SiteAgentSubscriber $subscriber): string
    {
        $last = SiteAgentRequest::query()
            ->where('site_agent_subscriber_id', $subscriber->id)
            ->revertable()
            ->latest('applied_at')
            ->first();

        if ($last === null) {
            return 'אין שינוי אחרון שאפשר להחזיר.';
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
                    return data_get($last->restore, 'kind') === 'product'
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
