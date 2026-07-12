<?php

namespace App\Services\Import;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-import support tickets exported from the previous system, PRESERVING the
 * original ticket ids so numbering is continuous, then advancing the id sequence
 * so new tickets carry on from the highest imported number.
 *
 * Each row becomes a ticket (subject, status, priority, dates) matched to an
 * existing customer by email, plus one opening ticket_message so the imported
 * conversation isn't empty — its body is the export's content/body column when
 * present, otherwise the subject line.
 *
 * All writes go through raw DB inserts (never Eloquent create/save), so no model
 * events fire and NO email or WhatsApp notification is ever sent to a customer
 * during an import.
 */
class TicketImporter
{
    /** Header aliases (Hebrew + English) → canonical field. Matched after normalize(). */
    private const HEADER_ALIASES = [
        'id' => 'id', 'מזהה' => 'id', 'ticket id' => 'id', 'מספר' => 'id', 'מספר כרטיס' => 'id', 'מספר פנייה' => 'id',
        'email' => 'email', 'mail' => 'email', 'e-mail' => 'email', 'אימייל' => 'email', 'דואל' => 'email', 'כתובת דואל' => 'email',
        'subject' => 'subject', 'title' => 'subject', 'נושא' => 'subject', 'כותרת' => 'subject',
        'status' => 'status', 'סטטוס' => 'status',
        'priority' => 'priority', 'עדיפות' => 'priority',
        'date closed' => 'date', 'date' => 'date', 'תאריך' => 'date', 'תאריך סגירה' => 'date', 'נסגר' => 'date',
        'body' => 'body', 'content' => 'body', 'message' => 'body', 'description' => 'body', 'details' => 'body',
        'תוכן' => 'body', 'גוף' => 'body', 'הודעה' => 'body', 'תיאור' => 'body', 'פירוט' => 'body', 'תוכן הפנייה' => 'body',
    ];

    /**
     * @param  iterable<int, array<string, string>>  $rows  Associative rows keyed by raw header.
     */
    public function import(iterable $rows, bool $skipDuplicates = true): TicketImportResult
    {
        $result = new TicketImportResult;

        // Email → existing customer id (lower-cased) for in-memory matching.
        $customers = Customer::query()
            ->whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [mb_strtolower(trim((string) $email)) => $id])
            ->all();

        // Ids already present (existing tickets + ids seen earlier in this file),
        // so we never insert a duplicate primary key.
        $seen = Ticket::query()->pluck('id')->flip();

        $batch = [];
        $messages = [];
        $line = 1;
        $now = now();

        foreach ($rows as $raw) {
            $line++;
            $row = $this->canonicalize($raw);

            $id = (int) preg_replace('/\D/', '', (string) ($row['id'] ?? ''));
            if ($id <= 0) {
                $result->skip($line, 'אין מזהה כרטיס תקין');

                continue;
            }

            if ($seen->has($id)) {
                if ($skipDuplicates) {
                    $result->skip($line, "כרטיס #{$id} כבר קיים — דולג");

                    continue;
                }
                $result->skip($line, "כרטיס #{$id} כפול — דולג");

                continue;
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            $customerId = $customers[$email] ?? null;
            if ($customerId !== null) {
                $result->matched++;
            }

            $status = $this->mapStatus((string) ($row['status'] ?? ''));
            $date = $this->parseDate((string) ($row['date'] ?? '')) ?? $now;
            $subject = trim((string) ($row['subject'] ?? '')) ?: "פנייה מיובאת #{$id}";

            $batch[] = [
                'id' => $id,
                'customer_id' => $customerId,
                'channel' => TicketChannel::Email->value,
                'subject' => Str::limit($subject, 250, ''),
                'status' => $status->value,
                'priority' => $this->mapPriority((string) ($row['priority'] ?? ''))->value,
                'external_thread_ref' => 'legacy-'.$id,
                'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? $date : null,
                'first_response_at' => null,
                'created_at' => $date,
                'updated_at' => $date,
            ];

            // One opening message so the ticket view has content. Use the export's
            // body column when it carries one, otherwise fall back to the subject.
            $body = trim((string) ($row['body'] ?? ''));
            $messages[] = [
                'ticket_id' => $id,
                'direction' => MessageDirection::Inbound->value,
                'channel' => MessageChannel::Email->value,
                'body' => $body !== '' ? $body : $subject,
                'external_message_id' => 'legacy-'.$id,
                'author' => MessageAuthor::Customer->value,
                'attachments' => null,
                'created_at' => $date,
            ];

            $seen->put($id, true);
            $result->imported++;
        }

        if ($batch !== []) {
            DB::transaction(function () use ($batch, $messages) {
                foreach (array_chunk($batch, 500) as $chunk) {
                    DB::table('tickets')->insert($chunk);
                }
                foreach (array_chunk($messages, 500) as $chunk) {
                    DB::table('ticket_messages')->insert($chunk);
                }
            });
        }

        $this->advanceSequence();
        $result->maxId = (int) Ticket::max('id');

        return $result;
    }

    /**
     * Remove every previously imported legacy ticket (and its messages, via the
     * foreign-key cascade) so a botched import can be redone from scratch. Returns
     * the number of tickets deleted. Deletion is a raw query — no model events, so
     * nothing is emailed to any customer.
     */
    public function deleteImported(): int
    {
        $deleted = DB::table('tickets')->where('external_thread_ref', 'like', 'legacy-%')->delete();

        $this->advanceSequence();

        return $deleted;
    }

    /** Map a legacy status label to our status enum. */
    private function mapStatus(string $value): TicketStatus
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'הושלם'), str_contains($v, 'טופל'), str_contains($v, 'סגור') => TicketStatus::Closed,
            str_contains($v, 'ממתין לתשובתך') => TicketStatus::Pending,
            str_contains($v, 'בעבודה') => TicketStatus::OnHold,
            default => TicketStatus::Open,
        };
    }

    /** Map a legacy priority label to our priority enum. */
    private function mapPriority(string $value): TicketPriority
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'sos'), str_contains($v, 'מושבת'), str_contains($v, 'דחוף') => TicketPriority::Urgent,
            str_contains($v, 'בינוני'), str_contains($v, 'מיידי'), str_contains($v, 'גבוה') => TicketPriority::High,
            default => TicketPriority::Normal,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * After inserting explicit ids, make sure the next auto-generated id continues
     * from the highest one. PostgreSQL's sequence isn't advanced by explicit
     * inserts, so bump it; SQLite/MySQL track the max inserted id automatically.
     */
    private function advanceSequence(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('tickets', 'id'), GREATEST((SELECT MAX(id) FROM tickets), 1))");
        }
    }

    /**
     * Re-key a raw row to canonical field names via the header aliases.
     *
     * @param  array<string, string>  $raw
     * @return array<string, string>
     */
    private function canonicalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $header => $value) {
            $field = self::HEADER_ALIASES[$this->normalize((string) $header)] ?? null;
            if ($field !== null && ! isset($out[$field])) {
                $out[$field] = (string) $value;
            }
        }

        return $out;
    }

    private function normalize(string $value): string
    {
        $value = str_replace(["\u{FEFF}", '"', "'"], '', $value);

        return mb_strtolower(trim($value));
    }
}
