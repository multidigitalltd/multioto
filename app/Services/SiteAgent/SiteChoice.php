<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentSubscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Which of a number's sites is this message about?
 *
 * The schema lets one number manage more than one site — an owner of two
 * businesses, which is an ordinary thing to be. Everything else in the product
 * then has exactly one question to answer before it does anything at all, and
 * getting it wrong is the worst failure this product has: an instruction meant
 * for the bakery, carried out on the café.
 *
 * So it is never inferred. When there is more than one candidate the agent asks
 * and waits; the answer is remembered for the rest of the conversation, and the
 * customer switches by naming the other site. A number bound to exactly one
 * site never sees any of this.
 */
class SiteChoice
{
    /**
     * Where the answer lives.
     *
     * A cache entry keyed by the number, not a column: it is conversation
     * state, it expires on its own, and a stale one is corrected by the
     * customer naming the other site — which is the same gesture that sets it.
     */
    private function key(string $phone): string
    {
        return 'site-agent:site-choice:'.$phone;
    }

    /** How long the conversation stays about the same site. */
    private function minutes(): int
    {
        return max(1, (int) config('siteagent.site_choice_minutes', 1440));
    }

    public function remember(string $phone, int $siteId): void
    {
        Cache::put($this->key($phone), $siteId, now()->addMinutes($this->minutes()));
    }

    public function forget(string $phone): void
    {
        Cache::forget($this->key($phone));
    }

    /**
     * Keep what they asked for while we ask which site it is for.
     *
     * Without this the question eats the instruction: the customer writes
     * "תעדכן את שעות הפתיחה", is asked which site, answers "1" — and "1" is the
     * only thing the planner ever sees. From where they sit, the agent asked a
     * question and then forgot what it was about.
     *
     * Held for the confirmation window, not for the day the site choice lasts:
     * an instruction nobody got back to within half an hour should not spring
     * to life later on.
     */
    public function hold(string $phone, string $text, ?string $mediaId = null, int $sentAt = 0): void
    {
        if (trim($text) === '' && $mediaId === null) {
            return;
        }

        $held = Cache::get($this->heldKey($phone));

        // The NEWEST instruction is the one they meant — and "newest" is when
        // the customer sent it, not which worker happened to get there first.
        // Two messages arriving together are handed out in no particular order,
        // so without their own timestamps the one that survives is a coin toss
        // and the customer cannot tell which of the two they are answering.
        if (is_array($held) && (int) ($held['sent_at'] ?? 0) > $sentAt) {
            return;
        }

        Cache::put(
            $this->heldKey($phone),
            ['text' => $text, 'media_id' => $mediaId, 'sent_at' => $sentAt],
            now()->addMinutes(max(1, (int) config('siteagent.confirmation_minutes', 30))),
        );
    }

    /**
     * The instruction we were holding, taken (never left behind to run twice).
     *
     * @return array{0: string, 1: string}|null [text, media id]
     */
    public function take(string $phone): ?array
    {
        $held = Cache::pull($this->heldKey($phone));

        return is_array($held)
            ? [(string) ($held['text'] ?? ''), (string) ($held['media_id'] ?? '')]
            : null;
    }

    /**
     * Is this message ONLY an answer to "which site?", with no instruction in it?
     *
     * The distinction decides whether the held instruction is replayed. "1" and
     * "cafe.test" are answers and nothing more; "cafe.test תעדכן מחיר" names the
     * site AND says what to do, and replaying an older instruction over it would
     * carry out something they have moved on from.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $bindings
     */
    public function isBareAnswer(string $text, Collection $bindings): bool
    {
        if ($bindings->count() <= 1) {
            return false;
        }

        $trimmed = Str::lower(trim($text, " \t\n\r\0\x0B.!?,־-"));

        if ($trimmed === '') {
            return false;
        }

        if (ctype_digit($trimmed) && $bindings->get((int) $trimmed - 1) !== null) {
            return true;
        }

        return $bindings->contains(function (SiteAgentSubscriber $binding) use ($trimmed): bool {
            $domain = Str::lower((string) $binding->site?->domain);

            return $domain !== ''
                && ($trimmed === $domain || $trimmed === Str::before(Str::after($domain, 'www.') ?: $domain, '.'));
        });
    }

