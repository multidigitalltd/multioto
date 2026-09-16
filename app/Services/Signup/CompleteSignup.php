<?php

namespace App\Services\Signup;

use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\SiteStatus;
use App\Enums\TicketChannel;
use App\Jobs\GenerateCustomerCardPdfJob;
use App\Jobs\NotifySignupJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\PendingSignup;
use App\Models\SignupInvite;
use App\Models\Site;
use App\Services\Cardcom\CardTokenService;
use App\Services\Support\TicketIntake;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turn a filled-in signup into a customer — and the ONLY place the public form
 * ever creates one.
 *
 * Everything that used to happen at submit time happens here instead, once the
 * card is in hand. That ordering is the whole point: a customer record that
 * exists before the card is a customer we may never be able to collect from,
 * and it is indistinguishable from one who finished properly.
 *
 * Both paths in are idempotent. Cardcom announces a completed session twice —
 * a webhook and a browser redirect, in whichever order they arrive — and each
 * calls in here. A second call returns the customer the first one made; it
 * never opens a second customer, sends a second welcome, or saves a second card.
 */
class CompleteSignup
{
    /** Human labels for the non-card payment methods (for the follow-up ticket). */
    private const METHOD_LABELS = [
        'standing_order' => 'הוראת קבע בנקאית',
        'bank_transfer' => 'העברה בנקאית',
        'checks' => 'צ׳קים (מקדמה / תשלום מראש)',
    ];

    /**
     * Finish a signup whose card Cardcom has just captured.
     *
     * Returns null when the result carries no usable token: without one there
     * is nothing to create a customer for, and creating one anyway would
     * reintroduce the exact record this flow exists to prevent.
     *
     * @param  array<string, mixed>  $result  A Cardcom GetLpResult / webhook body.
     */
    public function withCard(PendingSignup $pending, array $result): ?Customer
    {
        if ((string) ($result['ResponseCode'] ?? '0') !== '0') {
            return null;
        }

        $tokenInfo = $result['TokenInfo'] ?? [];

        if (empty($tokenInfo['Token'])) {
            return null;
        }

        // Last-4 and brand live on the transaction, not the token.
        $tran = $result['TranzactionInfo'] ?? [];
        $cardInfo = array_merge($tokenInfo, [
            'CardLast4Digits' => $tran['Last4CardDigits'] ?? $tran['Last4CardDigitsString'] ?? $tokenInfo['CardLast4Digits'] ?? null,
            'CardBrand' => $tran['CardName'] ?? $tran['Brand'] ?? $tokenInfo['CardBrand'] ?? null,
        ]);

        return $this->finish($pending, function (Customer $customer) use ($cardInfo): void {
            // collectNow: false — nothing is owed yet. This customer has no
            // subscription; the team sets one up afterwards.
            app(CardTokenService::class)->store($customer, $cardInfo, collectNow: false);
        });
    }

    /**
     * Finish a signup a manager exempted from the card requirement.
     *
     * The exemption is stamped onto the customer with its reason and the person
     * who granted it, because that is what every later question needs: whether
     * to chase them for a card, whether their missing card is a fault worth
     * reporting, and — the day a payment does not arrive and there is nothing
     * to fall back on — who decided that.
     */
    public function exempt(PendingSignup $pending, SignupInvite $invite): Customer
    {
        return $this->finish($pending, function (Customer $customer) use ($invite): void {
            $customer->forceFill([
                'card_exempt_at' => now(),
                'card_exempt_reason' => $invite->exempt_reason,
                'card_exempt_by' => $invite->created_by,
            ])->save();

            // Single use: spent the moment it opens a customer, so one waiver
            // cannot become a link that keeps waiving the card for whoever it
            // is forwarded to.
            $invite->forceFill(['used_at' => now(), 'customer_id' => $customer->id])->save();
        });
    }

    /**
     * Create the customer and everything that follows it, exactly once.
     *
     * @param  callable(Customer): void  $attach  What this particular path adds
     *                                            before the welcome goes out.
     */
    private function finish(PendingSignup $pending, callable $attach): Customer
    {
        $lock = Cache::lock('signup-complete:'.$pending->id, 30);

        return $lock->block(10, function () use ($pending, $attach): Customer {
            $pending->refresh();

            // The webhook and the redirect both land here. Whichever was second
            // gets the customer the first one made.
            if ($pending->customer_id !== null && $pending->customer !== null) {
                return $pending->customer;
            }

            $customer = DB::transaction(function () use ($pending, $attach): Customer {
                $customer = Customer::create([
                    'name' => $pending->name,
                    'contact_name' => $pending->contact_name,
                    'business_number' => $pending->business_number,
                    'business_type' => $pending->business_type,
                    'vat_exempt' => $pending->vat_exempt,
                    'email' => strtolower((string) $pending->email),
                    'phone' => $pending->phone,
                    'payment_method' => $pending->payment_method,
                    'terms_accepted_at' => $pending->terms_accepted_at,
                    'security_card_terms_at' => $pending->security_card_terms_at,
                    'signature_path' => $pending->signature_path,
                    'signed_ip' => $pending->signed_ip,
                    'status' => CustomerStatus::Active,
                ]);

                if (filled($pending->domain)) {
                    Site::create([
                        'customer_id' => $customer->id,
                        'domain' => $pending->domain,
                        'monitor_url' => 'https://'.ltrim((string) $pending->domain, '/'),
                        'monitor_enabled' => true,
                        'status' => SiteStatus::Active,
                    ]);
                }

                $attach($customer);

                $pending->forceFill(['customer_id' => $customer->id, 'completed_at' => now()])->save();

                return $customer;
            });

            $this->announce($customer, (string) $pending->payment_method);

            return $customer;
        });
    }

    /**
     * Tell the team and welcome the customer. Dispatched after the transaction
     * commits, so no job can read a customer that is not there yet.
     */
    private function announce(Customer $customer, string $paymentMethod): void
    {
        NotifySignupJob::dispatch($customer->id);
        SendWelcomeMessageJob::dispatch($customer->id);
        GenerateCustomerCardPdfJob::dispatch($customer->id);

        if ($paymentMethod === 'credit_card') {
            return;
        }

        // The team completes the arrangement by hand — a ticket so it cannot
        // fall through. The card is no longer in doubt here: this customer
        // exists, so a card was captured (or a manager waived it on purpose).
        app(TicketIntake::class)->recordInbound(
            TicketChannel::Manual,
            MessageChannel::InternalNote,
            $customer,
            'לקוח חדש בחר '.(self::METHOD_LABELS[$paymentMethod] ?? $paymentMethod)
                .' — יש ליצור קשר ולהשלים את הסדר התשלום.',
            externalMessageId: 'signup-payment-'.$customer->id,
            subject: 'השלמת הסדר תשלום — '.$customer->name,
        );
    }
}
