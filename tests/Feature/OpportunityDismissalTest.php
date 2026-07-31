<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\OpportunityRadarPage;
use App\Models\Customer;
use App\Models\OpportunityNote;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use App\Services\Growth\OpportunityRadar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deciding what to do with an opportunity.
 *
 * The radar rebuilds its findings every week, so the one thing that must not
 * live inside them is the team's judgement about them: a suggestion waved away
 * on Monday has to stay away on Sunday, and a quote already sent must not read
 * as untouched work.
 */
class OpportunityDismissalTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $items = []): Site
    {
        $items = $items ?: [
            ['key' => 'accessibility', 'title' => 'התאמת נגישות', 'evidence' => '9 ממצאים', 'price_agorot' => 180000, 'severity' => 'high'],
            ['key' => 'monitoring', 'title' => 'הוספת ניטור', 'evidence' => 'הניטור כבוי', 'price_agorot' => 30000, 'severity' => 'medium'],
        ];

        return Site::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'עסק לדוגמה'])->id,
            'domain' => 'example.co.il',
            'opportunities' => [
                'scanned_at' => now()->toIso8601String(),
                'items' => $items,
                'total_agorot' => collect($items)->sum('price_agorot'),
            ],
        ]);
    }

    private function page(): Testable
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        return Livewire::test(OpportunityRadarPage::class);
    }

    public function test_a_dismissed_opportunity_leaves_the_open_list_and_its_value(): void
    {
        $site = $this->site();

        $page = $this->page();
        $this->assertCount(2, $page->instance()->rows->first()['items']);
        $this->assertSame(210000, $page->instance()->totalAgorot);

        $page->callAction('dismiss', data: ['reason' => 'הלקוח עשה מול ספק אחר'],
            arguments: ['site' => $site->id, 'key' => 'accessibility']);

        $rows = $page->instance()->rows;

        // Gone from the list — and gone from the pipeline value, which is the
        // number the team plans against.
        $this->assertSame(['monitoring'], collect($rows->first()['items'])->pluck('key')->all());
        $this->assertSame(30000, $page->instance()->totalAgorot);
    }

    public function test_the_rendered_buttons_carry_the_site_and_the_opportunity(): void
    {
        $site = $this->site();

        // Every callback reads `site` and `key` off the arguments. Calling an
        // action directly in a test proves the callback; only the rendered
        // markup proves the button actually hands it those values.
        $html = $this->page()->assertOk()->html();

        foreach (['dismiss', 'markOffered', 'openTask'] as $action) {
            $this->assertStringContainsString(
                "mountAction('{$action}', JSON.parse('"
                    ."{\\u0022site\\u0022:{$site->id},\\u0022key\\u0022:\\u0022accessibility\\u0022}'))",
                $html,
                "הכפתור {$action} אינו מעביר את האתר וההזדמנות",
            );
        }
    }

    public function test_who_decided_is_shown_even_without_a_reason(): void
    {
        $site = $this->site();
        $page = $this->page();

        // "Offered" never carries a reason, and a dismissal may skip it — but
        // "who moved this out of the open list" is the whole point of recording
        // the user, so it must survive an empty reason.
        $page->callAction('markOffered', arguments: ['site' => $site->id, 'key' => 'monitoring']);

        $html = $page->set('filter', 'offered')->html();

        $this->assertStringContainsString('סומן על ידי', $html);
        // Escaped, because the page escapes it: a generated name with an
        // apostrophe ("Freda D'Amore") is rendered as &#039; and the raw string
        // is nowhere in the markup — a failure about the test, not the page.
        $this->assertStringContainsString(e(auth()->user()->name), $html);
    }

    public function test_a_rescan_does_not_bring_a_dismissed_opportunity_back(): void
    {
        $site = $this->site();

        OpportunityNote::decide($site->id, 'accessibility', OpportunityNote::DISMISSED);

        // A fresh scan rewrites the findings wholesale — the verdict lives
        // beside them precisely so it survives that.
        $site->update(['opportunities' => [
            'scanned_at' => now()->toIso8601String(),
            'items' => [
                ['key' => 'accessibility', 'title' => 'התאמת נגישות', 'evidence' => '11 ממצאים', 'price_agorot' => 180000, 'severity' => 'high'],
            ],
            'total_agorot' => 180000,
        ]]);

        $this->assertTrue($this->page()->instance()->rows->isEmpty());
    }

    public function test_a_dismissed_opportunity_is_visible_and_restorable(): void
    {
        $site = $this->site();
        $page = $this->page();

        $page->callAction('dismiss', data: ['reason' => 'לא רלוונטי'],
            arguments: ['site' => $site->id, 'key' => 'accessibility']);

        // Hidden is not the same as deleted: the dismissed pile is one click
        // away, with the reason and who decided it.
        $page->set('filter', 'dismissed');
        $item = $page->instance()->rows->first()['items'][0];
        $this->assertSame('accessibility', $item['key']);
        $this->assertSame('לא רלוונטי', $item['reason']);

        $page->callAction('restore', arguments: ['site' => $site->id, 'key' => 'accessibility']);

        $page->set('filter', 'open');
        $this->assertContains('accessibility', collect($page->instance()->rows->first()['items'])->pluck('key'));
    }

    public function test_marking_an_opportunity_as_offered_moves_it_out_of_the_open_list(): void
    {
        $site = $this->site();
        $page = $this->page();

        $page->callAction('markOffered', arguments: ['site' => $site->id, 'key' => 'monitoring']);

        $this->assertSame(['accessibility'], collect($page->instance()->rows->first()['items'])->pluck('key')->all());

        $page->set('filter', 'offered');
        $this->assertSame(['monitoring'], collect($page->instance()->rows->first()['items'])->pluck('key')->all());
    }

    public function test_the_counts_show_what_is_hiding_under_each_verdict(): void
    {
        $site = $this->site();

        OpportunityNote::decide($site->id, 'accessibility', OpportunityNote::DISMISSED);

        $counts = $this->page()->instance()->counts;

        // A dismissed pile that grows unseen is how a radar stops being read.
        $this->assertSame(['open' => 1, 'offered' => 0, 'dismissed' => 1], $counts);
    }

    public function test_an_opportunity_can_be_turned_into_a_task_with_its_evidence(): void
    {
        $site = $this->site();

        $this->page()->callAction('openTask', arguments: ['site' => $site->id, 'key' => 'accessibility']);

        $task = Task::firstOrFail();

        $this->assertStringContainsString('עסק לדוגמה', $task->title);
        $this->assertStringContainsString('התאמת נגישות', $task->title);
        // The evidence travels with the task, so whoever picks it up can quote.
        $this->assertStringContainsString('9 ממצאים', $task->description);
        $this->assertSame($site->customer_id, $task->customer_id);
    }

    public function test_the_urgent_filter_keeps_only_what_must_be_fixed(): void
    {
        $this->site();

        $page = $this->page()->set('urgentOnly', true);

        $this->assertSame(['accessibility'], collect($page->instance()->rows->first()['items'])->pluck('key')->all());
    }

    public function test_search_narrows_to_one_site(): void
    {
        $this->site();
        Site::factory()->create([
            'domain' => 'other.co.il',
            'opportunities' => ['scanned_at' => now()->toIso8601String(), 'items' => [
                ['key' => 'monitoring', 'title' => 'ניטור', 'evidence' => 'כבוי', 'price_agorot' => 30000, 'severity' => 'medium'],
            ], 'total_agorot' => 30000],
        ]);

        $page = $this->page();
        $this->assertCount(2, $page->instance()->rows);

        $this->assertSame(['other.co.il'], $page->set('search', 'other')->instance()->rows->pluck('domain')->all());
        $this->assertSame(['example.co.il'], $page->set('search', 'עסק לדוגמה')->instance()->rows->pluck('domain')->all());
    }

    public function test_a_blacklisted_domain_is_an_urgent_opportunity(): void
    {
        $site = Site::factory()->create([
            'monitor_enabled' => true,
            'reputation_scan' => ['listings' => [
                ['provider' => 'Spamhaus', 'detail' => 'listed'],
                ['provider' => 'URLhaus', 'detail' => 'listed'],
            ]],
        ]);

        $item = collect((new OpportunityRadar)->build($site))->firstWhere('key', 'reputation');

        $this->assertNotNull($item);
        $this->assertSame('high', $item['severity']);
        $this->assertStringContainsString('Spamhaus', $item['evidence']);
    }

    public function test_urgent_findings_come_before_the_merely_recommended(): void
    {
        $site = Site::factory()->create([
            // Monitoring off (medium) and legal docs missing (high).
            'monitor_enabled' => false,
            'compliance_scan' => ['missing_docs' => [['key' => 'privacy', 'label' => 'מדיניות פרטיות']]],
        ]);

        $keys = collect((new OpportunityRadar)->build($site))->pluck('key')->all();

        // The list is read top-down in a sales call.
        $this->assertSame('legal_docs', $keys[0]);
    }
}
