<?php

namespace App\Services\Support;

use App\Enums\BroadcastChannel;
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
     * @return array{is_marketing: bool, business: string, address: ?string, unsubscribe_url: string}
     */
    public function emailFooter(Broadcast $broadcast, Customer $customer): array
    {
        return [
            'is_marketing' => (bool) $broadcast->is_marketing,
            'business' => $this->businessName(),
            'address' => config('billing.business.address') ?: null,
            'unsubscribe_url' => $this->preferences->unsubscribeUrl($customer),
        ];
    }

    /** Resolve every {{token}} against this customer. */
    private function substitute(string $text, Customer $customer): string
    {
        $site = $customer->relationLoaded('sites')
            ? $customer->sites->first()
            : $customer->sites()->orderBy('id')->first();

        $plan = $customer->relationLoaded('subscriptions')
            ? $customer->subscriptions->first()?->plan
            : $customer->subscriptions()->with('plan')->orderBy('id')->first()?->plan;

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

    private function businessName(): string
    {
        // Same fallback chain the notification templates use for {{business_name}}.
        return (string) (config('billing.business.name')
            ?: config('mail.from.name')
            ?: config('app.name'));
    }
}
