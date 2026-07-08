<?php

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Models\Plan;
use Illuminate\Console\Command;

/**
 * Create a few starter subscription plans so the panel is usable out of the box
 * (the onboarding wizard needs at least one plan to pick). Idempotent and
 * production-safe: no factories/faker, and existing plans of the same name are
 * left untouched so it never overwrites prices the team has edited.
 */
class SeedStarterPlans extends Command
{
    protected $signature = 'app:seed-plans';

    protected $description = 'Create starter subscription plans (idempotent, safe to re-run)';

    /**
     * Sensible defaults for a WordPress maintenance business. Prices are in
     * agorot (₪99 / ₪199 / ₪399 per month). Edit them from the panel afterwards.
     */
    private const STARTER_PLANS = [
        ['name' => 'אחזקה בסיסית', 'price_agorot' => 9900, 'description' => 'עדכונים, גיבויים וניטור בסיסי.'],
        ['name' => 'אחזקה עסקית', 'price_agorot' => 19900, 'description' => 'הכול בבסיסית + תמיכה מורחבת ושינויים חודשיים.'],
        ['name' => 'אחזקה פרימיום', 'price_agorot' => 39900, 'description' => 'ליווי מלא, עדיפות בתמיכה ופיתוח שוטף.'],
    ];

    public function handle(): int
    {
        $created = 0;

        foreach (self::STARTER_PLANS as $plan) {
            $model = Plan::firstOrCreate(
                ['name' => $plan['name']],
                [
                    'price_agorot' => $plan['price_agorot'],
                    'vat_applies' => true,
                    'billing_interval' => BillingInterval::Monthly,
                    'description' => $plan['description'],
                    'active' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
                $this->line("  ✓ נוצרה תוכנית: {$plan['name']} (₪".number_format($plan['price_agorot'] / 100).')');
            }
        }

        $this->info($created > 0
            ? "{$created} תוכניות התחלה נוצרו. אפשר לערוך אותן מהמסך 'תוכניות'."
            : 'כל תוכניות ההתחלה כבר קיימות — לא בוצע שינוי.');

        return self::SUCCESS;
    }
}
