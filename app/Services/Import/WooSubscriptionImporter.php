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
 * One-off migration importer for WooCommerce Subscriptions — either a WordPress
 * WXR (XML) export or a WooCommerce Subscriptions CSV export. Each subscription
 * becomes a customer (matched by email) plus a free-form monthly subscription.
 *
 * Rules, per the migration brief:
 *  - Cancelled subscriptions are skipped entirely.
 *  - The WooCommerce total is VAT-inclusive; our price_agorot is the pre-VAT
 *    base (the system adds VAT on charge), so the base is derived by removing
 *    VAT for non-exempt customers and kept as-is for exempt ones.
 *  - next_charge_at is set to the subscription's real next-payment date, so the
 *    customer is never charged earlier or later than their existing cycle. Cards
 *    can't be migrated (PCI), so subscriptions come in as Trialing (awaiting a
 *    card); adding a card later only charges once the due date arrives.
 *  - On-hold subscriptions are the debtors — imported and reported separately.
 */
class WooSubscriptionImporter
{
    /** Import from a WXR (XML) file on disk. */
    public function import(string $xmlPath, bool $force = false): WooSubscriptionImportResult
    {
        return $this->fromXml(@simplexml_load_file($xmlPath), $force);
    }

    /** Import from raw WXR (XML) content (pasted into the panel). */
    public function importString(string $content, bool $force = false): WooSubscriptionImportResult
    {
        return $this->fromXml(@simplexml_load_string($content), $force);
    }

    /** Import from a file on disk, auto-detecting WXR (XML) or CSV. */
    public function ingestFile(string $path, bool $force = false): WooSubscriptionImportResult
    {
        return $this->ingest((string) @file_get_contents($path), $force);
    }

    /** Import from raw content, auto-detecting WXR (XML) or CSV by its first character. */
    public function ingest(string $content, bool $force = false): WooSubscriptionImportResult
    {
        $trimmed = ltrim($content);

        if (isset($trimmed[0]) && $trimmed[0] === '<') {
            return $this->fromXml(@simplexml_load_string($content), $force);
        }

        return $this->fromCsv($content, $force);
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

            $meta = [];
            foreach ($wp->postmeta as $pm) {
                $meta[(string) $pm->meta_key] = (string) $pm->meta_value;
            }

            $vatExempt = mb_strtolower(trim($meta['is_vat_exempt'] ?? 'no')) === 'yes';
            $totalAgorot = (int) round(((float) ($meta['_order_total'] ?? 0)) * 100);
            // WooCommerce total is VAT-inclusive; store the pre-VAT base so our
            // charge (base + VAT) reproduces exactly what the customer pays today.
            $baseAgorot = $vatExempt ? $totalAgorot : (int) round($totalAgorot / (1 + $vatRate));

            $postId = (int) $wp->post_id;

            $this->persistOne($result, [
                'external_ref' => $postId > 0 ? 'woo-'.$postId : null,
                'email' => mb_strtolower(trim($meta['_billing_email'] ?? '')),
                'person' => trim(($meta['_billing_first_name'] ?? '').' '.($meta['_billing_last_name'] ?? '')),
                'company' => trim($meta['_billing_company'] ?? ''),
                'phone' => trim($meta['_billing_phone'] ?? '') ?: null,
                'vat_exempt' => $vatExempt,
                'base_agorot' => $baseAgorot,
                'next_charge' => $this->parseDate($meta['_schedule_next_payment'] ?? ''),
                'on_hold' => $status === 'on-hold',
            ], $force);
        }

