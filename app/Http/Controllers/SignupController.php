<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\SiteStatus;
use App\Enums\TicketChannel;
use App\Http\Requests\SignupRequest;
use App\Jobs\GenerateCustomerCardPdfJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\Site;
use App\Services\Support\TicketIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Public self-signup: the multi-step "open a customer" form the team sends to a
 * prospect. The customer fills their details, signs, and picks how they pay.
 * It opens a new customer WITH a signed consent record — no plan is chosen here;
 * subscriptions are custom per customer and set up by the team afterwards.
 *
 * Credit-card customers then enter a card inside an embedded Cardcom iframe;
 * standing-order / bank-transfer / cheque customers get setup instructions and
 * an internal follow-up ticket. No card data touches this controller — PCI
 * scope stays with Cardcom.
 */
class SignupController extends Controller
{
    /** Human labels for the non-card payment methods (for the follow-up ticket). */
    private const METHOD_LABELS = [
        'standing_order' => 'הוראת קבע בנקאית',
        'bank_transfer' => 'העברה בנקאית',
        'checks' => 'צ׳קים (מקדמה / תשלום מראש)',
    ];

    public function show(): View
    {
        return view('signup.form', [
            'instructions' => config('billing.signup.instructions'),
            'taxNotice' => config('billing.signup.tax_approval_notice'),
        ]);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $businessType = BusinessType::from($data['business_type']);
        $signaturePath = $this->storeSignature($data['signature']);

        $customer = DB::transaction(function () use ($data, $businessType, $signaturePath, $request): Customer {
            $customer = Customer::create([
                'name' => $data['name'],
                'contact_name' => $data['contact_name'],
                'business_number' => $data['business_number'] ?? null,
                'business_type' => $businessType,
                // Exempt dealers are VAT-exempt; everyone else is charged VAT.
                'vat_exempt' => $businessType === BusinessType::ExemptDealer,
                'email' => strtolower($data['email']),
                'phone' => $data['phone'],
                'payment_method' => $data['payment_method'],
                // The legal record of consent — the box was ticked (validation
                // enforces it) and the customer signed. Stamped server-side with
                // the filer's IP.
                'terms_accepted_at' => now(),
                'signature_path' => $signaturePath,
                'signed_ip' => $request->ip(),
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

        // Generate the signed "customer card" PDF (details + signature), store it
        // on the customer, and email it to them with a thank-you. Heavy work runs
        // on the queue, never in this request.
        GenerateCustomerCardPdfJob::dispatch($customer->id);

        // Credit card: hand off to the embedded Cardcom card page via a
        // short-lived signed link (same route used for card updates), so no
        // customer id is enumerable. No card data ever touches this system.
        if ($data['payment_method'] === 'credit_card') {
            return redirect()->to(URL::temporarySignedRoute(
                'billing.update-card',
                now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                ['customer' => $customer->id],
            ));
        }

        // Standing order / bank transfer / cheques: the team completes the
        // arrangement manually — open a ticket so it can't fall through the
        // cracks, and show the customer the setup instructions on the way out.
        $label = self::METHOD_LABELS[$data['payment_method']] ?? $data['payment_method'];

        app(TicketIntake::class)->recordInbound(
            TicketChannel::Manual,
            MessageChannel::InternalNote,
            $customer,
            'לקוח חדש בחר '.$label.' — יש ליצור קשר ולהשלים את הסדר התשלום.',
            externalMessageId: 'signup-payment-'.$customer->id,
            subject: 'השלמת הסדר תשלום — '.$customer->name,
        );

        return redirect()->route('signup.thanks')->with([
            'payment_method_label' => $label,
            'payment_instructions' => config('billing.signup.instructions.'.$data['payment_method']),
        ]);
    }

    /**
     * Decode the canvas PNG data URL and store it on the private disk as the
     * signed consent record. The format is pinned to PNG by validation, so only
     * an image is ever written; the filename is derived server-side (never from
     * user input) and lives outside the web root.
     */
    private function storeSignature(string $dataUrl): string
    {
        $base64 = substr($dataUrl, strlen('data:image/png;base64,'));
        $binary = base64_decode(str_replace(["\r", "\n"], '', $base64), true) ?: '';

        $path = 'signatures/'.now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.png';
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
