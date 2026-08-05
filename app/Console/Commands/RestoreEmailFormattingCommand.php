<?php

namespace App\Console\Commands;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\WebhookSource;
use App\Models\TicketMessage;
use App\Models\WebhookEvent;
use App\Support\EmailBody;
use Illuminate\Console\Command;

/**
 * Re-render stored inbound emails from the original webhook payload.
 *
 * Ticket messages keep the SANITIZED body, so any formatting the sanitizer of
 * the day dropped is gone from the message row — highlighted text, for one, was
 * discarded with the style attribute that carried it until this was fixed. The
 * email itself is not lost: it is still in `webhook_events`, exactly as the
 * provider delivered it, which is what makes this recoverable at all.
 *
 * Reports by default and writes only with --apply. A command that rewrites
 * customer correspondence the moment it is typed is a command nobody dares run
 * on a live system, and a recovery tool that cannot be rehearsed is not a
 * recovery tool.
 */
class RestoreEmailFormattingCommand extends Command
{
    protected $signature = 'support:restore-email-formatting
        {--days= : Only messages received in the last N days}
        {--apply : Write the changes (without this, only reports what would change)}';

    protected $description = 'Re-render stored inbound emails from their original webhook payload (recovers lost formatting)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $messages = TicketMessage::query()
            ->where('direction', MessageDirection::Inbound)
            ->where('channel', MessageChannel::Email)
            ->whereNotNull('external_message_id')
            ->when($days !== null, fn ($query) => $query->where('created_at', '>=', now()->subDays($days)))
            ->orderBy('id');

        $examined = 0;
        $changed = 0;

        // Chunked by id: this walks correspondence going back as far as the
        // install does, and loading it all to count a few changes would trade a
        // recovery for an outage.
        $messages->chunkById(200, function ($chunk) use ($apply, &$examined, &$changed): void {
            $payloads = $this->payloadsFor($chunk->pluck('external_message_id')->all());

            foreach ($chunk as $message) {
                $examined++;

                $html = $payloads[$message->external_message_id] ?? null;

                if ($html === null) {
                    continue; // Delivery no longer on file — nothing to restore from.
                }

                $rendered = EmailBody::toSafeHtml($html);

                if ($rendered === null || $rendered === $message->body_html) {
                    continue;
                }

                $changed++;

                if ($apply) {
                    $message->update(['body_html' => $rendered]);
                }
            }
        });

        $this->line("נבדקו {$examined} הודעות נכנסות.");

        if ($changed === 0) {
            $this->info('אין מה לשחזר — כל ההודעות כבר מוצגות כפי שהמייל המקורי נראה.');

            return self::SUCCESS;
        }

        if ($apply) {
            $this->info("שוחזרו {$changed} הודעות.");

            return self::SUCCESS;
        }

        $this->warn("{$changed} הודעות ישתנו. להרצה בפועל: הוסיפו ‎--apply");

        return self::SUCCESS;
    }

    /**
     * The original HTML part of each delivery, keyed by provider message id.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function payloadsFor(array $ids): array
    {
        return WebhookEvent::query()
            ->where('source', WebhookSource::Email)
            ->whereIn('external_id', $ids)
            ->get(['external_id', 'payload'])
            ->mapWithKeys(function (WebhookEvent $event): array {
                $payload = (array) $event->payload;
                $html = trim((string) ($payload['HtmlBody'] ?? $payload['html'] ?? ''));

                return $html === '' ? [] : [(string) $event->external_id => $html];
            })
            ->all();
    }
}
