<?php

namespace App\Services\Import;

/**
 * Outcome of a WooCommerce-subscriptions import: how many customers were created
 * vs matched, how many subscriptions were created, which rows were skipped (with
 * a Hebrew reason), and the on-hold subscriptions carried over as open debt so
 * the team can chase them manually.
 */
class WooSubscriptionImportResult
{
    public int $customersCreated = 0;

    public int $customersMatched = 0;

    public int $created = 0;

    /** @var array<int, string> */
    public array $skipped = [];

    /** @var array<int, string> "name — amount ₪" for each on-hold (in-debt) subscription. */
    public array $debtors = [];

    public function skip(string $reason): void
    {
        $this->skipped[] = $reason;
    }
}
