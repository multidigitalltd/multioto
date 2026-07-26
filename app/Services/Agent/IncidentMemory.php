<?php

namespace App\Services\Agent;

use App\Models\IncidentResolution;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The agent's long-term incident memory. Every executed fix is recorded
 * against the problem it treated; the verification round that confirms
 * "✅ הבעיה נפתרה" marks it verified. Before a new investigation, the agent
 * gets the relevant history back — the same site's recent incidents, plus
 * VERIFIED fixes for similar problems on OTHER sites — so a recurring
 * problem starts from what already worked instead of from zero.
 */
class IncidentMemory
{
    /** Same-site incidents shown to the agent (verified or not — context). */
    private const SAME_SITE_LIMIT = 5;

    /** Cross-site candidates scanned for similarity (verified only). */
    private const CROSS_SITE_POOL = 30;

    /** Cross-site matches actually shown. */
    private const CROSS_SITE_LIMIT = 3;

    /** Shared meaningful words required before a cross-site fix is suggested. */
    private const MIN_SIMILARITY = 2;

    /**
     * Generic Hebrew words that appear in almost every goal/problem ("האתר",
     * "בעיה"…) — matching on them would inject unrelated history.
     */
    private const STOP_WORDS = [
        'האתר', 'אתר', 'אתרים', 'בעיה', 'הבעיה', 'בעיית', 'תקלה', 'התקלה', 'תקלת',
        'מציג', 'מחזיר', 'נראה', 'מאוד', 'כרגע', 'עדיין', 'אחרי', 'לאחר', 'בזמן',
        'כאשר', 'אנא', 'בבקשה', 'בדוק', 'לבדוק', 'טיפול', 'לטפל', 'קיימת', 'ישנה',
        'אצל', 'הלקוח', 'לקוח', 'דיווח', 'דווח',
    ];

    /** Record an executed fix against the problem it treated. */
    public function record(Site $site, string $problem, ?string $fixTool, ?string $fixSummary = null, ?int $actionId = null): IncidentResolution
    {
        return IncidentResolution::create([
            'site_id' => $site->id,
            'domain' => (string) $site->domain,
            'problem' => Str::limit($problem, 1000),
            'fix_tool' => $fixTool,
            'fix_summary' => $fixSummary !== null ? Str::limit($fixSummary, 490) : null,
            'action_id' => $actionId,
        ]);
    }

    /** The follow-up verification confirmed the problem is gone. */
    public function confirm(int $resolutionId): void
    {
        IncidentResolution::whereKey($resolutionId)->update(['verified' => true]);
    }

    /**
     * A Hebrew context block of relevant past incidents for a new
     * investigation — empty string when there is nothing relevant.
     */
    public function contextFor(Site $site, string $goal): string
    {
        $lines = [];

        foreach (IncidentResolution::query()
            ->where('site_id', $site->id)
            ->latest()->limit(self::SAME_SITE_LIMIT)->get() as $r) {
            $lines[] = self::line($r, sameSite: true);
        }

        // Cross-site: only VERIFIED fixes, and only when the problem actually
        // resembles the current goal — unrelated history is noise, not memory.
        $candidates = IncidentResolution::query()
            ->where('site_id', '!=', $site->id)
            ->where('verified', true)
            ->latest()->limit(self::CROSS_SITE_POOL)->get();

        $matches = $candidates
            ->map(fn (IncidentResolution $r): array => ['r' => $r, 'score' => self::similarity($goal, $r->problem)])
            ->filter(fn (array $m): bool => $m['score'] >= self::MIN_SIMILARITY)
            ->sortByDesc('score')
            ->take(self::CROSS_SITE_LIMIT);

        foreach ($matches as $m) {
            $lines[] = self::line($m['r'], sameSite: false);
        }

        if ($lines === []) {
            return '';
        }

        return "תקלות שטופלו בעבר (זיכרון — אותו אתר ואתרים אחרים):\n".implode("\n", $lines)
            ."\nאם הבעיה הנוכחית דומה לאחת מאלה — בדוק קודם את הכיוון שכבר עבד.";
    }

    /** Shared meaningful words between the goal and a past problem. */
    private static function similarity(string $goal, string $problem): int
    {
        $tokenize = fn (string $text): array => array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [],
            fn (string $w): bool => mb_strlen($w) > 3 && ! in_array($w, self::STOP_WORDS, true),
        ));

        return count(array_intersect(array_unique($tokenize($goal)), array_unique($tokenize($problem))));
    }

    private static function line(IncidentResolution $r, bool $sameSite): string
    {
        $where = $sameSite ? 'האתר הזה' : "אתר אחר ({$r->domain})";
        $status = $r->verified ? 'אומת שנפתר' : 'בוצע, טרם אומת';
        $fix = $r->fix_tool !== null ? " → תוקן עם {$r->fix_tool}" : '';

        return "- [{$where}, {$r->created_at->format('d/m/Y')}] {$r->problem}{$fix} ({$status})";
    }
}
