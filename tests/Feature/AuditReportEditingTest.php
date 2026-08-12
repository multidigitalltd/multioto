<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteAudits;
use App\Models\SiteAudit;
use App\Models\User;
use App\Services\Audit\AuditReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * עריכת הדוח שנשלח ללקוח.
 *
 * הבדיקה עצמה היא צילום מצב ואינה משתנה לעולם — זה מה שהופך אותה לשווה משהו.
 * מה שכן נבחר הוא מה מתוכה מודפס: ממצא נכון אינו תמיד שייך לשיחה הזו, ולפעמים
 * צריך להוסיף למסמך דברים שהבדיקה האוטומטית אינה יודעת לומר.
 *
 * שני הגבולות שנבדקים כאן: ההסתרה נוגעת למסמך בלבד ולא לנתונים, והמסמך אינו
 * סותר את עצמו — הספירה בראשו סופרת את מה שבאמת מודפס.
 */
class AuditReportEditingTest extends TestCase
{
    use RefreshDatabase;

    private function audit(): SiteAudit
    {
        return SiteAudit::create([
            'url' => 'https://example.com',
            'host' => 'example.com',
            'status' => SiteAudit::STATUS_COMPLETED,
            'finished_at' => now(),
            'findings' => [
                ['severity' => 'critical', 'area' => 'אבטחה', 'title' => 'אין תעודת אבטחה', 'detail' => 'ד', 'fix' => 'פ'],
                ['severity' => 'warning', 'area' => 'מהירות', 'title' => 'העמוד נטען לאט', 'detail' => 'ד', 'fix' => 'פ'],
                ['severity' => 'notice', 'area' => 'קידום', 'title' => 'חסר תיאור לעמוד', 'detail' => 'ד', 'fix' => 'פ'],
                ['severity' => 'ok', 'area' => 'זמינות', 'title' => 'האתר עלה כרגיל', 'detail' => 'ד'],
            ],
            'summary' => ['counts' => ['critical' => 1, 'warning' => 1, 'notice' => 1, 'ok' => 1], 'blocked' => false],
        ]);
    }

    /*
    | ----------------------------------------------------------------
    | מה נכנס למסמך
    | ----------------------------------------------------------------
    */

    /** כברירת מחדל — הכל. */
    public function test_everything_is_included_by_default(): void
    {
        $audit = $this->audit();

        $this->assertCount(4, $audit->visibleFindings());
        $this->assertSame(0, $audit->hiddenCount());
        $this->assertSame(1, $audit->visibleCounts()['critical']);
    }

    /** ממצא שהוסר יורד מהמסמך — ונשאר בבדיקה. */
    public function test_a_hidden_finding_leaves_the_document_but_not_the_audit(): void
    {
        $audit = $this->audit();

        $audit->update(['hidden_findings' => [1]]);
        $audit->refresh();

        $this->assertCount(3, $audit->visibleFindings());
        $this->assertSame(0, $audit->visibleCounts()['warning']);
        $this->assertSame(1, $audit->hiddenCount());

        // הנתון עצמו לא נגע — הבדיקה עדיין יודעת מה נמצא.
        $this->assertCount(4, (array) $audit->findings);
        $this->assertSame(1, $audit->count('warning'));
    }

    /**
     * הספירה בראש המסמך סופרת את מה שמודפס.
     *
     * כותרת שמכריזה על שני ממצאים מעל רשימה של אחד היא מסמך שסופרים בו ומגלים
     * שהמספר אינו נכון, וזה מטיל ספק בכל השאר.
     */
    public function test_the_tally_counts_what_is_printed(): void
    {
        $audit = $this->audit();
        $audit->update(['hidden_findings' => [0]]);

        $html = $this->renderedReport($audit->refresh());

        $this->assertStringContainsString('דורש טיפול מיידי: 0', $html);
        $this->assertStringNotContainsString('אין תעודת אבטחה', $html);
        $this->assertStringContainsString('העמוד נטען לאט', $html);
    }

    /** גם בדיקה שעברה ניתנת להסרה מהמסמך. */
    public function test_a_passed_check_can_be_left_out_too(): void
    {
        $audit = $this->audit();
        $audit->update(['hidden_findings' => [3]]);

        $html = $this->renderedReport($audit->refresh());

        $this->assertStringNotContainsString('האתר עלה כרגיל', $html);
    }

    /*
    | ----------------------------------------------------------------
    | מקטעים חופשיים
    | ----------------------------------------------------------------
    */

    /** מקטע שנוסף מופיע במסמך. */
    public function test_a_free_text_section_reaches_the_document(): void
    {
        $audit = $this->audit();
        $audit->update(['extra_sections' => [
            ['title' => 'הצעת מחיר', 'body' => "תיקון התעודה — 400 ₪.\nשיפור מהירות — 900 ₪."],
        ]]);

        $html = $this->renderedReport($audit->refresh());

        $this->assertStringContainsString('הצעת מחיר', $html);
        $this->assertStringContainsString('תיקון התעודה — 400 ₪', $html);
    }

    /** מקטע בלי תוכן אינו נשמר — כותרת ריחפת נראית כמו תקלה. */
    public function test_an_empty_section_is_dropped(): void
    {
        $audit = $this->audit();
        $audit->update(['extra_sections' => [
            ['title' => 'כותרת בלבד', 'body' => '   '],
            ['title' => '', 'body' => 'תוכן בלי כותרת'],
        ]]);

        $sections = $audit->refresh()->sections();

        $this->assertCount(1, $sections);
        $this->assertSame('תוכן בלי כותרת', $sections[0]['body']);
    }

    /** טקסט חופשי אינו הופך ל-HTML במסמך. */
    public function test_free_text_is_not_markup(): void
    {
        $audit = $this->audit();
        $audit->update(['extra_sections' => [
            ['title' => 'הערה', 'body' => '<script>alert(1)</script>'],
        ]]);

        $html = $this->renderedReport($audit->refresh());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /*
    | ----------------------------------------------------------------
    | המסך
    | ----------------------------------------------------------------
    */

    /** שמירה מהמסך: מה שלא סומן — לא ייכלל. */
    public function test_the_screen_saves_what_was_chosen(): void
    {
        $this->actingAs(User::factory()->create());
        $audit = $this->audit();

        Livewire::test(SiteAudits::class)
            ->callTableAction('editReport', $audit, [
                'included' => [0, 2, 3],
                'sections' => [['title' => 'סיכום', 'body' => 'דיברנו בטלפון.']],
            ]);

        $audit->refresh();
        $this->assertSame([1], $audit->hiddenIndexes());
        $this->assertCount(1, $audit->sections());
    }

    /** והחזרה: סימון מחדש מחזיר את הממצא למסמך. */
    public function test_a_hidden_finding_can_be_put_back(): void
    {
        $this->actingAs(User::factory()->create());
        $audit = $this->audit();
        $audit->update(['hidden_findings' => [0, 1]]);

        Livewire::test(SiteAudits::class)
            ->callTableAction('editReport', $audit->refresh(), [
                'included' => [0, 1, 2, 3],
                'sections' => [],
            ]);

        $this->assertSame([], $audit->refresh()->hiddenIndexes());
    }

    /** ה-HTML של הדוח, בלי לייצר PDF (mPDF אינו נדרש לבדיקת התוכן). */
    private function renderedReport(SiteAudit $audit): string
    {
        $data = (fn (SiteAudit $a): array => $this->data($a))
            ->call(app(AuditReport::class), $audit);

        return view('pdf.site-audit', $data)->render();
    }
}
