<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Http\Requests\SignupRequest;
use App\Models\PendingSignup;
use App\Models\Setting;
use App\Models\SignupInvite;
use App\Services\Signup\CompleteSignup;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Public self-signup: the multi-step "open a customer" form the team sends to a
 * prospect. The customer fills their details, signs, and picks how they pay.
 *
 * A card is required of everyone, and this controller no longer creates the
 * customer. The details wait in `pending_signups` and become a customer only
 * once Cardcom hands back a token — the card page used to be the last step
 * AFTER the customer was saved, which meant closing the tab left a customer
 * nobody could collect from and nobody could tell apart from a finished one.
 *
 * The single exception is an invite a manager issued with the card waived. It
 * is recorded, single-use and attributed; see SignupInvite.
 *
 * No card data touches this controller — PCI scope stays with Cardcom.
 */
class SignupController extends Controller
{
    public function show(Request $request): View
    {
        // The tax notice is optional and can be hidden by clearing it. A stored
        // empty value means "hidden"; only fall back to the config default when
        // no row exists (the config overlay ignores blanks, so read it directly).
        $stored = Setting::map();
        $taxNotice = array_key_exists('signup.tax_approval_notice', $stored)
            ? $stored['signup.tax_approval_notice']
            : config('billing.signup.tax_approval_notice');

        $invite = $this->inviteFrom($request);

        return view('signup.form', [
            'instructions' => config('billing.signup.instructions'),
            'taxNotice' => $taxNotice,
            // Carried through the form so the exemption survives the POST, and
            // so the terms the customer ticks say what is actually true for
            // them: an exempt signup must not promise a card it never takes.
            'invite' => $invite,
            'cardRequired' => ! ($invite?->waivesCard() ?? false),
            'prefill' => [
                'name' => $invite?->name,
                'email' => $invite?->email,
                'phone' => $invite?->phone,
            ],
        ]);
    }

    /**
     * File a signup.
     *
     * Sending the same form twice must not open a second pending signup. The
     * whole body is serialised on a fingerprint of the submission, so two
     * clicks landing at once queue behind each other instead of racing.
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
            if ($existing = $this->alreadyFiled($data)) {
                return $this->handOff($existing);
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
        // The same form, arriving again. A customer who clicks "אישור וסיום" a
        // second time because nothing seemed to happen is not a second signup.
        if ($existing = $this->alreadyFiled($data)) {
            return $this->handOff($existing);
        }

        $businessType = BusinessType::from($data['business_type']);
        $invite = SignupInvite::query()->where('token', $data['invite'] ?? '')->first();

        $pending = PendingSignup::create([
            'name' => $data['name'],
            'contact_name' => $data['contact_name'],
            'business_number' => $data['business_number'] ?? null,
            'business_type' => $businessType->value,
            // Exempt dealers are VAT-exempt; everyone else is charged VAT.
            'vat_exempt' => $businessType === BusinessType::ExemptDealer,
            'email' => strtolower($data['email']),
            'phone' => $data['phone'],
            'domain' => $this->domainFrom($data),
            'payment_method' => $data['payment_method'],
            // The legal record of consent — the box was ticked (validation
            // enforces it) and the customer signed. Stamped server-side.
            'terms_accepted_at' => now(),
            // Recorded only when the terms the customer just ticked actually
            // carried the security-card clause. A signup the manager exempted
            // never showed that clause, so nobody agreed to it and stamping it
            // would manufacture a consent.
            'security_card_terms_at' => $this->securityCardApplies($invite) ? now() : null,
            'signature_path' => $this->storeSignature($data['signature']),
            'signed_ip' => $ip,
        ]);

        // The exception: a manager waived the card for this one prospect. The
        // customer is created now, because there is no card to wait for.
        if ($invite?->waivesCard()) {
            $customer = app(CompleteSignup::class)->exempt($pending, $invite);

            return redirect()->route('signup.done', ['pending' => $pending->token])
                ->with('customerId', $customer->id);
        }

        return $this->handOff($pending);
    }

    /** Whether this signup is being asked for a security card at all. */
    private function securityCardApplies(?SignupInvite $invite): bool
    {
        return ! ($invite?->waivesCard() ?? false)
            && (int) config('billing.card_fallback_days', 0) > 0;
    }

    /**
     * Where the customer goes once their details are filed: the card page.
     *
     * EVERY customer passes through it, whatever they chose to pay by. A card
     * is required from all of them as security — somebody paying by transfer is
     * still not charged on it, but it is what covers the payment that never
     * arrives.
     */
    private function handOff(PendingSignup $pending): RedirectResponse
    {
        if ($pending->completed_at !== null) {
            // Already finished — the same form arriving after the card went in.
            return redirect()->route('signup.done', ['pending' => $pending->token]);
        }

        return redirect()->route('signup.card', ['pending' => $pending->token]);
    }

    /**
     * The pending signup this exact submission already opened, if it did.
     *
     * Matched on every identifying field the form collects, not on the email
     * alone. A resubmission that differs in any of them is a different filing
     * and is treated as one — collapsing it onto the earlier row would discard
     * whatever the customer changed, silently, which is worse than a duplicate.
     *
     * @param  array<string, mixed>  $data
     */
    private function alreadyFiled(array $data): ?PendingSignup
    {
        $window = (int) config('billing.signup.duplicate_window_minutes');

        if ($window <= 0) {
            return null;
        }

        $number = $data['business_number'] ?? null;
        $domain = $this->domainFrom($data);

        return PendingSignup::query()
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
            // would drop the new site out of monitoring without a word.
            ->when(
                $domain === null,
                fn ($q) => $q->whereNull('domain'),
                fn ($q) => $q->where('domain', $domain),
            )
            ->latest('id')
            ->first();
    }

    /** The invite this visit carries, if it names a real one. */
    private function inviteFrom(Request $request): ?SignupInvite
    {
        $token = trim((string) $request->query('invite', ''));

        return $token === '' ? null : SignupInvite::query()->where('token', $token)->first();
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
