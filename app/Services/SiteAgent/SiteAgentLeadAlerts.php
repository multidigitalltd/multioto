<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;

/**
 * "תודיע לי על כל ליד חדש": a WhatsApp message for every new form submission.
 *
 * A lead is somebody waiting for a call back, and the owner who hears about it
 * within minutes is the one who gets the job. So the site's forms are read
 * every few minutes and each submission not seen before is announced once.
 *
 * "Not seen before" is a list of the lead keys (form plugin + its own id)
 * already accounted for, kept on the number. Switching alerts on records
 * what is already there, so the first check does not announce yesterday's
 * leads as new.
 */
class SiteAgentLeadAlerts
{
    /** How many lead keys are remembered per number. A day's leads, with room. */
    private const REMEMBERED = 300;

    /** The most leads one read returns — the plugin's own ceiling. */
    public const READ_LIMIT = 50;

    /** Fields shown per lead in the message. */
    private const FIELDS = 6;

    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    public function available(Site $site): bool
    {
        return $this->toolbox->siteHas($site, 'wp_lead_list');
    }

    /**
     * Switch alerts on, with what is on the site now counted as already seen.
     *
     * @return string|null why it could not be switched on
     */
    public function enable(SiteAgentSubscriber $subscriber, Site $site): ?string
    {
        if (! $this->available($site)) {
            return 'התוסף באתר אינו יודע לקרוא לידים — צריך לעדכן אותו.';
        }

        // Taken BEFORE the read: a lead that arrives while it runs is not in
        // the snapshot, so the cursor must not start after it.
        $from = now()->getTimestamp();
        $recent = $this->recent($site);

        if ($recent === null) {
            return 'לא הצלחתי לקרוא את הטפסים באתר כרגע. נסו שוב בעוד כמה דקות.';
        }

        if ($recent['sources'] === []) {
            return 'לא נמצא באתר תוסף טפסים ששומר פניות (Elementor Pro, Contact Form 7 עם Flamingo, WPForms, Gravity Forms או Fluent Forms).';
        }

        $subscriber->forceFill([
            'lead_alerts' => true,
            'lead_alert_seen' => array_map($this->key(...), $recent['leads']),
            // From the start of that read on: what it saw is not news.
            'lead_alert_cursor' => $from,
        ])->save();

        return null;
    }

    public function disable(SiteAgentSubscriber $subscriber): void
    {
        $subscriber->forceFill(['lead_alerts' => false, 'lead_alert_seen' => null, 'lead_alert_cursor' => null])->save();
    }

    /** Pages followed in one check. A burst beyond this waits for the next run. */
    private const MAX_PAGES = 10;

    /**
     * The site's recent leads, newest first — or null when the read failed.
     *
     * With a cursor and a plugin that takes one (1.8.4+), everything since the
     * cursor is read oldest first, page after page, so a burst bigger than
     * one read is followed to the end: `complete` says it was. An older
     * plugin ignores the cursor and answers with the last day's newest
     * fifty, exactly as before — `complete` is then false and the caller
     * says "at least" where it has to.
     *
     * @return array{sources: list<string>, leads: list<array<string, mixed>>, complete: bool}|null
     */
    public function recent(Site $site, ?int $after = null): ?array
    {
        $leads = [];
        $sources = [];
        $complete = false;

        $afterKey = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $decoded = $this->read($site, array_filter(['days' => 1, 'limit' => self::READ_LIMIT, 'after' => $after, 'after_key' => $afterKey]));

            if ($decoded === null) {
                return $page === 0 ? null : ['sources' => $sources, 'leads' => $this->newestFirst($leads), 'complete' => false];
            }

            $sources = array_values((array) ($decoded['sources'] ?? []));
            $batch = array_values(array_filter((array) ($decoded['leads'] ?? []), 'is_array'));

            if (! array_key_exists('has_more', $decoded)) {
                // No cursor support: the plain newest-first read.
                return ['sources' => $sources, 'leads' => $batch, 'complete' => false];
            }

            $leads = [...$leads, ...$batch];
            $lastLead = $batch[count($batch) - 1] ?? null;
            $last = (int) ($lastLead['ts'] ?? 0);
            $lastKey = $lastLead !== null ? $this->key($lastLead) : null;

            // Done — or a page that did not move past where it started, which
            // asking again cannot fix.
            if (! $decoded['has_more'] || $batch === [] || ($last === (int) $after && $lastKey === $afterKey)) {
                $complete = ! $decoded['has_more'];
                break;
            }

            // The next page starts after this exact lead — its second AND its
            // key, so fifty leads in one second do not return the same page.
            $after = $last;
            $afterKey = $lastKey;
        }

