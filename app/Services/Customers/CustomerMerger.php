<?php

namespace App\Services\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Merge a duplicate customer card into the card that stays.
 *
 * The same business reaches us twice — a signup under a private address and an
 * invoice under the company one, a phone number that arrived before the email —
 * and ends up as two cards, each holding half the history. Merging is how the
 * two halves become one customer again.
 *
 * Everything that points at the duplicate is moved to the survivor: sites,
 * subscriptions, charges, invoices, cards, tickets, contacts, tasks and the
 * outbound log. NOTHING is recalculated and no amount is touched — a merge is a
 * change of address for existing history, not a financial event.
 *
 * The tables are discovered from the schema rather than listed here, so a table
 * added next year is covered the day it is created. A list would be correct only
 * until the next migration, and a merge that silently leaves rows behind is the
 * one failure mode that cannot be spotted by looking at the surviving card.
 */
class CustomerMerger
{
    /**
     * Identity fields taken from the duplicate ONLY where the survivor is blank.
     *
     * The survivor is authoritative: a merge never overwrites a value someone
     * typed. It fills holes — the phone we only ever had on the other card.
     */
    private const GAP_FILL = [
        'contact_name', 'business_number', 'email', 'phone', 'whatsapp_jid',
        'address', 'cardcom_account_id',
    ];

    /**
     * Move every trace of $duplicate onto $survivor and delete the empty card.
     *
     * @return array<string, int> Rows moved, per table (only tables that had any)
     *
     * @throws RuntimeException When the two cards can't be merged
     */
    public function merge(Customer $duplicate, Customer $survivor): array
    {
        if ($duplicate->id === $survivor->id) {
            throw new RuntimeException('אי אפשר למזג כרטיס לתוך עצמו.');
        }

        if ($duplicate->exists === false || $survivor->exists === false) {
            throw new RuntimeException('אחד הכרטיסים כבר לא קיים.');
        }

        return DB::transaction(function () use ($duplicate, $survivor): array {
            $tables = $this->tablesPointingAtCustomers();

            // Which contact the survivor itself calls primary — once the cards
            // are pooled the two are indistinguishable, and a merge must not
            // promote the incoming card's contact over the existing choice.
            $survivorPrimaryId = $survivor->contacts()->where('is_primary', true)->orderBy('id')->value('id');

            // The duplicate points at one of its own cards, which is about to
            // change owner: clear the pointer first so the FK never dangles
            // mid-move. The survivor's default is decided at the end.
            DB::table('customers')->where('id', $duplicate->id)->update(['default_token_id' => null]);

            $moved = [];

            foreach ($tables as $table) {
                // Deliberately a raw update: this is ONE merge, not four hundred
                // record edits, and firing an observer per row would bury the
                // audit trail under noise and re-index everything twice.
                $count = DB::table($table)
                    ->where('customer_id', $duplicate->id)
                    ->update(['customer_id' => $survivor->id]);

                if ($count > 0) {
                    $moved[$table] = $count;
                }
            }

            $this->settlePrimaryContact($survivor, $survivorPrimaryId);
            $this->mergeAttributes($duplicate, $survivor);

            // Nothing may point at the duplicate by the time it goes. Its foreign
            // keys cascade, so a single row missed here would be deleted rather
            // than merged — the check is what makes the delete safe.
            $this->assertNothingLeftBehind($duplicate, $tables);

            AuditLog::record(
                'updated',
                "מיזוג כרטיס לקוח: #{$duplicate->id} ({$duplicate->name}) → #{$survivor->id} ({$survivor->name})",
                $survivor,
                ['merged_from' => $duplicate->only(['id', 'name', 'email', 'phone']), 'moved' => $moved],
            );

            $duplicate->delete();
            $survivor->refresh();

            return $moved;
        });
    }

