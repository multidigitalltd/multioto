<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Everyone the sender copied on an email, turned into watchers on the ticket.
 *
 * A class of its own rather than methods on the ingest job, because two callers
 * need exactly this behaviour: the live intake, and the command that recovers
 * the copied people from emails that arrived before we read the Cc header at
 * all. Two copies of "who counts as a participant" would drift, and the day
 * they drift is the day a recovery run quietly adds our own support inbox as a
 * watcher on four hundred old tickets.
 */
class CopiedWatchers
{
    /**
     * Copied addresses one message may add to a ticket.
     *
     * A message sent to a distribution list would otherwise turn every future
     * reply into a broadcast nobody chose to send.
     */
    public const MAX_PER_MESSAGE = 10;

    /**
     * Register the copied addresses on the ticket. Returns how many were new.
     *
     * @param  array<string, mixed>  $payload  the inbound email, as delivered
     */
    public function register(Ticket $ticket, array $payload, string $from): int
    {
        $skip = $this->neverCopied($from);
        $added = 0;

        foreach ($this->addresses($payload) as $email => $name) {
            if (in_array($email, $skip, true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            if ($added >= self::MAX_PER_MESSAGE) {
                Log::info('CopiedWatchers: addresses beyond the cap were not added', [
                    'ticket' => $ticket->id,
                    'cap' => self::MAX_PER_MESSAGE,
                ]);

                break;
            }

            // firstOrCreate: a thread where the same people are copied on every
            // reply must not accumulate duplicates, and an address the team
            // added by hand keeps the attribution it already had.
            $watcher = $ticket->watchers()->firstOrCreate(
                ['email' => $email],
                ['name' => $name !== '' ? $name : null, 'added_by' => 'הפונה (מכותב במייל)'],
            );

            // Only somebody NEW counts against the cap. Every later message in
            // a thread copies the people already on it, and counting them again
            // would spend the whole allowance on names we already have — so the
            // one person this message actually added, listed last, would be the
            // one dropped.
            if ($watcher->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * The addresses copied on this message, as email => display name.
     *
     * Both shapes the provider sends: `CcFull` (structured, with names) and the
     * plain `Cc` header. Bcc is deliberately absent — we only ever see it when
     * it was addressed to us, and treating a blind copy as a visible
     * participant would expose it to everyone else on the thread.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function addresses(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['CcFull'] ?? []) as $entry) {
            $email = $this->normalize((string) ($entry['Email'] ?? ''));

            if ($email !== '') {
                $out[$email] = trim((string) ($entry['Name'] ?? ''));
            }
        }

        foreach (explode(',', (string) ($payload['Cc'] ?? $payload['cc'] ?? '')) as $part) {
            $email = $this->normalize($part);

            if ($email !== '' && ! isset($out[$email])) {
                $out[$email] = '';
            }
        }

        return $out;
    }

    /**
     * Addresses that must never become watchers.
     *
     * Our own mailbox and the support address customers write TO, because
     * copying ourselves means every reply is ingested as a new inbound message
     * on the same ticket and the thread talks to itself. The team's own
     * addresses, because they already receive the internal alert and would get
     * every reply twice. And the sender, who is the recipient of the reply, not
     * a copy of it.
     *
     * @return list<string>
     */
    public function neverCopied(string $from): array
    {
        $ours = [
            Str::lower(trim($from)),
            Str::lower(trim((string) config('mail.from.address'))),
            Str::lower(trim((string) config('billing.email.support_address'))),
        ];

        $team = User::query()->pluck('email')
            ->map(fn (?string $email): string => Str::lower(trim((string) $email)))
            ->all();

        return array_values(array_filter(array_unique([...$ours, ...$team])));
    }

    /** A bare address from a "Name <addr@host>" header, lowercased. */
    public function normalize(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = $m[1];
        }

        return Str::lower(trim($raw));
    }
}
