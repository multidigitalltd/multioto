<?php

namespace App\Services\Audit;

use App\Models\SiteAudit;
use App\Support\Branding;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

/**
 * Turn a stored audit into the document that gets handed to the customer.
 *
 * Renders what was SAVED and never re-checks anything: a report is a statement
 * about a moment, and one that quietly changed between being sent and being
 * read would be worthless as a basis for a conversation about money.
 *
 * Problems are ordered by urgency rather than by the order the checks ran, and
 * everything that passed is listed at the end — a page of only faults reads as
 * an accusation, and "we looked at this and it is in order" is itself worth
 * saying to somebody deciding whether to trust you with their site.
 */
class AuditReport
{
    /** The headings, in the order a reader should meet them. */
    private const GROUPS = [
        'critical' => 'דורש טיפול מיידי',
        'warning' => 'חשוב לתקן',
        'notice' => 'מומלץ לשפר',
    ];

    public function pdf(SiteAudit $audit): string
    {
        $html = View::make('pdf.site-audit', $this->data($audit))->render();

        $tmp = storage_path('app/mpdf');

        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        // DejaVu, pinned — NOT mPDF's automatic script-to-font switching.
        //
        // Left on, that switching sent every Hebrew run to TaameyDavidCLM, a
        // Hebrew-only face with no bold weight and no coverage for the
        // punctuation around it. That is both of the faults this fixes at once:
        // characters that came out as empty boxes, and headings that were not
        // any heavier than the text under them, because the font had nothing
        // heavier to offer. DejaVu covers every character this report uses and
        // ships a real Bold.
        //
        // Substitutions stay on for one reason: the evidence lines quote other
        // people's websites, and a site may answer in any script on earth.
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'directionality' => 'rtl',
            'tempDir' => $tmp,
            'default_font' => 'dejavusans',
            'useSubstitutions' => true,
        ]);
        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', 'S');
    }

    /** A filename that says what it is and which site it is about. */
    public function filename(SiteAudit $audit): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/i', '', $audit->host) ?: 'site';

        return 'בדיקת-אתר-'.$host.'-'.$audit->created_at->format('Y-m-d').'.pdf';
    }

    /**
     * The "since last time" block, or null when there is none to state.
     *
     * @return array{at: string, fixed: list<array<string, mixed>>, appeared: list<array<string, mixed>>}|null
     */
    private function since(SiteAudit $audit): ?array
    {
        $comparison = Comparison::for($audit);

        if (! $comparison->available()) {
            return null;
        }

        $previous = $comparison->previous;

        // ממצא שהוסתר מהמסמך אינו מופיע גם ברשימת "חדשים": הקורא היה מחפש
        // אותו בהמשך ולא מוצא, וסתירה בתוך אותו עמוד היא הדבר שהכי מהר מאבד
        // אמון במסמך שלם.
        $shown = array_flip(array_map(
            fn (array $finding): string => (string) ($finding['title'] ?? ''),
            $audit->visibleFindings(),
        ));

        return [
            'at' => $previous->finished_at?->format('d/m/Y') ?? $previous->created_at->format('d/m/Y'),
            'fixed' => $comparison->fixed(),
            'appeared' => array_values(array_filter(
                $comparison->appeared(),
                fn (array $finding): bool => isset($shown[(string) ($finding['title'] ?? '')]),
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function data(SiteAudit $audit): array
    {
        $groups = [];

        // מה שנבחר להדפסה, לא כל מה שנמצא. הבדיקה עצמה נשארת שלמה — ההסתרה
        // היא החלטה על המסמך הזה בלבד, וניתנת להחזרה.
        foreach (self::GROUPS as $severity => $label) {
            $groups[$label] = $audit->visibleOf($severity);
        }

        $visibleProblems = array_merge(
            $audit->visibleOf('critical'),
            $audit->visibleOf('warning'),
            $audit->visibleOf('notice'),
        );

        return [
            'audit' => $audit,
            'groups' => $groups,
            // What moved since the last comparable audit of the same site. Null
            // when there is nothing honest to compare against — a first audit,
            // or one where a firewall stopped most of the checks from running
            // and every fault it never reached would read as one that was
            // fixed. Telling a customer their site improved when it did not is
            // the one mistake this document cannot afford.
            'comparison' => $this->since($audit),
            'problems' => $visibleProblems,
            // Grouped by area rather than listed flat: "we checked this and it
            // is in order" is worth as much as any fault to somebody deciding
            // whether to hand over their site, and a run of unlabelled ticks
            // reads as filler while the same items under their headings read as
            // a survey that was actually carried out.
            'passed' => $audit->visibleByArea('ok'),
            'passedCount' => count($audit->visibleOf('ok')),
            'areas' => $audit->areas(),
            // נספר ממה שמודפס, ולא מהסיכום השמור: כותרת שמכריזה על שלושה
            // ממצאים מעל רשימה של שניים היא מסמך שסופרים בו ומגלים שהמספר
            // אינו נכון, וזה מטיל ספק בכל השאר.
            'counts' => $audit->visibleCounts(),
            // מקטעי הטקסט החופשי שנוספו לדוח, בסופו.
            'sections' => $audit->sections(),
            'logo' => Branding::logoDataUri(),
            'company' => (string) config('billing.company.name', 'מולטי דיגיטל'),
            'generatedAt' => now()->format('d/m/Y'),
            'checkedAt' => $audit->finished_at?->format('d/m/Y H:i') ?? $audit->created_at->format('d/m/Y H:i'),
        ];
    }
}
