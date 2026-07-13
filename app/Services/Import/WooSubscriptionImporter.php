<?php

namespace App\Services\Import;

use App\Enums\BillingInterval;
use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration importer for WooCommerce Subscriptions exported as a
 * WordPress WXR (XML) file. Each `shop_subscription` becomes a customer
 * (matched by email) plus a free-form monthly subscription.
 *
 * Rules, per the migration brief:
 *  - Cancelled subscriptions are skipped entirely.
 *  - The WooCommerce total is VAT-inclusive; our price_agorot is the pre-VAT
 *    base (the system adds VAT on charge), so the base is derived by dividing
 *    out VAT for non-exempt customers and kept as-is for exempt ones.
 *  - next_charge_at is set to the subscription's real next-payment date, so the
 *    customer is never charged earlier or later than their existing cycle. Cards
 *    can't be migrated (PCI), so subscriptions come in as Trialing (awaiting a
 *    card); adding a card later only charges once the due date arrives.
 *  - On-hold subscriptions are the debtors — imported and reported separately.
 */
class WooSubscriptionImporter
{
    /** Import from a WXR file on disk. */
    public function import(string $xmlPath, bool $force = false): WooSubscriptionImportResult
    {
        return $this->fromXml(@simplexml_load_file($xmlPath), $force);
    }

    /** Import from raw WXR content (pasted into the panel — bypasses file upload). */
    public function importString(string $content, bool $force = false): WooSubscriptionImportResult
    {
        return $this->fromXml(@simplexml_load_string($content), $force);
    }

    private function fromXml(\SimpleXMLElement|false $xml, bool $force): WooSubscriptionImportResult
    {
        $result = new WooSubscriptionImportResult;

        if ($xml === false || ! isset($xml->channel)) {
            $result->skip('לא ניתן לקרוא את תוכן ה-XML');

            return $result;
        }

        $vatRate = (float) config('billing.vat_rate');

        foreach ($xml->channel->item as $item) {
            $wp = $item->children('http://wordpress.org/export/1.2/');
            if ((string) $wp->post_type !== 'shop_subscription') {
                continue;
            }

            $status = str_replace('wc-', '', (string) $wp->status);
            if ($status === 'cancelled') {
                continue; // dropped by request — cancelled subscriptions are not imported
            }

            // Stable per-subscription key so a repeat upload is idempotent, yet a
            // customer with several subscriptions gets every one of them.
            $postId = (int) $wp->post_id;
            $externalRef = $postId > 0 ? 'woo-'.$postId : null;

            $meta = [];
            foreach ($wp->postmeta as $pm) {
                $meta[(string) $pm->meta_key] = (string) $pm->meta_value;
            }

            $email = mb_strtolower(trim($meta['_billing_email'] ?? ''));
            if ($email === '') {
                $result->skip('מנוי ללא אימייל — דולג');

                continue;
            }

            $person = trim(($meta['_billing_first_name'] ?? '').' '.($meta['_billing_last_name'] ?? ''));
            $company = trim($meta['_billing_company'] ?? '');
            $name = $company !== '' ? $company : ($person !== '' ? $person : $email);
            $vatExempt = mb_strtolower(trim($meta['is_vat_exempt'] ?? 'no')) === 'yes';

            $totalAgorot = (int) round(((float) ($meta['_order_total'] ?? 0)) * 100);
            // WooCommerce total is VAT-inclusive; store the pre-VAT base so our
            // charge (base + VAT) reproduces exactly what the customer pays today.
            $baseAgorot = $vatExempt ? $totalAgorot : (int) round($totalAgorot / (1 + $vatRate));

            $nextCharge = $this->parseDate($meta['_schedule_next_payment'] ?? '');
            $onHold = $status === 'on-hold';

            $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

            // Skip only this exact subscription if it was already imported — a
            // customer's other subscriptions are still brought in.
            if ($externalRef !== null && ! $force
                && Subscription::query()->where('external_ref', $externalRef)->exists()) {
                $result->skip("מנוי {$externalRef} כבר יובא — דולג");

                continue;
            }

            try {
                DB::transaction(function () use (&$customer, $result, $externalRef, $name, $person, $company, $email, $meta, $vatExempt, $baseAgorot, $nextCharge, $onHold) {
                    if (! $customer) {
                        $customer = Customer::create([
                            'name' => $name,
                            'contact_name' => $person !== '' ? $person : null,
                            'email' => $email,
                            'phone' => trim($meta['_billing_phone'] ?? '') ?: null,
                            'business_type' => $company !== '' ? BusinessType::Company : BusinessType::LicensedDealer,
                            'vat_exempt' => $vatExempt,
                            'status' => CustomerStatus::Active,
                        ]);
                        $result->customersCreated++;
                    } else {
                        $result->customersMatched++;
                    }

                    $attributes = [
                        'plan_id' => null,
                        'name' => $onHold ? 'מנוי חודשי (חוב פתוח מהמערכת הישנה)' : 'מנוי חודשי',
                        'billing_interval' => BillingInterval::Monthly,
                        'vat_applies' => ! $vatExempt,
                        'status' => SubscriptionStatus::Trialing,
                        'price_agorot_override' => $baseAgorot,
                        'next_charge_at' => $nextCharge ?? now()->startOfDay(),
                    ];

                    // Keyed on external_ref so a --force re-run updates the same
                    // row instead of colliding on the unique index.
                    if ($externalRef !== null) {
                        Subscription::updateOrCreate(
                            ['external_ref' => $externalRef],
                            ['customer_id' => $customer->id] + $attributes,
                        );
                    } else {
                        Subscription::create(['customer_id' => $customer->id] + $attributes);
                    }
                    $result->created++;

                    if ($onHold) {
                        $result->debtors[] = $name.' — '.number_format($baseAgorot / 100, 2).' ₪ (בסיס)';
                    }
                });
            } catch (\Throwable $e) {
                $result->skip("שגיאה ביצירת {$email}: ".$e->getMessage());
            }
        }

        return $result;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
