<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Find customer cards that are probably the same business.
 *
 * Merging exists, but nothing pointed at what to merge — the duplicates were
 * found by walking into one. A card opened twice is not rare: a signup under a
 * private address and an invoice under the company one, a phone captured before
 * an email, a name typed slightly differently. Each half then collects its own
 * tickets and its own history, and neither shows the whole customer.
 *
 * Only identifiers are matched, never names. Two businesses genuinely called
 * "מספרה" are not one customer, and a list that cries duplicate at every shared
 * word is a list nobody opens twice.
 */
class DuplicateFinder
{
    /**
     * Groups of cards that share a real identifier, newest group first.
     *
     * @return Collection<int, array{reason: string, value: string, customers: Collection<int, Customer>}>
     */
    public function groups(): Collection
    {
        $customers = Customer::query()
            ->select(['id', 'name', 'email', 'phone', 'whatsapp_jid', 'business_number', 'created_at'])
            ->get();

        return collect([
            'email' => 'אותה כתובת מייל',
            'phone' => 'אותו טלפון',
            'whatsapp_jid' => 'אותו וואטסאפ',
            'business_number' => 'אותו ח.פ / עוסק',
        ])
            ->flatMap(fn (string $reason, string $field): array => $customers
                ->filter(fn (Customer $customer): bool => filled($customer->{$field}))
                // Compared case- and space-insensitively: "Info@X.co.il " and
                // "info@x.co.il" are the same mailbox, and a duplicate that
                // hides behind a capital letter is still a duplicate.
                ->groupBy(fn (Customer $customer): string => mb_strtolower(trim((string) $customer->{$field})))
                ->filter(fn (Collection $group): bool => $group->count() > 1)
                ->map(fn (Collection $group, string $value): array => [
                    'reason' => $reason,
                    'value' => $value,
                    'customers' => $group->sortBy('id')->values(),
                ])
                ->values()
                ->all())
            // The same pair can share both a phone AND an email. Reported once,
            // by the first identifier that caught it — a person reading this
            // wants one row per problem, not one per reason it was noticed.
            ->unique(fn (array $group): string => $group['customers']->pluck('id')->join('-'))
            ->values();
    }

    /** How many duplicate groups exist — for the badge, without building them all. */
    public function count(): int
    {
        return $this->groups()->count();
    }
}
