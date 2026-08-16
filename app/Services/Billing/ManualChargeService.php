<?php

namespace App\Services\Billing;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Backup\OperationGate;
use App\Services\Cardcom\CardcomClient;
use App\Support\CardcomWebhook;
use App\Support\PaymentLink;
use Illuminate\Support\Str;

/**
 * One-off (manual) charging, shared by the "חיוב ידני" page and the quick
 * "חיוב חדש" action on the customer card, so the money logic lives in one place.
 * Amounts are VAT-inclusive totals in agorot; the net/VAT split is derived here.
 */
class ManualChargeService
{
    public function __construct(private CardcomClient $cardcom) {}

    public function hasActiveToken(Customer $customer): bool
    {
        return $customer->paymentTokens()->where('status', TokenStatus::Active)->exists();
    }

    /**
     * Create a pending one-off charge and queue it against the customer's saved
     * active token.
     */
    /**
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    public function chargeSavedToken(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], ?bool $vatExempt = null): Charge
    {
        $charge = $this->createPendingCharge($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);
        ProcessManualChargeJob::dispatch($charge->id);

        return $charge;
    }

    /**
     * Create a hosted Cardcom payment page (card entered on Cardcom, never here)
     * for a customer without a saved card.
     *
     * @return array{charge: Charge, url: string}
     *
     * @throws \RuntimeException when Cardcom returns no payment URL (charge marked failed)
     */
    /**
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    public function createHostedPage(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], ?bool $vatExempt = null, bool $withToken = false, ?string $successUrl = null, ?string $failureUrl = null): array
    {
        // A payment page is payable the moment it exists, and the row that says
        // which charge it belongs to is what a restore would replace — leaving
        // a customer able to pay into nothing this system can match.
        if (app(OperationGate::class)->isRunning()) {
            throw new \RuntimeException(
                'פעולת גיבוי או שחזור רצה כרגע — לא נוצר עמוד תשלום. נסו שוב בעוד כמה דקות.'
            );
        }

        $charge = $this->createPendingCharge($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);

        try {
            $lowProfile = $this->cardcom->createChargeLowProfile(
                $charge->id,
                $totalAgorot,
                $description,
                $customer->name,
                $customer->email,
                $customer->phone,
                // Where the payer lands afterwards. The default is the team's
                // generic notice; a self-service purchase sends them back to
                // their own order instead, which is the only page that can tell
                // them what they now own.
                $successUrl ?? route('billing.update-card.done', ['result' => 'success']),
                $failureUrl ?? route('billing.update-card.done', ['result' => 'failed']),
                CardcomWebhook::url(),
                $withToken,
            );
        } catch (\Throwable $e) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'יצירת עמוד תשלום נכשלה']);

            throw new \RuntimeException('יצירת עמוד התשלום נכשלה: '.Str::limit($e->getMessage(), 150), 0, $e);
        }

        if (blank($lowProfile['url'])) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'קארדקום לא החזירה כתובת תשלום']);

            throw new \RuntimeException('קארדקום לא החזירה עמוד תשלום');
        }

        // Store the raw Cardcom page so our own signed gateway can redirect to it
        // (and stop redirecting once the demand is paid or canceled).
        $charge->update([
            'cardcom_low_profile_id' => $lowProfile['low_profile_id'],
            'cardcom_pay_url' => $lowProfile['url'],
            // Direct-to-Bit URL (empty when the terminal has no Bit) — offered as
            // a one-tap option alongside the card link.
            'cardcom_bit_url' => $lowProfile['bit_url'] ?? '',
        ]);

        return [
            'charge' => $charge,
            'url' => $lowProfile['url'],           // the raw Cardcom page (team "open now")
            'pay_url' => PaymentLink::for($charge->id), // the cancelable link we hand the customer
        ];
    }

    /**
     * Try a failed one-off charge again.
     *
     * A card declines for reasons that pass: no funds this morning, a bank's
     * fraud rule, an expired card the customer has since replaced. Until now the
     * only way past that was to retype the whole charge from memory, which is
     * how a failed charge quietly turns into money nobody collected.
     *
     * A NEW charge row is created rather than the old one revived. The failed
     * attempt is what Cardcom answered and stays exactly as it is — the rule
     * that every response is recorded is worth more than a tidy list — and the
     * new row carries the next attempt number, so the history reads as what it
     * was: a second try.
     *
     * How it is collected depends on what the customer has now, not on how it
     * was tried before: a card saved since the failure is used, and without one
     * a fresh payment page is created.
     *
     * @return array{charge: Charge, method: string, pay_url: ?string}
     */
    public function retry(Charge $failed): array
    {
        if ($failed->status !== ChargeStatus::Failed) {
            throw new \RuntimeException('אפשר לחייב שוב רק חיוב שנכשל.');
        }

        if ($failed->subscription_id !== null) {
            // A subscription's failed charge belongs to the dunning ladder,
            // which retries it on its own schedule and tells the customer what
            // is happening. A second charge from here would collect twice on a
            // day the ladder also fires.
            throw new \RuntimeException('זהו חיוב של מנוי — הניסיון החוזר שלו מנוהל על ידי מנגנון הגבייה, לא מכאן.');
        }

        $customer = $failed->customer;

        if ($customer === null) {
            throw new \RuntimeException('לחיוב הזה אין לקוח, ולכן אי אפשר לחייב שוב.');
        }

        $lines = is_array($failed->lines) ? $failed->lines : [];
        $attempt = (int) $failed->attempt_number + 1;

        if ($this->hasActiveToken($customer)) {
            $charge = $this->createPendingCharge(
                $customer, (int) $failed->total_agorot, (string) $failed->description,
                $failed->invoice_notes, $lines, $this->wasVatExempt($failed),
            );
            $charge->update(['attempt_number' => $attempt]);

            ProcessManualChargeJob::dispatch($charge->id);

            return ['charge' => $charge, 'method' => 'token', 'pay_url' => null];
        }

        $page = $this->createHostedPage(
            $customer, (int) $failed->total_agorot, (string) $failed->description,
            $failed->invoice_notes, $lines, $this->wasVatExempt($failed),
        );

        $page['charge']->update(['attempt_number' => $attempt]);

        return ['charge' => $page['charge'], 'method' => 'link', 'pay_url' => $page['pay_url']];
    }