        return ['sources' => $sources, 'leads' => $this->newestFirst($leads), 'complete' => $complete];
    }

    /**
     * Oldest-first pages overlap by a second at each seam: each lead once,
     * handed back newest first like the plain read.
     *
     * @param  list<array<string, mixed>>  $leads
     * @return list<array<string, mixed>>
     */
    private function newestFirst(array $leads): array
    {
        $unique = [];

        foreach ($leads as $lead) {
            $unique[$this->key($lead)] = $lead;
        }

        return array_reverse(array_values($unique));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function read(Site $site, array $arguments): ?array
    {
        try {
            $decoded = json_decode($this->mcp->textContent($this->mcp->callTool($site, 'wp_lead_list', $arguments)), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The leads this number has not been told about, oldest first.
     *
     * @param  list<array<string, mixed>>  $leads  newest first, as the site lists them
     * @return list<array<string, mixed>>
     */
    public function fresh(SiteAgentSubscriber $subscriber, array $leads): array
    {
        $seen = array_flip((array) ($subscriber->lead_alert_seen ?? []));
        $cursor = (int) $subscriber->lead_alert_cursor;

        // Not seen, and not before this number's own cursor: the site is read
        // from the furthest-behind number, and the remembered keys are capped,
        // so the cursor is what keeps a stuck neighbour from replaying old
        // leads to everyone else.
        return array_reverse(array_values(array_filter(
            $leads,
            fn (array $lead): bool => ! isset($seen[$this->key($lead)])
                && ! (isset($lead['ts']) && $cursor > 0 && (int) $lead['ts'] < $cursor),
        )));
    }

    /**
     * Could leads have arrived that this read did not reach?
     *
     * A read returns the newest READ_LIMIT and the plugins in the field have
     * no cursor. When the read is full and even its oldest lead is new to
     * this number, there may be older new ones beyond it — which the owner is
     * told, rather than their being dropped without a word.
     *
     * @param  list<array<string, mixed>>  $leads  newest first, as read
     */
    public function mayHaveMissed(SiteAgentSubscriber $subscriber, array $leads, bool $complete = false): bool
    {
        if ($complete || count($leads) < self::READ_LIMIT) {
            return false;
        }

        $seen = array_flip((array) ($subscriber->lead_alert_seen ?? []));

        return ! isset($seen[$this->key($leads[count($leads) - 1])]);
    }

    /** @param list<array<string, mixed>> $leads */
    public function markSeen(SiteAgentSubscriber $subscriber, array $leads): void
    {
        $seen = [...(array) ($subscriber->lead_alert_seen ?? []), ...array_map($this->key(...), $leads)];

        // The cursor moves only as far as what was actually accounted for:
        // a lead held back by the owner's ceiling stays ahead of it.
        $newest = max([0, ...array_map(fn (array $lead): int => (int) ($lead['ts'] ?? 0), $leads)]);

        $subscriber->forceFill([
            'lead_alert_seen' => array_slice(array_values(array_unique($seen)), -self::REMEMBERED),
            'lead_alert_cursor' => $newest > 0 ? max((int) $subscriber->lead_alert_cursor, $newest) : $subscriber->lead_alert_cursor,
        ])->save();
    }

    /** The message for one lead, as free text. */
    public function text(array $lead): string
    {
        $lines = ['📩 *ליד חדש* — '.$this->form($lead).(filled($lead['date'] ?? null) ? " ({$lead['date']})" : '')];

        foreach (array_slice((array) ($lead['fields'] ?? []), 0, self::FIELDS, true) as $label => $value) {
            $lines[] = '• '.Str::limit(trim((string) $label), 40).': '.Str::limit(trim((string) $value), 200);
        }

        return implode("\n", $lines);
    }

    /** One line about a lead, for a template parameter. */
    public function summary(array $lead): string
    {
        $values = array_slice(array_values((array) ($lead['fields'] ?? [])), 0, 2);

        return Str::limit($this->form($lead).': '.implode(', ', array_map(fn ($v): string => trim((string) $v), $values)), 120);
    }

    /** @param array<string, mixed> $lead */
    private function key(array $lead): string
    {
        return ($lead['source'] ?? '').':'.($lead['id'] ?? '');
    }

    /** @param array<string, mixed> $lead */
    private function form(array $lead): string
    {
        return Str::limit(trim((string) ($lead['form'] ?? '')) ?: 'טופס', 60);
    }
}