    /**
     * What a merge would move, without moving it — for the confirmation screen.
     *
     * Nobody should merge two customer cards on trust. The count of what is
     * about to move is the only way to notice, while it is still undoable, that
     * the direction is backwards.
     *
     * @return array<string, int>
     */
    public function preview(Customer $duplicate): array
    {
        $counts = [];

        foreach ($this->tablesPointingAtCustomers() as $table) {
            $count = DB::table($table)->where('customer_id', $duplicate->id)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /**
     * Every table with a customer_id column, read from the live schema.
     *
     * @return list<string>
     */
    private function tablesPointingAtCustomers(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'customer_id'))
            ->values()
            ->all();
    }

    /** Fill the survivor's blanks from the duplicate — never overwrite. */
    private function mergeAttributes(Customer $duplicate, Customer $survivor): void
    {
        $fill = [];

        foreach (self::GAP_FILL as $column) {
            if (blank($survivor->{$column}) && filled($duplicate->{$column})) {
                $fill[$column] = $duplicate->{$column};
            }
        }

        // An address that bounced keeps its verdict when it moves cards: the
        // mailbox is what was undeliverable, not the card it was filed under.
        if (isset($fill['email']) && $duplicate->email_bounced_at !== null) {
            $fill['email_bounced_at'] = $duplicate->email_bounced_at;
            $fill['email_bounce_reason'] = $duplicate->email_bounce_reason;
        }

        // A withdrawn consent survives the merge. Someone who asked us to stop
        // marketing to them said it about themselves, not about a row — and a
        // merge that quietly re-subscribed them would be the system deciding it
        // knows better.
        if ($survivor->marketing_opt_out_at === null && $duplicate->marketing_opt_out_at !== null) {
            $fill['marketing_opt_out_at'] = $duplicate->marketing_opt_out_at;
            $fill['marketing_opt_out_channel'] = $duplicate->marketing_opt_out_channel;
        }

        // The signed agreement moves as one piece: an acceptance date without
        // its signature, or the reverse, is not an agreement anyone could show.
        if ($survivor->terms_accepted_at === null && $duplicate->terms_accepted_at !== null) {
            $fill += [
                'terms_accepted_at' => $duplicate->terms_accepted_at,
                'signature_path' => $duplicate->signature_path,
                'signed_ip' => $duplicate->signed_ip,
                'signed_pdf_path' => $duplicate->signed_pdf_path,
            ];
        }

        // The cards moved with everything else; if the survivor never had a
        // default, the duplicate's becomes it.
        if ($survivor->default_token_id === null) {
            $token = $survivor->paymentTokens()->where('status', 'active')->latest('id')->first();

            if ($token !== null) {
                $fill['default_token_id'] = $token->id;
            }
        }

        if (filled($duplicate->notes)) {
            $fill['notes'] = trim(($survivor->notes ?? '')."\n\n— מכרטיס שמוזג (#{$duplicate->id} {$duplicate->name}):\n".$duplicate->notes);
        }

        if ($fill !== []) {
            $survivor->forceFill($fill)->save();
        }
    }

    /**
     * One primary contact per customer. The survivor's own primary keeps the
     * title; the incoming card's primaries become ordinary contacts. If the
     * survivor never named one, the incoming primary fills the empty slot
     * instead of being demoted for nothing.
     */
    private function settlePrimaryContact(Customer $survivor, ?int $survivorPrimaryId): void
    {
        $keep = $survivorPrimaryId
            ?? $survivor->contacts()->where('is_primary', true)->orderBy('id')->value('id');

        if ($keep === null) {
            return;
        }

        $survivor->contacts()->where('is_primary', true)->whereKeyNot($keep)->update(['is_primary' => false]);
    }

    /**
     * @param  list<string>  $tables
     *
     * @throws RuntimeException
     */
    private function assertNothingLeftBehind(Customer $duplicate, array $tables): void
    {
        foreach ($tables as $table) {
            if (DB::table($table)->where('customer_id', $duplicate->id)->exists()) {
                throw new RuntimeException("המיזוג בוטל: נשארו רשומות בטבלה {$table}.");
            }
        }
    }
}
