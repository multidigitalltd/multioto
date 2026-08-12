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
        'hidden_findings', 'extra_sections',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'summary' => 'array',
            'hidden_findings' => 'array',
            'extra_sections' => 'array',
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

    /**
     * The most recent earlier audit of the same host that can be compared to.
     *
     * Blocked and unfinished audits are skipped rather than reached for: a run
     * where most checks never happened would make everything it missed look
     * like something that was fixed since.
     */
    public function previousComparable(): ?self
    {
        return static::query()
            ->where('host', $this->host)
            ->where('id', '<', $this->id)
            ->where('status', self::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->select(['id', 'host', 'url', 'status', 'findings', 'summary', 'created_at', 'finished_at'])
            // Streamed, not fetched: "blocked" lives inside the summary JSON and
            // cannot be filtered in SQL on both databases, and the findings
            // column is heavy. This stops at the first row that qualifies.
            ->cursor()
            ->first(fn (self $audit): bool => ! $audit->blocked());
    }

    /** Everything that needs doing, worst first. Whatever passed is not it. */
    public function problems(): array
    {
        return array_merge($this->of('critical'), $this->of('warning'), $this->of('notice'));
    }

    /*
    | ----------------------------------------------------------------
    | מה נכנס למסמך שנשלח ללקוח
    | ----------------------------------------------------------------
    |
    | הבדיקה עצמה אינה משתנה לעולם — היא צילום מצב, וזה מה שהופך אותה לשווה
    | משהו. מה שכן ניתן לבחירה הוא מה מתוכה מודפס: ממצא נכון אינו תמיד שייך
    | לשיחה הזו. הממצא המוסתר נשאר כאן, נראה בפאנל, וניתן להחזרה בלחיצה.
    */

    /** מיקומי הממצאים שסומנו כלא-להדפסה. */
    public function hiddenIndexes(): array
    {
        return array_values(array_map('intval', (array) $this->hidden_findings));
    }

    public function isHidden(int $index): bool
    {
        return in_array($index, $this->hiddenIndexes(), true);
    }

    /** כמה ממצאים לא ייכללו במסמך. */
    public function hiddenCount(): int
    {
        return count($this->hiddenIndexes());
    }

    /**
     * הממצאים שייכללו במסמך.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleFindings(): array
    {
        $hidden = array_flip($this->hiddenIndexes());

        return array_values(array_filter(
            (array) $this->findings,
            fn (int $index): bool => ! isset($hidden[$index]),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * הממצאים שייכללו במסמך, לפי חומרה.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleOf(string $severity): array
    {
        return array_values(array_filter(
            $this->visibleFindings(),
            fn (array $finding): bool => ($finding['severity'] ?? '') === $severity,
        ));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function visibleByArea(string $severity): array
    {
        $grouped = [];

        foreach ($this->visibleOf($severity) as $finding) {
            $grouped[(string) ($finding['area'] ?? 'כללי')][] = $finding;
        }

        return $grouped;
    }

    /**
     * הספירה לפי מה שמודפס בפועל.
     *
     * מסמך שכותרתו "3 דורשים טיפול מיידי" ומפרט שניים הוא מסמך שסופרים בו
     * ומגלים שהמספר אינו נכון — וזה מטיל ספק בכל השאר.
     *
     * @return array<string, int>
     */
    public function visibleCounts(): array
    {
        $counts = [];

        foreach (self::SEVERITIES as $severity) {
            $counts[$severity] = count($this->visibleOf($severity));
        }

        return $counts;
    }

    /**
     * מקטעי הטקסט החופשי שנוספו לדוח.
     *
     * @return list<array{title: string, body: string}>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ((array) $this->extra_sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $body = trim((string) ($section['body'] ?? ''));

            // מקטע בלי תוכן אינו מקטע; כותרת ריחפת במסמך נראית כמו תקלה.
            if ($body !== '') {
                $sections[] = ['title' => $title, 'body' => $body];
            }
        }

        return $sections;
    }
}