        return $result;
    }

    private function fromCsv(string $content, bool $force): WooSubscriptionImportResult
    {
        $result = new WooSubscriptionImportResult;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            $result->skip('לא ניתן לקרוא את קובץ ה-CSV');

            return $result;
        }

        // Strip a UTF-8 BOM from the first header cell, then map name → column index.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $idx = array_flip(array_map(fn ($h): string => trim((string) $h), $header));
        $get = fn (array $row, string $key): string => isset($idx[$key], $row[$idx[$key]])
            ? trim((string) $row[$idx[$key]]) : '';

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }

            $status = mb_strtolower($get($row, 'status'));
            if ($status === 'cancelled') {
                continue;
            }

            $total = (float) $get($row, 'recurring_total');
            $tax = (float) $get($row, 'tax_total');
            // recurring_total is VAT-inclusive; the tax column is the VAT portion,
            // so the pre-VAT base is simply the difference (0 tax ⇒ exempt).
            $baseAgorot = (int) round(($total - $tax) * 100);
            $subId = $get($row, 'subscription_id');

            $this->persistOne($result, [
                'external_ref' => $subId !== '' ? 'woo-'.$subId : null,
                'email' => mb_strtolower($get($row, 'email')),
                'person' => trim($get($row, 'first_name').' '.$get($row, 'last_name')),
                'company' => $get($row, 'company'),
                'phone' => $get($row, 'phone') ?: null,
                'vat_exempt' => $tax <= 0.0049,
                'base_agorot' => $baseAgorot,
                'next_charge' => $this->parseDate($get($row, 'next_payment_date')),
                'on_hold' => $status === 'on-hold',
            ], $force);
        }

        fclose($handle);

        return $result;
    }

    /**
     * Create the customer (matched/created by email) and their subscription from
     * one normalised row. Idempotent per subscription via external_ref.
     *
     * @param  array{external_ref: ?string, email: string, person: string, company: string, phone: ?string, vat_exempt: bool, base_agorot: int, next_charge: ?Carbon, on_hold: bool}  $row
     */
    private function persistOne(WooSubscriptionImportResult $result, array $row, bool $force): void
    {
        $email = mb_strtolower(trim($row['email']));
        if ($email === '') {
            $result->skip('מנוי ללא אימייל — דולג');

            return;
        }

        $externalRef = $row['external_ref'];

        // Skip only this exact subscription if it was already imported — a
        // customer's other subscriptions are still brought in.
        if ($externalRef !== null && ! $force
            && Subscription::query()->where('external_ref', $externalRef)->exists()) {
            $result->skip("מנוי {$externalRef} כבר יובא — דולג");

            return;
        }

        $company = $row['company'];
        $person = $row['person'];
        $name = $company !== '' ? $company : ($person !== '' ? $person : $email);

        try {
            DB::transaction(function () use ($result, $row, $externalRef, $email, $name, $person, $company): void {
                $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

                if (! $customer) {
                    $customer = Customer::create([
                        'name' => $name,
                        'contact_name' => $person !== '' ? $person : null,
                        'email' => $email,
                        'phone' => $row['phone'],
                        'business_type' => $company !== '' ? BusinessType::Company : BusinessType::LicensedDealer,
                        'vat_exempt' => $row['vat_exempt'],
                        'status' => CustomerStatus::Active,
                    ]);
                    $result->customersCreated++;
                } else {
                    $result->customersMatched++;
                }

                $attributes = [
                    'plan_id' => null,
                    'name' => $row['on_hold'] ? 'מנוי חודשי (חוב פתוח מהמערכת הישנה)' : 'מנוי חודשי',
                    'billing_interval' => BillingInterval::Monthly,
                    'vat_applies' => ! $row['vat_exempt'],
                    'status' => SubscriptionStatus::Trialing,
                    'price_agorot_override' => $row['base_agorot'],
                    'next_charge_at' => $row['next_charge'] ?? now()->startOfDay(),
                ];

                // Keyed on external_ref so a --force re-run updates the same row
                // instead of colliding on the unique index.
                if ($externalRef !== null) {
                    Subscription::updateOrCreate(
                        ['external_ref' => $externalRef],
                        ['customer_id' => $customer->id] + $attributes,
                    );
                } else {
                    Subscription::create(['customer_id' => $customer->id] + $attributes);
                }
                $result->created++;

                if ($row['on_hold']) {
                    $result->debtors[] = $name.' — '.number_format($row['base_agorot'] / 100, 2).' ₪ (בסיס)';
                }
            });
        } catch (\Throwable $e) {
            $result->skip("שגיאה ביצירת {$email}: ".$e->getMessage());
        }
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
