<?php

namespace App\Services\SiteAgent;

use App\Models\Customer;
use App\Models\SiteAgentSubscriber;
use App\Support\CardLink;
use Illuminate\Support\Collection;

/**
 * ללקוח של בוט ניהול האתר — הכול מגיע מהמספר של הבוט.
 *
 * זו החלטה של מוצר ולא של תשתית: לקוח שמנהל את האתר שלו בשיחה עם מספר אחד אינו
 * אמור לקבל פתאום הודעה על אותו מנוי ממספר אחר, שנראה לו כמו מספר זר. המספר
 * הכללי נשאר למה שהוא באמת — פניות תמיכה שהלקוח פותח — ומופיע כפרט קשר לצד
 * כתובת המייל, במקום לשמש ככתובת השולח.
 *
 * **מה ההודעה נושאת תלוי במי מחזיק בטלפון, וזו הנקודה העדינה כאן.**
 *
 * המוצר מתיר במפורש להעביר את הסוכן לעובד או לסוכנות, כלומר המספר שמחובר לבוט
 * אינו בהכרח של בעל העסק. קישור תשלום חתום הוא הזמנה להקליד את פרטי הכרטיס של
 * העסק, ושליחתו למספר כזה היא מסירת דף התשלום של לקוח למי שבמקרה מחזיק במכשיר.
 * לכן קישור חתום נשלח רק כשהמספר שמחובר לבוט הוא גם המספר שברשומת הלקוח, ובכל
 * מקרה אחר נשלח קישור לאזור האישי — שאינו מוסר דבר, כי מי שפותח אותו עדיין
 * חייב להתחבר עם הפרטים שברשומה.
 */
class BotNumberRoute
{
    public function __construct(private readonly WhatsAppCloudClient $whatsapp) {}

    /**
     * The number this customer manages their site from, if they have one.
     *
     * Only a usable binding counts — verified, and not revoked. An unverified
     * number has not proved it belongs to anybody, and a revoked one was taken
     * away on purpose.
     */
    public function subscriber(Customer $customer): ?SiteAgentSubscriber
    {
        return rescue(function () use ($customer): ?SiteAgentSubscriber {
            /** @var Collection<int, SiteAgentSubscriber> $bindings */
            $bindings = SiteAgentSubscriber::query()
                ->where('customer_id', $customer->id)
                ->usable()
                ->latest('verified_at')
                ->get();

            if ($bindings->isEmpty()) {
                return null;
            }

            // The business's own number first, whenever it is among them.
            //
            // A customer may hold several bindings at once — the owner plus an
            // employee, or a manager per site — and extra managers are usually
            // added AFTER the owner. Taking the most recent would therefore
            // send the owner's payment notice to a manager, with only the
            // sign-in link, while the owner's own binding sat right there
            // unused; on a multi-site customer it could even land with the
            // manager of a different site entirely.
            foreach ($bindings as $binding) {
                $binding->setRelation('customer', $customer);

                if ($this->isCustomerOwnNumber($binding)) {
                    return $binding;
                }
            }

            return $bindings->first();
        }, null, report: false);
    }

    /**
     * Does the bot carry this customer's messages at all?
     *
     * A fact about the CUSTOMER, and deliberately not about whether the client
     * happens to be configured this minute. Mixing the two would mean that a
     * token being rotated — a few minutes, a routine act — quietly sends that
     * customer's signed payment page out over the support number instead,
     * which is the one thing this route exists to prevent. A client that cannot
     * send is a failure to report, not a different number to use.
     */
    public function carries(Customer $customer): bool
    {
        return $this->subscriber($customer) !== null;
    }

    /** Can the bot's number actually send right now? */
    public function available(): bool
    {
        return $this->whatsapp->configured();
    }

    /**
     * Is this the number on the customer record itself?
     *
     * Compared in the normalised form both sides are stored in, so 050-1234567
     * on the customer and 972501234567 on the subscriber are recognised as the
     * same person rather than treated as a stranger.
     */
    public function isCustomerOwnNumber(SiteAgentSubscriber $subscriber): bool
    {
        $customer = $subscriber->customer;

        if ($customer === null || $subscriber->phone === '') {
            return false;
        }

        // The phone on the customer record, and ONLY it.
        //
        // `whatsapp_jid` is deliberately excluded even though it identifies the
        // same customer: it is LEARNED, not declared. IngestWhatsappMessageJob
        // stamps it from whoever wrote to support first and matched this
        // customer, so an employee or an agency contacting us before the owner
        // does becomes the customer's recorded JID. Accepting it here would
        // classify that delegated binding as the business's own number and send
        // it the signed card page — the exact disclosure this route exists to
        // withhold, arriving through the back door.
        if (blank($customer->phone)) {
            return false;
        }

        return hash_equals($subscriber->phone, $this->whatsapp->normalize((string) $customer->phone));
    }

    /**
     * The payment link that is safe to send to THIS number.
     *
     * The signed card page only for the business's own number; for anybody
     * else, the sign-in page, which gives away nothing — whoever opens it still
     * has to receive a login link on the address or number the customer record
     * carries, which is exactly the check we would otherwise have to write here
     * and get right.
     */
    public function paymentLinkFor(SiteAgentSubscriber $subscriber): string
    {
        return $this->isCustomerOwnNumber($subscriber)
            ? CardLink::for($subscriber->customer_id)
            : route('portal.login');
    }
}
