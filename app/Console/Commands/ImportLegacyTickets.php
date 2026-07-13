<?php

namespace App\Console\Commands;

use App\Filament\Concerns\ParsesCsvUpload;
use App\Services\Import\TicketImporter;
use Illuminate\Console\Command;

/**
 * Import legacy support tickets straight from a CSV file on disk — for exports
 * too large to push through the browser upload (tens of MB). Same importer as
 * the panel screen (ids preserved, customer matched by email, opening message
 * created, HTML flattened), so it never emails a customer.
 *
 * Usage on the server:
 *   php artisan tickets:import storage/app/tickets.csv --delete-existing
 */
class ImportLegacyTickets extends Command
{
    use ParsesCsvUpload;

    protected $signature = 'tickets:import
        {path : Path to the CSV file to import}
        {--delete-existing : Remove previously imported legacy tickets first}';

    protected $description = 'Import legacy support tickets from a CSV file on disk (no HTTP upload needed).';

    public function handle(TicketImporter $importer): int
    {
        // Large exports parse into sizeable arrays; give the CLI room to work.
        ini_set('memory_limit', '2048M');

        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("קובץ לא נמצא או לא קריא: {$path}");

            return self::FAILURE;
        }

        // Validate the file fully BEFORE any destructive step, so --delete-existing
        // can never wipe the previous import when the CSV is unreadable or empty.
        $rows = $this->parseCsv($path);
        if ($rows === null) {
            $this->error('לא ניתן לקרוא את ה-CSV (חסרות כותרות?).');

            return self::FAILURE;
        }
        if ($rows === []) {
            $this->error('הקובץ אינו מכיל שורות לייבוא — לא בוצע שינוי.');

            return self::FAILURE;
        }

        if ($this->option('delete-existing')) {
            $deleted = $importer->deleteImported();
            $this->warn("נמחקו {$deleted} כרטיסים שיובאו קודם.");
        }

        $this->info('מייבא '.count($rows).' שורות...');

        $result = $importer->import($rows);

        $this->info("הושלם: יובאו {$result->importedCount()} כרטיסים ({$result->matched} שויכו ללקוח).");
        if ($result->maxId) {
            $this->line('המספר הבא לכרטיס חדש: '.($result->maxId + 1).'.');
        }
        if ($result->skippedCount() > 0) {
            $this->warn("דולגו {$result->skippedCount()} (למשל כרטיסים שכבר קיימים).");
        }

        return self::SUCCESS;
    }
}
