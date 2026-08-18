<?php

namespace App\Console\Commands;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\WebhookSource;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use App\Services\Support\CopiedWatchers;
use Illuminate\Console\Command;

/**
 * Recover the people copied on emails that arrived before we read Cc at all.
 *
 * Until the Cc header was read, a customer who wrote in and copied their
 * accountant left no trace of that person anywhere: the team could not see them
 * on the thread, and every reply left them out. Those tickets are still open,
 * and the person is still waiting.
 *
 * The emails themselves were never lost — `webhook_events` keeps each delivery
 * exactly as the provider sent it, Cc header and all, for the retention window
 * (60 days by default). So this is recoverable for every ticket inside that
 * window, and only for those: older deliveries have been pruned, and no amount
 * of work here will bring them back.
 *
 * Reports by default and writes only with --apply. A command that adds people
 * to customer correspondence the moment it is typed is a command nobody dares
 * run on a live system — and every address it adds will receive every future
 * reply, so it must be possible to read the list first.
 */
class RestoreCopiedWatchersCommand extends Command
{
    protected $signature = 'support:restore-copied-watchers
        {--days= : Only messages received in the last N days}
        {--apply : Write the changes (without this, only reports what would be added)}';

    protected $description = 'Register the Cc\'d people from stored inbound emails as watchers on their tickets';

    public function handle(CopiedWatchers $copied): int
    {
        $apply = (bool) $this->option('apply');
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $messages = TicketMessage::query()
            ->with('ticket')
            ->where('direction', MessageDirection::Inbound)
            ->where('channel', MessageChannel::Email)
            ->whereNotNull('external_message_id')
            ->when($days !== null, fn ($query) => $query->where('created_at', '>=', now()->subDays($days)))
            ->orderBy('id');

        $scanned = 0;
        $added = 0;
        $tickets = [];

        // Chunked: a mailbox with tens of thousands of messages must not be
        // loaded into memory to answer a question about a few hundred of them.
        $messages->chunkById(200, function ($chunk) use ($copied, $apply, &$scanned, &$added, &$tickets): void {
            $payloads = $this->payloadsFor($chunk->pluck('external_message_id')->all());

            foreach ($chunk as $message) {
                $scanned++;
                $payload = $payloads[(string) $message->external_message_id] ?? null;

                if ($payload === null || $message->ticket === null) {
                    continue;
                }

                $from = $copied->normalize((string) ($payload['From'] ?? $payload['from'] ?? ''));
                $skip = $copied->neverCopied($from);

                foreach ($copied->addresses($payload) as $email => $name) {
                    if (in_array($email, $skip, true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        continue;
                    }

                    // Already on the ticket — by an earlier message in this same
                    // run, or by a person who added them by hand. Either way it
                    // is not something this command would change.
                    if ($message->ticket->watchers()->where('email', $email)->exists()) {
                        continue;
                    }

                    $this->line("  פנייה #{$message->ticket->id}: {$email}".($name !== '' ? " ({$name})" : ''));
                    $added++;
                    $tickets[$message->ticket->id] = true;

                    if ($apply) {
                        $message->ticket->watchers()->create([
                            'email' => $email,
                            'name' => $name !== '' ? $name : null,
                            'added_by' => 'הפונה (מכותב במייל, שוחזר)',
                        ]);
                    }
                }
            }
        });

        $this->newLine();
        $this->info("נסרקו {$scanned} הודעות נכנסות.");

        if ($added === 0) {
            $this->info('לא נמצאו מכותבים חסרים.');

            return self::SUCCESS;
        }

        $this->info("נמצאו {$added} מכותבים חסרים ב-".count($tickets).' פניות.');

        if (! $apply) {
            // Said plainly, because the difference between this run and the real
            // one is that the real one starts sending those people email.
            $this->warn('הרצה יבשה — לא נכתב דבר. להחלה: --apply');
            $this->warn('שימו לב: אחרי ההחלה, כל אחד מהם יקבל עותק של כל תשובה עתידית בפנייה שלו.');
        } else {
            $this->info('נוספו. מכאן, כל אחד מהם מקבל עותק של כל תשובה בפנייה שלו.');
        }

        return self::SUCCESS;
    }

    /**
     * The original delivery for each message, keyed by provider message id.
     *
     * A message whose delivery has already been pruned simply has no entry —
     * there is nothing to recover for it, and saying so is more useful than
     * pretending the ticket had no copied addresses.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function payloadsFor(array $ids): array
    {
        return WebhookEvent::query()
            ->where('source', WebhookSource::Email)
            ->whereIn('external_id', $ids)
            ->get(['external_id', 'payload'])
            ->mapWithKeys(fn (WebhookEvent $event): array => [
                (string) $event->external_id => (array) $event->payload,
            ])
            ->all();
    }
}