    /**
     * Whether the original charge was billed VAT-free.
     *
     * Read off the charge itself rather than off the customer: an exemption can
     * be set per charge, and the customer's flag may have changed since. The
     * retry must bill what was billed, not what would be billed today.
     */
    private function wasVatExempt(Charge $charge): bool
    {
        return (int) $charge->vat_agorot === 0;
    }

    /**
     * Create a pending demand with no payment page — used when the customer will
     * pay by bank transfer, so there is still a charge to track and issue a
     * proforma against, but no Cardcom link.
     *
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    public function createDemand(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], ?bool $vatExempt = null): Charge
    {
        return $this->createPendingCharge($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);
    }

    /**
     * Split a VAT-inclusive total into [net, vat] agorot. Exempt customers pay
     * no VAT, so the whole amount is net.
     *
     * @return array{0: int, 1: int}
     */
    public function splitVat(int $totalAgorot, bool $vatExempt): array
    {
        if ($vatExempt) {
            return [$totalAgorot, 0];
        }

        $vatRate = (float) config('billing.vat_rate');
        $net = (int) round($totalAgorot / (1 + $vatRate));

        return [$net, $totalAgorot - $net];
    }

    /**
     * @param  array<int, array{name: string, qty: int, unit_price_agorot: int}>  $lines
     */
    private function createPendingCharge(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], ?bool $vatExempt = null): Charge
    {
        // Per-charge exemption overrides the customer's default when set.
        [$net, $vat] = $this->splitVat($totalAgorot, $vatExempt ?? (bool) $customer->vat_exempt);

        return Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $description,
            'invoice_notes' => filled($notes) ? $notes : null,
            'lines' => $lines !== [] ? $lines : null,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }
}
