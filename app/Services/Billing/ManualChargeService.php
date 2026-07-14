<?php

namespace App\Services\Billing;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
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
    public function createHostedPage(Customer $customer, int $totalAgorot, string $description, ?string $notes = null, array $lines = [], ?bool $vatExempt = null): array
    {
        $charge = $this->createPendingCharge($customer, $totalAgorot, $description, $notes, $lines, $vatExempt);

        try {
            $lowProfile = $this->cardcom->createChargeLowProfile(
                $charge->id,
                $totalAgorot,
                $description,
                $customer->name,
                $customer->email,
                $customer->phone,
                route('billing.update-card.done', ['result' => 'success']),
                route('billing.update-card.done', ['result' => 'failed']),
                CardcomWebhook::url(),
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
        ]);

        return [
            'charge' => $charge,
            'url' => $lowProfile['url'],           // the raw Cardcom page (team "open now")
            'pay_url' => PaymentLink::for($charge->id), // the cancelable link we hand the customer
        ];
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
