<?php

namespace App\Console\Commands;

use App\Services\Import\WooSubscriptionImporter;
use Illuminate\Console\Command;

/**
 * Import WooCommerce Subscriptions from a WordPress WXR (XML) export file on
 * disk. One-off migration: customers are matched/created by email and given a
 * free-form monthly subscription on their existing renewal date. Cancelled
 * subscriptions are skipped; on-hold ones are reported as open debt.
 *
 *   php artisan subscriptions:import-woo storage/app/subscriptions.xml
 */
class ImportWooSubscriptions extends Command
{
    protected $signature = 'subscriptions:import-woo
        {path : Path to the WooCommerce WXR (.xml) export}
        {--force : Add a subscription even to customers who already have one}';

    protected $description = 'Import WooCommerce subscriptions from a WXR XML export (no HTTP upload needed).';

    public function handle(WooSubscriptionImporter $importer): int
    {
        ini_set('memory_limit', '1024M');

        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("קובץ לא נמצא או לא קריא: {$path}");

            return self::FAILURE;
        }

        $result = $importer->import($path, (bool) $this->option('force'));

        $this->info("הושלם: נוצרו {$result->created} מנויים (לקוחות חדשים: {$result->customersCreated}, קיימים: {$result->customersMatched}).");

        if ($result->debtors !== []) {
            $this->warn('מנויים בחוב (בהמתנה) — לגבות ידנית:');
            foreach ($result->debtors as $d) {
                $this->line('  • '.$d);
            }
        }

        if ($result->skipped !== []) {
            $this->warn('דולגו '.count($result->skipped).':');
            foreach (array_slice($result->skipped, 0, 20) as $s) {
                $this->line('  • '.$s);
            }
        }

        return self::SUCCESS;
    }
}
