<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketChannel;
use App\Http\Requests\SignupRequest;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Support\TicketIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Public self-signup: the link the team sends to a prospect. The customer picks
 * a plan, fills their own details, and is then sent to Cardcom's hosted page to
 * enter a card. Card capture + subscription activation reuse the existing flow
 * (ProcessCardcomLowProfileJob), so the first charge runs once the card lands.
 *
 * No card data touches this controller — PCI scope stays with Cardcom.
 */
class SignupController extends Controller
{
    public function show(): View
    {
        return view('signup.form', [
            'plans' => Plan::where('active', true)->orderBy('price_agorot')->get(),
            'vatRate' => (float) config('billing.vat_rate'),
        ]);
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

            $siteId = null;
            if (! empty($data['domain'])) {
                $domain = preg_replace('#^https?://#', '', trim($data['domain']));
                $siteId = Site::create([
                    'customer_id' => $customer->id,
                    'domain' => $domain,
                    'monitor_url' => 'https://'.ltrim($domain, '/'),
                    'monitor_enabled' => true,
                    'status' => SiteStatus::Active,
                ])->id;
            }

            Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $data['plan_id'],
                'site_id' => $siteId,
                // Trialing + due now: once the card is captured the subscription
                // activates and the first charge is collected immediately.
                'status' => SubscriptionStatus::Trialing,
                'next_charge_at' => now(),
            ]);

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
