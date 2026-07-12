<?php

namespace App\Filament\Concerns;

/**
 * Shared CSV handling for the import screens (customers, tickets): resolve the
 * not-yet-stored uploaded file's path and parse it into associative rows keyed
 * by header. One copy so both importers behave identically.
 */
trait ParsesCsvUpload
{
    /** Resolve the real filesystem path of the (not-yet-stored) uploaded CSV. */
    protected function uploadedFilePath(mixed $fileState): ?string
    {
        $file = is_array($fileState) ? reset($fileState) : $fileState;

        if ($file && method_exists($file, 'getRealPath')) {
            $path = $file->getRealPath();

            return is_string($path) && is_readable($path) ? $path : null;
        }

        return null;
    }

    /**
     * Parse a CSV into associative rows keyed by their header. Returns null if
     * the file can't be opened or has no header row.
     *
     * @return array<int, array<string, string>>|null
     */
    protected function parseCsv(?string $path): ?array
    {
        if ($path === null || ! ($handle = fopen($path, 'r'))) {
            return null;
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === null) {
            fclose($handle);

            return null;
        }

        // Strip a UTF-8 BOM from the first header cell (Excel adds it).
        $headers[0] = ltrim((string) $headers[0], "\u{FEFF}");
        $count = count($headers);

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $cells = array_pad(array_slice($cells, 0, $count), $count, '');
            $rows[] = array_combine($headers, $cells);
        }

        fclose($handle);

        return $rows;
    }
}
