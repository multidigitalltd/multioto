<?php

namespace App\Services\Support;

use App\Enums\BroadcastChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Broadcast;
use App\Models\Customer;

/**
 * Turns one broadcast into the exact message a given customer receives:
 * placeholders resolved against that customer, plus the legal footer an
 * advertising message must carry.
 *
 * Placeholders use the same {{token}} syntax as the notification templates, so
 * there is one thing to learn. An unknown token resolves to empty rather than
 * reaching a customer as literal "{{שם}}" — the same promise the template
 * editor already makes.
 */
class BroadcastRenderer
{
    /**
     * Tokens offered in the panel, as token => short Hebrew explanation.
     * Order is the order they are listed to the operator.
     */
    public const TOKENS = [
        'שם' => 'שם הלקוח / העסק',
        'איש_קשר' => 'שם איש הקשר, אם קיים — אחרת שם הלקוח',
        'אתר' => 'הדומיין של האתר הראשון של הלקוח',
        'חבילה' => 'שם החבילה במנוי הפעיל',
        'עסק' => 'שם העסק שלך (השולח)',
    ];

    /** Subscription statuses that mean "no longer the customer's package". */
    private const ENDED_STATUSES = [SubscriptionStatus::Canceled];

    public function __construct(private MarketingPreferences $preferences) {}

    /** The subject line for this customer (email only; null on WhatsApp). */
    public function subject(Broadcast $broadcast, Customer $customer): string
    {
        return $this->substitute((string) $broadcast->subject, $customer);
    }

    /**
     * The full message body for this customer, footer included.
     *
     * The footer is not decoration: for an advertising message the law wants
     * the word "פרסומת", the sender's identity, and a working way out — in the
     * medium the message arrived on.
     */
    public function body(Broadcast $broadcast, Customer $customer): string
    {
        $body = $this->substitute((string) $broadcast->body, $customer);

        if (! $broadcast->is_marketing) {
            return $body;
        }

        return $broadcast->channel === BroadcastChannel::Whatsapp
            ? $body."\n\n—\nפרסומת מאת ".$this->businessName()."\nלהסרה מרשימת התפוצה: השיבו \"הסר\"."
            : $body;
    }

    /**
     * Email footer parts, rendered by the mail template rather than glued into
     * the body — an email footer wants markup and a real link, not plain text.
     *
     * The sender's full identity (name, address, phone) is NOT repeated here:
     * it comes from "כותרת תחתונה למיילים" in the mail settings and is already
     * printed at the bottom of every email by the shared mail layout. Duplicating
     * it would mean two conflicting addresses on the same message whenever the
     * operator updates one of them.
     *
     * @param  bool  $preview  a test send to the operator's own inbox. The
     *                         unsubscribe link is withheld: it lands in OUR
     *                         inbox but acts on the sample CUSTOMER, so one
     *                         curious click while checking the layout would
     *                         opt out a real customer who never asked.
     * @return array{is_marketing: bool, business: string, support: ?string, unsubscribe_url: ?string}
     */
    public function emailFooter(Broadcast $broadcast, Customer $customer, bool $preview = false): array
    {
        return [
            'is_marketing' => (bool) $broadcast->is_marketing,
            'business' => $this->businessName(),
            'support' => config('billing.email.support_address') ?: null,
            'unsubscribe_url' => $preview ? null : $this->preferences->unsubscribeUrl($customer),
        ];
    }

    /** Resolve every {{token}} against this customer. */
    private function substitute(string $text, Customer $customer): string
    {
        $site = $customer->relationLoaded('sites')
            ? $customer->sites->first()
            : $customer->sites()->orderBy('id')->first();

        // {{חבילה}} is presented as the customer's CURRENT package, so a
        // canceled subscription from two years ago must not win just because it
        // has the lowest id. Both paths apply the same filter and the same
        // ordering, or the placeholder would differ depending on whether the
        // caller happened to eager-load.
        $plan = ($customer->relationLoaded('subscriptions')
            ? $customer->subscriptions->whereNotIn('status', self::ENDED_STATUSES)->sortByDesc('id')->first()
            : $customer->subscriptions()->with('plan')->whereNotIn('status', self::ENDED_STATUSES)->latest('id')->first()
        )?->plan;

        $values = [
            'שם' => (string) $customer->name,
            'איש_קשר' => (string) ($customer->contact_name ?: $customer->name),
            'אתר' => (string) ($site?->domain ?? ''),
            'חבילה' => (string) ($plan?->name ?? ''),
            'עסק' => $this->businessName(),
        ];

        foreach ($values as $token => $value) {
            $text = str_replace('{{'.$token.'}}', $value, $text);
        }

        // Anything left is a typo or a token we do not offer. Blanking it beats
        // sending a customer a message with braces in it.
        return preg_replace('/\{\{[^{}]{0,60}\}\}/u', '', $text) ?? $text;
    }

    /**
     * The sender name, taken from "שם שולח" in the mail settings — the same
     * source the mail header, the notification templates and every other
     * customer-facing message already use.
     */
    private function businessName(): string
    {
        return (string) (config('mail.from.name') ?: config('app.name'));
    }
}
