<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outside-in inspection of a website and everything it found.
 *
 * The findings are a snapshot, never re-derived: the PDF handed to a customer
 * says what was true on the day it was produced, and a document that quietly
 * changed when it was reopened would be worse than no document.
 */
class SiteAudit extends Model
{
    use HasFactory;

    /** Ordered worst-first — the order the report is read in. */
    public const SEVERITIES = ['critical', 'warning', 'notice', 'ok'];

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'url', 'host', 'site_id', 'user_id', 'status', 'error', 'findings', 'summary', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'summary' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The findings of one severity, in the order they were produced.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $severity): array
    {
        return array_values(array_filter(
            (array) $this->findings,
            fn (array $finding): bool => ($finding['severity'] ?? '') === $severity,
        ));
    }

    /** How many findings of a severity — 0 when the audit never finished. */
    public function count(string $severity): int
    {
        return (int) (($this->summary['counts'][$severity] ?? 0));
    }

    /**
     * Whether the site turned the check away rather than answering it.
     *
     * Its own question because a blocked audit is not a failed one and not a
     * clean one: most of it could not run, and a reader who is not told that
     * will read the short list of findings as a short list of problems.
     */
    public function blocked(): bool
    {
        return (bool) ($this->summary['blocked'] ?? false);
    }

    /**
     * The findings of one severity, gathered under the area they belong to.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function byArea(string $severity): array
    {
        $grouped = [];

        foreach ($this->of($severity) as $finding) {
            $grouped[(string) ($finding['area'] ?? 'כללי')][] = $finding;
        }

        return $grouped;
    }

    /**
     * Every area the audit actually covered, in the order it ran them.
     *
     * Read from the findings rather than written down anywhere, so the sentence
     * that tells the reader what was examined cannot fall out of step with what
     * was examined. A list maintained by hand goes stale the first time a check
     * is added, and then the report is overclaiming in the one place a reader
     * would never think to doubt it.
     *
     * @return list<string>
     */
    public function areas(): array
    {
        $areas = [];

        foreach ((array) $this->findings as $finding) {
            $area = (string) ($finding['area'] ?? '');

            if ($area !== '') {
                $areas[$area] = true;
            }
        }

        return array_keys($areas);
    }

    /** Everything that needs doing, worst first. Whatever passed is not it. */
    public function problems(): array
    {
        return array_merge($this->of('critical'), $this->of('warning'), $this->of('notice'));
    }
}
