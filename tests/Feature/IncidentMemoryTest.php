<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Jobs\InvestigateSiteJob;
use App\Models\IncidentResolution;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\IncidentMemory;
use App\Services\Agent\SiteActionRunner;
use App\Services\Agent\SiteAgent;
use App\Services\Agent\SiteMemoryStore;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\HostingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The agent's incident memory: executed fixes are recorded against the
 * problem they treated, the verification round marks them proven, and new
 * investigations get the relevant history back — same site and cross-site.
 */
class IncidentMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.owner_number' => '']); // panel-only gate in these tests
        Http::fake();
    }

    public function test_an_executed_ai_fix_is_recorded_in_the_incident_memory(): void
    {
        $site = Site::factory()->create(['domain' => 'remembered.co.il']);
        $runner = Mockery::mock(SiteActionRunner::class);
        $runner->shouldReceive('run')->once();
        $this->app->instance(SiteActionRunner::class, $runner);
        Queue::fake(); // capture the verification dispatch

        $action = PendingAction::create([
            'type' => 'site_action', 'status' => ActionStatus::Pending,
            'summary' => 'ניקוי מטמון לתיקון איטיות',
            'payload' => ['site_id' => $site->id, 'tool' => 'wp_cache_flush', 'goal' => 'האתר איטי מאוד בעמוד הבית', 'round' => 1],
            'proposed_by' => 'ai',
        ]);

        app(ApprovalGate::class)->approve($action);

        $resolution = IncidentResolution::sole();
        $this->assertSame($site->id, $resolution->site_id);
        $this->assertSame('האתר איטי מאוד בעמוד הבית', $resolution->problem);
        $this->assertSame('wp_cache_flush', $resolution->fix_tool);
        $this->assertFalse($resolution->verified);

        // The verification round carries the resolution id, so a "solved"
        // summary can upgrade the memory to verified.
        Queue::assertPushed(InvestigateSiteJob::class, fn (InvestigateSiteJob $job): bool => $job->verifiesResolutionId === $resolution->id && $job->round === 2);
    }

    public function test_a_solved_verification_marks_the_resolution_verified(): void
    {
        $site = Site::factory()->create();
        $resolution = app(IncidentMemory::class)->record($site, 'האתר איטי', 'wp_cache_flush');

        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldReceive('investigate')->andReturn('✅ הבעיה נפתרה — זמני הטעינה חזרו לתקין.');
        $this->app->instance(SiteAgent::class, $agent);

        (new InvestigateSiteJob($site->id, 'אימות', 2, null, $resolution->id))
            ->handle(app(SiteAgent::class), app(SiteMemoryStore::class));

        $this->assertTrue($resolution->fresh()->verified);
    }

    public function test_an_unsolved_verification_keeps_the_resolution_unverified(): void
    {
        $site = Site::factory()->create();
        $resolution = app(IncidentMemory::class)->record($site, 'האתר איטי', 'wp_cache_flush');

        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldReceive('investigate')->andReturn('הבעיה עדיין קיימת — מוצע צעד נוסף.');
        $this->app->instance(SiteAgent::class, $agent);

        (new InvestigateSiteJob($site->id, 'אימות', 2, null, $resolution->id))
            ->handle(app(SiteAgent::class), app(SiteMemoryStore::class));

        $this->assertFalse($resolution->fresh()->verified);
    }

    public function test_a_negative_verification_phrase_is_not_read_as_success(): void
    {
        // "לא ניתן לאשר שהבעיה נפתרה" contains the phrase but is NOT the
        // mandated leading "✅ הבעיה נפתרה" marker.
        $site = Site::factory()->create();
        $resolution = app(IncidentMemory::class)->record($site, 'האתר איטי', 'wp_cache_flush');

        $agent = Mockery::mock(SiteAgent::class);
        $agent->shouldReceive('investigate')->andReturn('לא ניתן לאשר שהבעיה נפתרה — נדרשת בדיקה נוספת.');
        $this->app->instance(SiteAgent::class, $agent);

        (new InvestigateSiteJob($site->id, 'אימות', 2, null, $resolution->id))
            ->handle(app(SiteAgent::class), app(SiteMemoryStore::class));

        $this->assertFalse($resolution->fresh()->verified);
    }

    public function test_a_single_generic_shared_word_is_not_enough_to_cross_sites(): void
    {
        $memory = app(IncidentMemory::class);
        $site = Site::factory()->create();
        $other = Site::factory()->create(['domain' => 'other.co.il']);

        // Shares only the generic "האתר" (stop word) — must not be suggested.
        $memory->record($other, 'האתר מציג שגיאת 500', 'wp_plugin_rollback')->update(['verified' => true]);

        $this->assertSame('', $memory->contextFor($site, 'האתר איטי מאוד בשעות הבוקר'));
    }

    public function test_a_memory_write_failure_does_not_fail_the_executed_action(): void
    {
        // The fix already ran — a memory hiccup must not flip it to Failed.
        $site = Site::factory()->create();
        $runner = Mockery::mock(SiteActionRunner::class);
        $runner->shouldReceive('run')->once();
        $this->app->instance(SiteActionRunner::class, $runner);

        $failing = Mockery::mock(IncidentMemory::class);
        $failing->shouldReceive('record')->andThrow(new \RuntimeException('db down'));
        $this->app->instance(IncidentMemory::class, $failing);
        Queue::fake();

        $action = PendingAction::create([
            'type' => 'site_action', 'status' => ActionStatus::Pending,
            'summary' => 'ניקוי מטמון',
            'payload' => ['site_id' => $site->id, 'tool' => 'wp_cache_flush', 'goal' => 'האתר איטי', 'round' => 1],
            'proposed_by' => 'ai',
        ]);

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
    }

    public function test_context_includes_same_site_history_and_similar_verified_cross_site_fixes(): void
    {
        $memory = app(IncidentMemory::class);
        $site = Site::factory()->create(['domain' => 'current.co.il']);
        $other = Site::factory()->create(['domain' => 'other.co.il']);
        $unrelated = Site::factory()->create(['domain' => 'unrelated.co.il']);

        $memory->record($site, 'שגיאת 500 אחרי עדכון תוסף', 'wp_plugin_rollback');
        $memory->record($other, 'האתר מציג שגיאת 500 אחרי עדכון תוסף אבטחה', 'wp_plugin_rollback')->update(['verified' => true]);
        $memory->record($unrelated, 'תעודת SSL פגה', 'hosting:restart')->update(['verified' => true]);

        $context = $memory->contextFor($site, 'האתר מחזיר שגיאת 500 אחרי עדכון תוסף');

        $this->assertStringContainsString('האתר הזה', $context);
        $this->assertStringContainsString('other.co.il', $context);
        $this->assertStringContainsString('wp_plugin_rollback', $context);
        $this->assertStringNotContainsString('unrelated.co.il', $context);
    }

    public function test_unverified_cross_site_fixes_are_not_suggested(): void
    {
        $memory = app(IncidentMemory::class);
        $site = Site::factory()->create();
        $other = Site::factory()->create(['domain' => 'other.co.il']);

        // Same problem wording, but the fix was never verified — cross-site
        // memory must only propagate PROVEN solutions.
        $memory->record($other, 'שגיאת 500 אחרי עדכון תוסף', 'wp_plugin_rollback');

        $this->assertSame('', $memory->contextFor($site, 'שגיאת 500 אחרי עדכון תוסף'));
    }

    public function test_a_hosting_fix_is_recorded_too(): void
    {
        $site = Site::factory()->create();
        $hosting = Mockery::mock(HostingClient::class);
        $hosting->shouldReceive('clearCache')->once();
        $this->app->instance(HostingClient::class, $hosting);

        $action = PendingAction::create([
            'type' => 'site_fix', 'status' => ActionStatus::Pending,
            'summary' => 'ניקוי מטמון אחרי איטיות',
            'payload' => ['site_id' => $site->id, 'fix' => 'clear_cache'],
            'proposed_by' => 'ai',
        ]);

        app(ApprovalGate::class)->approve($action);

        $this->assertTrue(IncidentResolution::where('fix_tool', 'hosting:clear_cache')->exists());
    }
}
