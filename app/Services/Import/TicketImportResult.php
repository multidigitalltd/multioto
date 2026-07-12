<?php

namespace App\Services\Import;

/**
 * Outcome of a legacy-ticket import: how many rows were imported, how many were
 * skipped (with a Hebrew reason), and the highest ticket id now in the system —
 * the point from which new ticket numbers continue.
 */
class TicketImportResult
{
    public int $imported = 0;

    public int $matched = 0;

    /** @var array<int, array{line: int, reason: string}> */
    public array $skipped = [];

    public ?int $maxId = null;

    public function importedCount(): int
    {
        return $this->imported;
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function skip(int $line, string $reason): void
    {
        $this->skipped[] = ['line' => $line, 'reason' => $reason];
    }
}
