<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\SiteStatus;
use App\Enums\TicketChannel;
use App\Http\Requests\SignupRequest;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\Site;
use App\Services\Support\TicketIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Public self-signup: the link the team sends to a prospect. The customer fills
 * their details and is sent to Cardcom's hosted page to enter a card. It opens a
 * new customer WITH a valid saved card — no plan is chosen here. Subscriptions
 * are custom per customer and are set up by the team afterwards; the captured
 * card is then ready to charge.
 *
 * No card data touches this controller — PCI scope stays with Cardcom.
 */
class SignupController extends Controller
{
    public function show(): View
    {
        return view('signup.form');
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $businessType = BusinessType::from($data['business_type']);

        $customer = DB::transaction(function () use ($data, $businessType): Customer {
            $customer = Customer::create([
                'name' => $data['name'],
                'contact_name' => $data['contact_name'],
                'business_number' => $data['business_number'] ?? null,
                'business_type' => $businessType,
                // Exempt dealers are VAT-exempt; everyone else is charged VAT.
                'vat_exempt' => $businessType === BusinessType::ExemptDealer,
                'email' => strtolower($data['email']),
                'phone' => $data['phone'],
                'address' => $data['address'],
                'payment_method' => $data['payment_method'],
                // The legal record of consent — set only when the box was ticked
                // (validation enforces it), stamped server-side.
                'terms_accepted_at' => now(),
                'status' => CustomerStatus::Active,
            ]);

            // Record the site (if given) so monitoring starts right away.
            if (! empty($data['domain'])) {
                $domain = preg_replace('#^https?://#', '', trim($data['domain']));
                Site::create([
                    'customer_id' => $customer->id,
                    'domain' => $domain,
                    'monitor_url' => 'https://'.ltrim($domain, '/'),
                    'monitor_enabled' => true,
                    'status' => SiteStatus::Active,
                ]);
            }

            // No subscription is created here — the customer's plan is custom and
            // set up by the team afterwards, then the captured card is charged.
            return $customer;
        });

        // Personal welcome (email + WhatsApp) — dispatched only from this
        // explicit signup flow, never from bulk import.
        SendWelcomeMessageJob::dispatch($customer->id);

        // Credit card: hand off to Cardcom's hosted card page via a short-lived
        // signed link (same route used for card updates), so no customer id is
        // enumerable. No card data ever touches this system.
        if ($data['payment_method'] === 'credit_card') {
            return redirect()->to(URL::temporarySignedRoute(
                'billing.update-card',
                now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                ['customer' => $customer->id],
            ));
        }

        // Standing order / bank transfer: the team completes the arrangement
        // manually — open a ticket so it can't fall through the cracks.
        app(TicketIntake::class)->recordInbound(
            TicketChannel::Manual,
            MessageChannel::InternalNote,
            $customer,
            'לקוח חדש בחר '.($data['payment_method'] === 'standing_order' ? 'הוראת קבע בנקאית' : 'העברה בנקאית')
                .' — יש ליצור קשר ולהשלים את הסדר התשלום.',
            externalMessageId: 'signup-payment-'.$customer->id,
            subject: 'השלמת הסדר תשלום — '.$customer->name,
        );

        return redirect()->route('signup.thanks');
    }
}
