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

        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl', 'tempDir' => $tmp]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', 'S');
    }

    /** A filename that says what it is and which site it is about. */
    public function filename(SiteAudit $audit): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/i', '', $audit->host) ?: 'site';

        return 'בדיקת-אתר-'.$host.'-'.$audit->created_at->format('Y-m-d').'.pdf';
    }

    /** @return array<string, mixed> */
    private function data(SiteAudit $audit): array
    {
        $groups = [];

        foreach (self::GROUPS as $severity => $label) {
            $groups[$label] = $audit->of($severity);
        }

        return [
            'audit' => $audit,
            'groups' => $groups,
            'problems' => $audit->problems(),
            'passed' => $audit->of('ok'),
            'counts' => (array) ($audit->summary['counts'] ?? []),
            'logo' => Branding::logoDataUri(),
            'company' => (string) config('billing.company.name', 'מולטי דיגיטל'),
            'generatedAt' => now()->format('d/m/Y'),
            'checkedAt' => $audit->finished_at?->format('d/m/Y H:i') ?? $audit->created_at->format('d/m/Y H:i'),
        ];
    }
}
