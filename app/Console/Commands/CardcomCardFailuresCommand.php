<?php

namespace App\Console\Commands;

use App\Enums\WebhookSource;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Console\Command;

/**
 * Why card captures are failing, in Cardcom's own words.
 *
 * "העדכון לא הושלם" is all a customer ever sees, and it is all the same page
 * whatever went wrong — a declined card, a terminal that mandates a document, a
 * validation amount the acquirer will not authorise. The reason exists: Cardcom
 * sends it, and the authoritative copy sits in the Low Profile result. It was
 * simply never anywhere a person could read it.
 *
 * This prints it. `--fetch` asks Cardcom for the authoritative result of each
 * session rather than trusting the (minimal) webhook body, which is the form
 * worth reading when a customer says "it always fails".
 */
class CardcomCardFailuresCommand extends Command
{
    protected $signature = 'cardcom:card-failures
        {--limit=20 : How many recent card sessions to inspect}
        {--fetch : Ask Cardcom for the authoritative result of each session}';

    protected $description = 'Show why card-capture sessions failed (Cardcom response codes and descriptions)';

    public function handle(CardcomClient $cardcom): int
    {
        $events = WebhookEvent::query()
            ->where('source', WebhookSource::Cardcom)
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($events->isEmpty()) {
            $this->warn('לא התקבלו התראות מקארדקום.');
            $this->line('אם לקוח מנסה להזין כרטיס ולא מגיעה התראה — הבעיה היא בכתובת ה-webhook, ולא בכרטיס.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($events as $event) {
            $result = $this->resultFor($event, $cardcom);

            $code = (string) ($result['ResponseCode'] ?? '—');
            $hasToken = filled(data_get($result, 'TokenInfo.Token'));

            $rows[] = [
                $event->id,
                $event->created_at?->format('d/m H:i') ?? '—',
                $this->customerName($result, $event),
                $code,
                $hasToken ? '✅ כרטיס נשמר' : '❌ ללא כרטיס',
                // The whole point of the command: the reason, not the code.
                mb_substr(trim((string) ($result['Description'] ?? '')) ?: '—', 0, 60),
            ];
        }

        $this->table(['#', 'מתי', 'לקוח', 'קוד', 'תוצאה', 'סיבה מקארדקום'], $rows);

        if (! $this->option('fetch')) {
            $this->newLine();
            $this->line('הגוף של ההתראה מקארדקום מינימלי. לתשובה המלאה: php artisan cardcom:card-failures --fetch');
        }

        return self::SUCCESS;
    }

    /**
     * The authoritative Low Profile result, or the webhook body when we were
     * not asked to fetch.
     *
     * @return array<string, mixed>
     */
    private function resultFor(WebhookEvent $event, CardcomClient $cardcom): array
    {
        $payload = (array) $event->payload;

        if (! $this->option('fetch')) {
            return $payload;
        }

        $lowProfileId = (string) ($payload['LowProfileId'] ?? '');

        if ($lowProfileId === '') {
            return $payload;
        }

        try {
            // A failed lookup must not hide the rows that did resolve, so it
            // falls back to the body rather than ending the command.
            return $cardcom->getLpResult($lowProfileId) ?: $payload;
        } catch (\Throwable $e) {
            $this->warn("#{$event->id}: לא ניתן היה למשוך את התוצאה מקארדקום — {$e->getMessage()}");

            return $payload;
        }
    }

    /** @param  array<string, mixed>  $result */
    private function customerName(array $result, WebhookEvent $event): string
    {
        $id = (int) ($result['ReturnValue'] ?? data_get($event->payload, 'ReturnValue') ?? 0);

        if ($id <= 0) {
            return '—';
        }

        return Customer::query()->whereKey($id)->value('name') ?? "#{$id}";
    }
}
