<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\SiteStatus;
use App\Enums\TicketChannel;
use App\Http\Requests\SignupRequest;
use App\Jobs\GenerateCustomerCardPdfJob;
use App\Jobs\NotifySignupJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Support\TicketIntake;
use App\Support\CardLink;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // The tax notice is optional and can be hidden by clearing it. A stored
        // empty value means "hidden"; only fall back to the config default when
        // no row exists (the config overlay ignores blanks, so read it directly).
        $stored = Setting::map();
        $taxNotice = array_key_exists('signup.tax_approval_notice', $stored)
            ? $stored['signup.tax_approval_notice']
            : config('billing.signup.tax_approval_notice');

        return view('signup.form', [
            'instructions' => config('billing.signup.instructions'),
            'taxNotice' => $taxNotice,
        ]);
    }

    /**
     * File a signup.
     *
     * Sending the same form twice must not open a second customer. The whole
     * body is serialised on a fingerprint of the submission, so two clicks
     * landing at once queue behind each other instead of racing to insert —
     * without it the duplicate check reads an empty table in both requests and
     * passes in both.
     */
    public function store(SignupRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $lock = Cache::lock('signup:'.$this->fingerprint($data), 30);

        try {
            $wait = max(0, (int) config('billing.signup.lock_wait_seconds', 10));

            return $lock->block($wait, fn (): RedirectResponse => $this->file($data, $request->ip()));
        } catch (LockTimeoutException) {
            // Waited and never got the lock. Running the body anyway would put
            // two requests inside the very section this lock exists to hold one
            // at a time — both would read an empty table and both would insert.
            //
            // So look once more (the other request may have committed while we
            // waited), and otherwise say so and hand the form back with
            // everything still in it. Asking someone to press again is a far
            // smaller harm than opening the duplicate we came here to prevent.
            if ($existing = $this->alreadyFiled($data)) {
                return $this->redirectAfter($existing, $data['payment_method']);
            }

            return back()->withInput()->withErrors([
                'signup' => 'השליחה הקודמת עדיין מתבצעת. המתינו רגע ונסו שוב — הפרטים נשמרו בטופס.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function file(array $data, ?string $ip): RedirectResponse
    {
        // The same form, arriving again. A customer who clicks "אישור וסיום"
        // a second time because nothing seemed to happen is not a second
        // customer: they would otherwise get a duplicate customer row, a
        // duplicate site (monitored twice), a duplicate welcome message and a
        // duplicate follow-up ticket — every click.
        if ($existing = $this->alreadyFiled($data)) {
            return $this->redirectAfter($existing, $data['payment_method']);
        }

        $businessType = BusinessType::from($data['business_type']);
        $signaturePath = $this->storeSignature($data['signature']);

        $customer = DB::transaction(function () use ($data, $businessType, $signaturePath, $ip): Customer {
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
                'signed_ip' => $ip,
                'status' => CustomerStatus::Active,
            ]);

            // Record the site (if given) so monitoring starts right away.
            if (($domain = $this->domainFrom($data)) !== null) {
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

        // Tell the team a customer just signed up — WhatsApp + email + bell —
        // for EVERY payment method (a credit-card signup opens no ticket, so this
        // is the only signal there). Queued so it never blocks the response.
        NotifySignupJob::dispatch($customer->id);

        // Personal welcome (email + WhatsApp) — dispatched only from this
        // explicit signup flow, never from bulk import.
        SendWelcomeMessageJob::dispatch($customer->id);

        // Generate the signed "customer card" PDF (details + signature), store it
        // on the customer, and email it to them with a thank-you. Heavy work runs
        // on the queue, never in this request.
        GenerateCustomerCardPdfJob::dispatch($customer->id);

        // Standing order / bank transfer / cheques: the team completes the
        // arrangement manually — open a ticket so it can't fall through the
        // cracks. (Credit card needs no ticket: the customer enters the card
        // themselves on the next screen.)
        if ($data['payment_method'] !== 'credit_card') {
            $label = self::METHOD_LABELS[$data['payment_method']] ?? $data['payment_method'];

            app(TicketIntake::class)->recordInbound(
                TicketChannel::Manual,
                MessageChannel::InternalNote,
                $customer,
                'לקוח חדש בחר '.$label.' — יש ליצור קשר ולהשלים את הסדר התשלום.',
                externalMessageId: 'signup-payment-'.$customer->id,
                subject: 'השלמת הסדר תשלום — '.$customer->name,
            );
        }

        return $this->redirectAfter($customer, $data['payment_method']);
    }

    /**
     * Where the customer goes once their details are filed — identical whether
     * this submission opened the customer or was the same form arriving twice.
     */
    private function redirectAfter(Customer $customer, string $method): RedirectResponse
    {
        // Credit card: hand off to the embedded Cardcom card page via a
        // short-lived signed link (same route used for card updates), so no
        // customer id is enumerable. No card data ever touches this system.
        if ($method === 'credit_card') {
            return redirect()->to(CardLink::for($customer->id));
        }

        return redirect()->route('signup.thanks')->with([
            'payment_method_label' => self::METHOD_LABELS[$method] ?? $method,
            'payment_instructions' => config('billing.signup.instructions.'.$method),
        ]);
    }

    /**
     * The customer this exact submission already opened, if it did.
     *
     * Matched on every identifying field the form collects, not on the email
     * alone. A resubmission that differs in any of them is a different filing
     * and is treated as one — collapsing it onto the earlier row would discard
     * whatever the customer changed, silently, which is worse than a duplicate.
     *
     * The window is short by design: this is here to absorb a second click on
     * one form, not to decide what a customer signing up again months later
     * means. Nothing is written to the existing record either way, so knowing
     * somebody's details buys no way to overwrite their customer card.
     *
     * @param  array<string, mixed>  $data
     */
    private function alreadyFiled(array $data): ?Customer
    {
        $window = (int) config('billing.signup.duplicate_window_minutes');

        if ($window <= 0) {
            return null;
        }

        $number = $data['business_number'] ?? null;
        $domain = $this->domainFrom($data);

        return Customer::query()
            ->where('created_at', '>=', now()->subMinutes($window))
            ->where('email', strtolower($data['email']))
            ->where('name', $data['name'])
            ->where('contact_name', $data['contact_name'])
            ->where('phone', $data['phone'])
            ->where('business_type', $data['business_type'])
            ->where('payment_method', $data['payment_method'])
            ->when(
                $number === null,
                fn ($q) => $q->whereNull('business_number'),
                fn ($q) => $q->where('business_number', $number),
            )
            // The site counts too. The same business filing again for a SECOND
            // domain keeps every other field identical, and collapsing that
            // would drop the new site out of monitoring without a word — the
            // silent loss this whole check is shaped to avoid.
            ->when(
                $domain === null,
                fn ($q) => $q->whereDoesntHave('sites'),
                fn ($q) => $q->whereHas('sites', fn ($s) => $s->where('domain', $domain)),
            )
            ->latest('id')
            ->first();
    }

    /**
     * The domain as this form stores it — scheme stripped — or null when none
     * was given. One place, so what gets written and what gets compared cannot
     * drift apart.
     *
     * @param  array<string, mixed>  $data
     */
    private function domainFrom(array $data): ?string
    {
        $domain = trim((string) ($data['domain'] ?? ''));

        return $domain === '' ? null : (string) preg_replace('#^https?://#', '', $domain);
    }

    /**
     * A stable key for one filing of the form — the same fields the duplicate
     * check compares, so the lock and the check agree on what "the same
     * submission" means.
     *
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data): string
    {
        return hash('sha256', implode('|', [
            strtolower((string) $data['email']),
            (string) $data['name'],
            (string) $data['contact_name'],
            (string) $data['phone'],
            (string) $data['business_type'],
            (string) $data['payment_method'],
            (string) ($data['business_number'] ?? ''),
            (string) $this->domainFrom($data),
        ]));
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