    private function heldKey(string $phone): string
    {
        return 'site-agent:held-instruction:'.$phone;
    }

    /**
     * The binding this message is about, or null when we have to ask.
     *
     * Three ways to arrive at an answer, in order of how explicit they are:
     * naming the site wins over the position in the list, which wins over what
     * was agreed earlier. An earlier choice never beats something the customer
     * just said, or "תעדכן את המחיר ב-cafe" would go to the bakery because that
     * is what they were talking about an hour ago.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $bindings  usable ones, in list order
     */
    public function resolve(string $phone, string $text, Collection $bindings): ?SiteAgentSubscriber
    {
        if ($bindings->count() <= 1) {
            return $bindings->first();
        }

        $named = $this->named($text, $bindings);

        if ($named !== null) {
            $this->remember($phone, (int) $named->site_id);

            return $named;
        }

        $position = $this->position($text, $bindings);

        if ($position !== null) {
            $this->remember($phone, (int) $position->site_id);

            return $position;
        }

        $remembered = Cache::get($this->key($phone));

        // Only if it is still one of theirs: access can be revoked between two
        // messages, and a remembered site must never resurrect a binding that
        // is no longer allowed.
        return $bindings->first(fn (SiteAgentSubscriber $b): bool => (int) $b->site_id === (int) $remembered);
    }

    /**
     * Did they name one of their domains?
     *
     * Matched on the domain with its www and TLD taken off, so "תעדכן ב-cafe"
     * finds cafe.co.il. Two matches are no match at all — a customer with
     * shop.co.il and shop-tools.co.il who wrote "shop" has not chosen.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $bindings
     */
    private function named(string $text, Collection $bindings): ?SiteAgentSubscriber
    {
        $haystack = Str::lower($text);

        $matches = $bindings->filter(function (SiteAgentSubscriber $binding) use ($haystack): bool {
            $domain = Str::lower((string) $binding->site?->domain);

            if ($domain === '') {
                return false;
            }

            $label = Str::before(Str::after($domain, 'www.') ?: $domain, '.');

            return $this->mentions($haystack, $domain)
                || ($label !== '' && mb_strlen($label) >= 3 && $this->mentions($haystack, $label));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Is this name a word of its own in the text, rather than a fragment?
     *
     * A bare substring is not good enough, and the failure is silent: a site
     * called car.example would match the word "cart" in an ordinary sentence,
     * and — because naming a site outranks the site already chosen — quietly
     * move the whole conversation to the wrong business.
     *
     * The boundary is Latin only. Hebrew runs straight into a Latin name with
     * no space ("בcafe"), and treating a Hebrew letter as part of the word
     * would stop the customer being able to name their site in the way they
     * actually write.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        return preg_match(
            '/(?<![a-z0-9_-])'.preg_quote($needle, '/').'(?![a-z0-9_-])/u',
            $haystack,
        ) === 1;
    }

    /**
     * Did they answer the numbered question with a number?
     *
     * Only when the whole message is that number. "2" is an answer; "תוריד את
     * המחיר ל-2" is not, and reading it as one would silently change which site
     * the next instruction lands on.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $bindings
     */
    private function position(string $text, Collection $bindings): ?SiteAgentSubscriber
    {
        $trimmed = trim($text, " \t\n\r\0\x0B.!?,־-");

        if (! ctype_digit($trimmed)) {
            return null;
        }

        return $bindings->get((int) $trimmed - 1);
    }

    /**
     * The question, with the list the numbers refer to.
     *
     * @param  Collection<int, SiteAgentSubscriber>  $bindings
     */
    public function question(Collection $bindings): string
    {
        $list = $bindings
            ->values()
            ->map(fn (SiteAgentSubscriber $binding, int $index): string => ($index + 1).'. '.($binding->site?->domain ?? ''))
            ->implode("\n");

        return implode("\n", [
            'המספר הזה מנהל יותר מאתר אחד. לאיזה אתר הבקשה?',
            '',
            $list,
            '',
            'אפשר להשיב במספר או בשם האתר.',
        ]);
    }
}
