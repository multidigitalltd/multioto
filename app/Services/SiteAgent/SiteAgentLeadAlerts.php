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
        ])->save();

        return null;
    }

    public function disable(SiteAgentSubscriber $subscriber): void
    {
        $subscriber->forceFill(['lead_alerts' => false, 'lead_alert_seen' => null])->save();
    }

    /**
     * The last day's leads on the site, newest first — or null when the read failed.
     *
     * @return array{sources: list<string>, leads: list<array<string, mixed>>}|null
     */
    public function recent(Site $site): ?array
    {
        try {
            $decoded = json_decode($this->mcp->textContent(
                $this->mcp->callTool($site, 'wp_lead_list', ['days' => 1, 'limit' => 50]),
            ), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'sources' => array_values((array) ($decoded['sources'] ?? [])),
            'leads' => array_values(array_filter((array) ($decoded['leads'] ?? []), 'is_array')),
        ];
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

        return array_reverse(array_values(array_filter(
            $leads,
            fn (array $lead): bool => ! isset($seen[$this->key($lead)]),
        )));
    }

    /** @param list<array<string, mixed>> $leads */
    public function markSeen(SiteAgentSubscriber $subscriber, array $leads): void
    {
        $seen = [...(array) ($subscriber->lead_alert_seen ?? []), ...array_map($this->key(...), $leads)];

        $subscriber->forceFill([
            'lead_alert_seen' => array_slice(array_values(array_unique($seen)), -self::REMEMBERED),
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
