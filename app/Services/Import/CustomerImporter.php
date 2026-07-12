<?php

namespace App\Services\Import;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-create customers (+ site + subscription) from parsed spreadsheet rows.
 *
 * Each row becomes a customer with an optional site and a Trialing subscription
 * with no card yet — exactly like the onboarding wizard, so the same
 * card-capture flow activates it later. Rows are validated and created one by
 * one in their own transaction, so a single bad row never aborts the whole
 * import; failures are collected and reported back.
 */
class CustomerImporter
{
    /**
     * Accepted header aliases (Hebrew + English) → canonical field name. Headers
     * are matched case-insensitively after trimming and stripping BOM/quotes.
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        'name' => 'name', 'שם' => 'name', 'שם הלקוח' => 'name', 'שם לקוח' => 'name', 'שם העסק' => 'name',
        'email' => 'email', 'אימייל' => 'email', 'מייל' => 'email', 'דוא"ל' => 'email', 'דואל' => 'email',
        'phone' => 'phone', 'טלפון' => 'phone', 'נייד' => 'phone', 'טלפון נייד' => 'phone',
        'business_number' => 'business_number', 'חפ' => 'business_number', 'ח.פ' => 'business_number',
        'עוסק' => 'business_number', 'ח.פ / עוסק' => 'business_number', 'מספר עוסק' => 'business_number',
        'business_type' => 'business_type', 'סוג עסק' => 'business_type',
        'vat_exempt' => 'vat_exempt', 'פטור ממעמ' => 'vat_exempt', 'פטור ממע"מ' => 'vat_exempt', 'פטור' => 'vat_exempt',
        'domain' => 'domain', 'דומיין' => 'domain', 'אתר' => 'domain', 'כתובת אתר' => 'domain',
        'plan' => 'plan', 'תוכנית' => 'plan', 'תכנית' => 'plan', 'מסלול' => 'plan',
        'price' => 'price', 'מחיר' => 'price', 'מחיר מיוחד' => 'price',
    ];

    /** Hebrew/English business-type values → enum. */
    private const BUSINESS_TYPE_ALIASES = [
        'exempt_dealer' => BusinessType::ExemptDealer, 'עוסק פטור' => BusinessType::ExemptDealer, 'פטור' => BusinessType::ExemptDealer,
        'licensed_dealer' => BusinessType::LicensedDealer, 'עוסק מורשה' => BusinessType::LicensedDealer, 'מורשה' => BusinessType::LicensedDealer,
        'company' => BusinessType::Company, 'חברה' => BusinessType::Company, 'בעמ' => BusinessType::Company, 'חברה בע"מ' => BusinessType::Company,
        // normalize() strips quotes but keeps dots, so match both "ער" and "ע.ר.".
        'nonprofit' => BusinessType::Nonprofit, 'עמותה' => BusinessType::Nonprofit, 'ער' => BusinessType::Nonprofit, 'ע.ר.' => BusinessType::Nonprofit, 'עמותה רשומה' => BusinessType::Nonprofit,
    ];

    /**
     * @param  iterable<int, array<string, string>>  $rows  Associative rows keyed by raw header.
     */
    public function import(iterable $rows, bool $skipDuplicates = true): ImportResult
    {
        $result = new ImportResult;
        $plansByName = Plan::all()->keyBy(fn (Plan $p) => $this->normalize($p->name));
        $firstActivePlan = Plan::where('active', true)->orderBy('id')->first();

        $lineNumber = 1; // header is line 1; data starts at 2
        foreach ($rows as $raw) {
            $lineNumber++;
            $row = $this->canonicalize($raw);

            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $result->skip($lineNumber, 'חסר שם לקוח');

                continue;
            }

            $email = trim($row['email'] ?? '') ?: null;
            $phone = trim($row['phone'] ?? '') ?: null;

            if ($skipDuplicates && $this->duplicateExists($email, $phone)) {
                $result->skip($lineNumber, "לקוח קיים כבר ({$name})");

                continue;
            }

            $plan = $this->resolvePlan($row['plan'] ?? '', $plansByName, $firstActivePlan);
            if (! $plan) {
                $result->skip($lineNumber, 'לא נמצאה תוכנית מתאימה — הוסיפו עמודת "תוכנית" או צרו תוכנית פעילה');

                continue;
            }

            try {
                $subscription = DB::transaction(fn () => $this->createRecords($name, $email, $phone, $row, $plan));
                $result->imported($subscription->id, $name);
            } catch (\Throwable $e) {
                $result->skip($lineNumber, 'שגיאה ביצירה: '.$e->getMessage());
            }
        }

        return $result;
    }

    private function createRecords(string $name, ?string $email, ?string $phone, array $row, Plan $plan): Subscription
    {
        $customer = Customer::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'business_number' => trim($row['business_number'] ?? '') ?: null,
            'business_type' => $this->resolveBusinessType($row['business_type'] ?? ''),
            'vat_exempt' => $this->parseBool($row['vat_exempt'] ?? ''),
            'status' => CustomerStatus::Active,
        ]);

        $siteId = null;
        $domain = trim($row['domain'] ?? '');
        if ($domain !== '') {
            $siteId = Site::create([
                'customer_id' => $customer->id,
                'domain' => $domain,
                'monitor_url' => 'https://'.ltrim(preg_replace('#^https?://#', '', $domain), '/'),
                'monitor_enabled' => true,
                'status' => SiteStatus::Active,
            ])->id;
        }

        return Subscription::create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'site_id' => $siteId,
            // No token yet — Trialing keeps it out of the charge run until the
            // customer enters a card (via the capture link), which activates it.
            'status' => SubscriptionStatus::Trialing,
            'price_agorot_override' => $this->parsePriceAgorot($row['price'] ?? ''),
            'next_charge_at' => now()->startOfDay(),
        ]);
    }

    private function duplicateExists(?string $email, ?string $phone): bool
    {
        if ($email === null && $phone === null) {
            return false;
        }

        return Customer::query()
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->exists();
    }

    private function resolvePlan(string $value, $plansByName, ?Plan $fallback): ?Plan
    {
        $value = $this->normalize($value);

        if ($value !== '' && $plansByName->has($value)) {
            return $plansByName->get($value);
        }

        // No plan column (or unmatched) → fall back to the only/first active plan.
        return $value === '' ? $fallback : null;
    }

    private function resolveBusinessType(string $value): BusinessType
    {
        return self::BUSINESS_TYPE_ALIASES[$this->normalize($value)] ?? BusinessType::LicensedDealer;
    }

    /** @return array<string, string> Row re-keyed to canonical field names. */
    private function canonicalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $header => $value) {
            $key = self::HEADER_ALIASES[$this->normalize((string) $header)] ?? null;
            if ($key !== null) {
                $out[$key] = is_string($value) ? $value : (string) $value;
            }
        }

        return $out;
    }

    private function parseBool(string $value): bool
    {
        return in_array($this->normalize($value), ['1', 'כן', 'true', 'yes', 'כ', 'פטור', 'v'], true);
    }

    /** Price entered in shekels → integer agorot; blank/zero → null (use plan price). */
    private function parsePriceAgorot(string $value): ?int
    {
        $value = trim(str_replace(['₪', ',', ' '], '', $value));

        if ($value === '' || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function normalize(string $value): string
    {
        // Strip BOM, quotes and surrounding whitespace; lower-case for matching.
        $value = str_replace(["\u{FEFF}", '"', "'"], '', $value);

        return mb_strtolower(trim($value));
    }
}
