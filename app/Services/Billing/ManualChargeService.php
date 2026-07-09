<?php

namespace App\Services\Billing;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Jobs\ProcessManualChargeJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
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
    public function chargeSavedToken(Customer $customer, int $totalAgorot, string $description): Charge
    {
        $charge = $this->createPendingCharge($customer, $totalAgorot, $description);
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
    public function createHostedPage(Customer $customer, int $totalAgorot, string $description): array
    {
        $charge = $this->createPendingCharge($customer, $totalAgorot, $description);

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
                route('webhooks.cardcom', ['secret' => config('billing.cardcom.webhook_secret')]),
            );
        } catch (\Throwable $e) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'יצירת עמוד תשלום נכשלה']);

            throw new \RuntimeException('יצירת עמוד התשלום נכשלה: '.Str::limit($e->getMessage(), 150), 0, $e);
        }

        if (blank($lowProfile['url'])) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'קארדקום לא החזירה כתובת תשלום']);

            throw new \RuntimeException('קארדקום לא החזירה עמוד תשלום');
        }

        $charge->update(['cardcom_low_profile_id' => $lowProfile['low_profile_id']]);

        return ['charge' => $charge, 'url' => $lowProfile['url']];
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

    private function createPendingCharge(Customer $customer, int $totalAgorot, string $description): Charge
    {
        [$net, $vat] = $this->splitVat($totalAgorot, (bool) $customer->vat_exempt);

        return Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $description,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }
}
