<?php

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\ScanSiteComplianceJob;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Compliance\AccessibilityScanner;
use App\Services\Compliance\LegalDocsScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The accessibility (ת"י 5568 / WCAG 2.2 AA) and legal-document audit: what a
 * customer site is missing, dated, and shown on the site page.
 */
class ComplianceScanTest extends TestCase
{
    use RefreshDatabase;

    /** A page that does everything right. */
    private function goodPage(): string
    {
        return '<html lang="he" dir="rtl"><body>'
            .'<a href="#main">דלג לתוכן</a><h1>ברוכים הבאים</h1>'
            .'<img src="a.jpg" alt="חנות"><img src="b.jpg" alt="">'
            .'<label for="email">אימייל</label><input id="email" type="email">'
            .'<a href="/privacy">מדיניות פרטיות</a><a href="/terms">תנאי שימוש</a>'
            .'<a href="/accessibility">הצהרת נגישות</a><a href="tel:03-5551234">התקשרו</a>'
            .'<script src="/wp-content/plugins/pojo-a11y/a11y.js"></script>'
            .'</body></html>';
    }

    public function test_a_well_built_page_scores_full_marks(): void
    {
        $result = (new AccessibilityScanner)->scan($this->goodPage());

        $this->assertSame(100, $result['score']);
        $this->assertSame([], $result['issues']);
        $this->assertTrue($result['has_widget']);
        $this->assertTrue($result['has_statement']);
    }

    public function test_missing_alt_text_labels_and_language_are_reported(): void
    {
        $html = '<html><body><img src="a.jpg"><img src="b.jpg"><input type="text" name="q">'
            .'<a href="/x">לחץ כאן</a></body></html>';

        $result = (new AccessibilityScanner)->scan($html);

        $keys = collect($result['issues'])->pluck('key')->all();

        $this->assertContains('lang', $keys);
        $this->assertContains('img_alt', $keys);
        $this->assertContains('form_labels', $keys);
        $this->assertContains('link_text', $keys);
        $this->assertContains('statement', $keys);
        $this->assertLessThan(50, $result['score']);
    }

    public function test_an_empty_alt_on_a_decorative_image_is_not_a_finding(): void
    {
        // alt="" is the CORRECT markup for a decorative image — flagging it
        // would send the team to "fix" something that is already right.
        $result = (new AccessibilityScanner)->scan('<html lang="he" dir="rtl"><body><img src="x.jpg" alt=""></body></html>');

        $this->assertNotContains('img_alt', collect($result['issues'])->pluck('key')->all());
    }

    public function test_missing_legal_documents_are_listed_and_a_store_also_needs_a_returns_policy(): void
    {
        $html = '<html><body><a href="/privacy">מדיניות פרטיות</a></body></html>';

        $brochure = (new LegalDocsScanner)->scan($html, isStore: false);
        $store = (new LegalDocsScanner)->scan($html, isStore: true);

        $this->assertSame(['privacy'], $brochure['found']);
        $this->assertContains('terms', collect($brochure['missing'])->pluck('key')->all());
        // Only the shop is asked for a cancellation/returns policy.
        $this->assertNotContains('refund', collect($brochure['missing'])->pluck('key')->all());
        $this->assertContains('refund', collect($store['missing'])->pluck('key')->all());
    }

    public function test_a_document_is_recognised_by_its_url_when_the_link_text_differs(): void
    {
        $result = (new LegalDocsScanner)->scan('<a href="/takanon-haatar">התקנון שלנו</a>', isStore: false);

        $this->assertContains('terms', $result['found']);
    }

    public function test_the_job_stores_the_report_and_dates_the_findings(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'site_type' => SiteType::Store]);

        Http::fake(['*' => Http::response('<html><body><img src="a.jpg"></body></html>')]);

        (new ScanSiteComplianceJob($site->id))->handle(new AccessibilityScanner, new LegalDocsScanner);

        $scan = $site->fresh()->compliance_scan;
        $this->assertNotNull($scan['scanned_at']);
        $this->assertLessThan(100, $scan['score']);
        $this->assertNotEmpty($scan['missing_docs']);

        // Both findings are dated in the customer-showable log.
        $this->assertDatabaseHas('site_events', ['site_id' => $site->id, 'type' => 'accessibility']);
        $this->assertDatabaseHas('site_events', ['site_id' => $site->id, 'type' => 'legal_docs']);
    }

    public function test_an_unchanged_report_does_not_add_a_finding_every_week(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il']);
        $page = '<html><body><img src="a.jpg"></body></html>';

        Http::fake(['*' => Http::sequence()->push($page)->push($page)]);

        (new ScanSiteComplianceJob($site->id))->handle(new AccessibilityScanner, new LegalDocsScanner);
        (new ScanSiteComplianceJob($site->id))->handle(new AccessibilityScanner, new LegalDocsScanner);

        // One row per finding type, not one per weekly run.
        $this->assertSame(1, SiteEvent::where('site_id', $site->id)->where('type', 'accessibility')->count());
        $this->assertSame(1, SiteEvent::where('site_id', $site->id)->where('type', 'legal_docs')->count());
    }

    public function test_an_unreachable_site_is_not_scored(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il']);

        Http::fake(['*' => Http::response('', 503)]);

        (new ScanSiteComplianceJob($site->id))->handle(new AccessibilityScanner, new LegalDocsScanner);

        $this->assertNull($site->fresh()->compliance_scan);
    }
}
