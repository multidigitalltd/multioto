<?php

namespace App\Services\Support;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\SystemLog;
use Illuminate\Support\Facades\URL;

/**
 * Opting in and out of marketing.
 *
 * חוק התקשורת (בזק ושידורים), סעיף 30א requires a commercial message to carry
 * an opt-out route in the medium it arrived on, and requires that route to
 * work. So: every marketing email carries a signed link, every marketing
 * WhatsApp message says to reply "הסר", and both land here.
 *
 * The links are signed rather than guessable ids, so nobody can walk the
 * customer table unsubscribing other people's accounts.
 */
class MarketingPreferences
{
    /** The word a customer replies on WhatsApp to stop marketing. */
    public const OPT_OUT_WORDS = ['הסר', 'הסירו', 'הסרה', 'להסיר', 'stop', 'unsubscribe'];

    /** How long an unsubscribe link stays valid — long enough to be fair. */
    private const LINK_DAYS = 120;

    public function unsubscribeUrl(Customer $customer): string
    {
        return URL::temporarySignedRoute(
            'marketing.unsubscribe',
            now()->addDays(self::LINK_DAYS),
            ['customer' => $customer->getKey()],
        );
    }

    /** Does this inbound message read as an opt-out request? */
    public function looksLikeOptOut(string $body): bool
    {
        // Only a message that is *just* the word counts. "אל תסירו אותי
        // מהרשימה" or a sentence that happens to contain it is a support
        // question, and silently unsubscribing on it would be worse than
        // missing the request.
        $normalized = trim(mb_strtolower(strip_tags($body)));
        $normalized = trim($normalized, ".,!?'\"־-–— \t\n\r");

        return in_array($normalized, self::OPT_OUT_WORDS, true);
    }

    public function optOut(Customer $customer, string $channel): void
    {
        if ($customer->hasOptedOutOfMarketing()) {
            return;
        }

        $customer->update([
            'marketing_opt_out_at' => now(),
            'marketing_opt_out_channel' => $channel,
        ]);

        SystemLog::record('info', 'support',
            "הלקוח {$customer->name} הוסר מרשימת הדיוור (דרך {$channel}).",
            ['customer_id' => $customer->id]);

        AuditLog::record('updated', "הסרה מרשימת דיוור — {$customer->name} (דרך {$channel})", $customer);
    }

    public function optIn(Customer $customer, string $channel): void
    {
        if (! $customer->hasOptedOutOfMarketing()) {
            return;
        }

        $customer->update(['marketing_opt_out_at' => null, 'marketing_opt_out_channel' => null]);

        SystemLog::record('info', 'support',
            "הלקוח {$customer->name} חזר לרשימת הדיוור (דרך {$channel}).",
            ['customer_id' => $customer->id]);

        AuditLog::record('updated', "חזרה לרשימת דיוור — {$customer->name} (דרך {$channel})", $customer);
    }
}
