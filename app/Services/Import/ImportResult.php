<?php

namespace App\Services\Import;

/**
 * Outcome of a customer import: how many rows became customers, the created
 * subscription ids (for follow-up card-capture dispatch), and per-row skips
 * with a human-readable Hebrew reason.
 */
class ImportResult
{
    /** @var array<int, string> subscription ids created */
    public array $subscriptionIds = [];

    /** @var array<int, array{name: string}> */
    public array $importedRows = [];

    /** @var array<int, array{line: int, reason: string}> */
    public array $skipped = [];

    public function imported(int $subscriptionId, string $name): void
    {
        $this->subscriptionIds[] = $subscriptionId;
        $this->importedRows[] = ['name' => $name];
    }

    public function skip(int $line, string $reason): void
    {
        $this->skipped[] = ['line' => $line, 'reason' => $reason];
    }

    public function importedCount(): int
    {
        return count($this->importedRows);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
