<?php

namespace App\Console\Commands;

use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Print what Kesher has actually been sending us.
 *
 * The integration's endpoint is live and recording before any of it is acted
 * on, because Kesher's published payload description is an outline and the API
 * is case sensitive — a field name guessed one letter wrong silently never
 * arrives. Rather than write collection logic against guesses and discover the
 * mistake in somebody's bank account, the endpoint listens first and this
 * prints the result.
 *
 * `--keys` is the form worth reading before writing any processing: it lists
 * every field seen across the deliveries, so the real notification becomes the
 * specification.
 */
class KesherPayloadsCommand extends Command
{
    protected $signature = 'kesher:payloads
        {--limit=10 : How many recent deliveries to show}
        {--keys : Show the field names seen across all deliveries instead of the bodies}';

    protected $description = 'Show the webhook payloads Kesher has sent (the endpoint records before it acts)';

    public function handle(): int
    {
        $query = WebhookEvent::query()->where('source', WebhookSource::Kesher);

        if ((clone $query)->doesntExist()) {
            $this->warn('קשר עדיין לא שלח שום דבר.');
            $this->line('בדקו שכתובת ה-webhook מוגדרת אצלם עם הסוד: '.route('webhooks.kesher').'?secret=…');

            return self::SUCCESS;
        }

        // --keys reads EVERY delivery, not a recent page of them.
        //
        // The whole point of the mode is to learn which fields Kesher really
        // sends, and a field that appeared only in an older callback is exactly
        // the one worth knowing about — a limit here would hide it and make the
        // counts read as complete when they are not.
        if ($this->option('keys')) {
            $this->keys($query);

            return self::SUCCESS;
        }

        $events = (clone $query)->latest('id')->limit(max(1, (int) $this->option('limit')))->get();

        foreach ($events as $event) {
            $this->newLine();
            $this->info("#{$event->id} · {$event->event_type} · {$event->created_at?->format('d/m/Y H:i')}");
            $this->line('external_id: '.$event->external_id);
            $this->line((string) json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    /**
     * Every field path seen, with how often — so a field that appears on only
     * some deliveries is visible as such rather than assumed to be always there.
     *
     * @param  Builder<WebhookEvent>  $query
     */
    private function keys(Builder $query): void
    {
        $counts = [];
        $total = 0;

        // Chunked: the point is to read everything, and everything is not a
        // size we get to assume.
        (clone $query)->orderBy('id')->chunk(200, function (Collection $chunk) use (&$counts, &$total): void {
            foreach ($chunk as $event) {
                $total++;

                foreach ($this->paths((array) $event->payload) as $path) {
                    $counts[$path] = ($counts[$path] ?? 0) + 1;
                }
            }
        });

        ksort($counts);

        $this->info("שדות שנראו בכל {$total} המסירות שהתקבלו:");

        foreach ($counts as $path => $seen) {
            $this->line(sprintf('  %-50s %d/%d', $path, $seen, $total));
        }
    }

    /**
     * Flatten to dotted paths, so a nested object reads as the caller will
     * address it.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function paths(array $payload, string $prefix = ''): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out = [...$out, ...$this->paths($value, $path)];

                continue;
            }

            $out[] = $path;
        }

        return $out;
    }
}
