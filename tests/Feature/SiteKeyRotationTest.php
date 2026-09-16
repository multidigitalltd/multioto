<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Filament\Pages\SecurityPosture;
use App\Jobs\LockOutSiteSessionsJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ending every login on a site — monthly, and after somebody gets in.
 *
 * The two steps are deliberately both attempted and never allowed to stand in
 * for each other: rotating the salts needs wp-config.php to be writable, which
 * on plenty of hosts it is not, and destroying the session tokens needs only
 * the database. Reporting "sessions ended" when only one of them ran — on the
 * day of a break-in — is the failure this shape exists to avoid.
 */
class SiteKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_both_steps_run_and_the_site_records_it(): void
    {
        $site = $this->site();
        Http::fakeSequence()
            ->push($this->toolResult('{"ok":true,"rotated":8}'))
            ->push($this->toolResult('{"ok":true,"users_signed_out":12}'));

        $this->lockOut($site);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('sessions_locked', $event->type);
        $this->assertStringContainsString('✓ מפתחות ההצפנה הוחלפו', $event->detail);
        $this->assertStringContainsString('✓ אסימוני ההתחברות נמחקו', $event->detail);
    }

    public function test_a_read_only_config_does_not_stop_the_sessions_being_cut(): void
    {
        $site = $this->site();

        // The host will not let anything write wp-config.php. If that stopped
        // the job, the one containment still available would never run.
        Http::fakeSequence()
            ->push($this->toolResult('{"error":"read only"}', isError: true))
            ->push($this->toolResult('{"ok":true,"users_signed_out":4}'));

        $this->lockOut($site);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        // Every session ended, so the containment succeeded — calling this a
        // failure would raise an emergency about a site that is fine.
        $this->assertSame('sessions_locked', $event->type);
        // And the report still says which half happened: a config nobody can
        // write is worth knowing before the day it is all that matters.
        $this->assertStringContainsString('✗ החלפת מפתחות נכשלה', $event->detail);
        $this->assertStringContainsString('✓ אסימוני ההתחברות נמחקו', $event->detail);
    }

    public function test_an_older_plugin_without_the_session_tool_is_still_contained(): void
    {
        $site = $this->site();

        // Most sites will run a plugin older than the session tool for a while
        // after this ships. Rotating the salts already invalidated every cookie
        // there, and reporting a monthly critical for each of them would be an
        // alert storm about sites that were never at risk.
        Http::fakeSequence()
            ->push($this->toolResult('{"ok":true,"rotated":8}'))
            ->push($this->toolResult('{"error":"unknown tool wp_sessions_destroy"}', isError: true));

        $notifier = \Mockery::mock(TeamNotifier::class);
        $notifier->shouldNotReceive('alert');

        (new LockOutSiteSessionsJob($site->id))->handle(app(McpClient::class), $notifier);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('sessions_locked', $event->type);
        $this->assertSame('info', $event->severity);
    }

    public function test_only_a_site_where_nothing_worked_is_called_a_failure(): void
    {
        $site = $this->site();
        Http::fakeSequence()
            ->push($this->toolResult('{"error":"read only"}', isError: true))
            ->push($this->toolResult('{"error":"db down"}', isError: true));

        $this->lockOut($site);

        // Neither step ran: somebody may still be logged in, and that is the
        // one case worth waking a person for.
        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('sessions_lock_failed', $event->type);
        $this->assertSame('critical', $event->severity);
    }

    public function test_a_failed_rotation_is_never_silent(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response([], 500)]);

        $notifier = \Mockery::mock(TeamNotifier::class);
        // A site whose keys could not be replaced is one where a stolen cookie
        // still works, and nobody else in the system will notice.
        $notifier->shouldReceive('alert')->once();

        (new LockOutSiteSessionsJob($site->id))->handle(app(McpClient::class), $notifier);

        $this->assertSame('sessions_lock_failed', SiteEvent::where('site_id', $site->id)->sole()->type);
    }

    public function test_a_routine_rotation_that_worked_does_not_page_anybody(): void
    {
        $site = $this->site();
        Http::fakeSequence()
            ->push($this->toolResult('{"ok":true}'))
            ->push($this->toolResult('{"ok":true}'));

        $notifier = \Mockery::mock(TeamNotifier::class);
        // Monthly hygiene that worked is not news, and an alert every month for
        // every site is how people stop reading the alerts that matter.
        $notifier->shouldNotReceive('alert');

        (new LockOutSiteSessionsJob($site->id, LockOutSiteSessionsJob::REASON_ROUTINE))
            ->handle(app(McpClient::class), $notifier);

        $this->assertSame('info', SiteEvent::where('site_id', $site->id)->sole()->severity);
    }

    public function test_an_intrusion_rotation_always_tells_the_team(): void
    {
        $site = $this->site();
        Http::fakeSequence()
            ->push($this->toolResult('{"ok":true}'))
            ->push($this->toolResult('{"ok":true}'));

        $notifier = \Mockery::mock(TeamNotifier::class);
        // Somebody got in. Even a clean containment is something a person has
        // to know happened.
        $notifier->shouldReceive('alert')->once();

        (new LockOutSiteSessionsJob($site->id, LockOutSiteSessionsJob::REASON_INTRUSION))
            ->handle(app(McpClient::class), $notifier);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('sessions_locked', $event->type);
        $this->assertSame('warning', $event->severity);
        $this->assertStringContainsString('פריצה', $event->title);
    }

    public function test_a_disconnected_site_is_left_alone(): void
    {
        $site = $this->site(['mcp_enabled' => false]);
        Http::fake();

        $this->lockOut($site);

        // Nothing to talk to, so nothing to claim happened.
        $this->assertSame(0, SiteEvent::where('site_id', $site->id)->count());
        Http::assertNothingSent();
    }

    public function test_the_monthly_run_covers_every_connected_site(): void
    {
        Queue::fake();
        config(['security.key_rotation.enabled' => true]);

        $connected = $this->site();
        $this->site(['mcp_enabled' => false]);            // nothing to talk to
        $this->site(['status' => SiteStatus::Suspended]); // not a live site

        $this->runScheduled('security:rotate-site-keys');

        Queue::assertPushed(LockOutSiteSessionsJob::class, 1);
        Queue::assertPushed(
            LockOutSiteSessionsJob::class,
            fn (LockOutSiteSessionsJob $job): bool => $job->siteId === $connected->id
                && $job->reason === LockOutSiteSessionsJob::REASON_ROUTINE,
        );
    }

    public function test_the_monthly_rotation_can_be_switched_off(): void
    {
        Queue::fake();
        // Off means nothing is dispatched — not that a flag reads false. Every
        // customer on every site is signed out by this run, so the switch has
        // to actually stop it.
        config(['security.key_rotation.enabled' => false]);

        $this->site();

        $this->runScheduled('security:rotate-site-keys');

        Queue::assertNotPushed(LockOutSiteSessionsJob::class);
    }

    public function test_the_security_screen_states_the_standing_rules(): void
    {
        $this->actingAs(User::factory()->create());
        config([
            'security.quarantine.enabled' => true,
            'security.quarantine.users' => ['sys_maint'],
            'security.quarantine.plugins' => ['wp-file-manager'],
            'security.key_rotation.enabled' => true,
        ]);

        Livewire::test(SecurityPosture::class)
            ->assertOk()
            ->assertSee('sys_maint', false)
            ->assertSee('wp-file-manager', false)
            ->assertDontSee('לא פעיל', false);
    }

    public function test_a_rule_that_is_switched_off_says_so_rather_than_reading_as_cover(): void
    {
        $this->actingAs(User::factory()->create());
        config(['security.key_rotation.enabled' => false]);

        // A screen that lists a protection it is not applying is worse than one
        // that lists nothing.
        Livewire::test(SecurityPosture::class)
            ->assertOk()
            ->assertSee('לא פעיל', false)
            ->assertSee('כבויה', false);
    }

    public function test_the_screen_counts_the_sites_nothing_on_it_protects(): void
    {
        $this->actingAs(User::factory()->create());

        $this->site();                          // connected
        $this->site(['mcp_enabled' => false]);  // the plugin cannot be reached

        Livewire::test(SecurityPosture::class)
            ->assertOk()
            ->assertSee('1 אתרים פעילים אינם מחוברים לתוסף', false);
    }

    public function test_the_screen_lists_what_the_guard_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $site = $this->site();

        SiteEvent::record($site->id, 'threat_purged', 'critical', 'משתמש בהסגר הוסר אוטומטית: sys_maint', 'משתמש #12 נמחק.');
        SiteEvent::record($site->id, 'sessions_locked', 'warning', 'נותקו כל ההתחברויות בעקבות חשד לפריצה', '');

        Livewire::test(SecurityPosture::class)
            ->assertOk()
            ->assertSee('sys_maint', false)
            ->assertSee('נותקו כל ההתחברויות', false);
    }

    /** Run one scheduled task by the name the scheduler registered it under. */
    private function runScheduled(string $name): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === $name);

        $this->assertNotNull($event, "אין משימה מתוזמנת בשם {$name}");

        $event->run(app());
    }

    /** @param array<string, mixed> $attributes */
    private function site(array $attributes = []): Site
    {
        return Site::factory()->create(array_merge([
            'customer_id' => Customer::factory(),
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ], $attributes));
    }

    private function lockOut(Site $site): void
    {
        (new LockOutSiteSessionsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));
    }

    /** An MCP tool response as the plugin returns it. */
    private function toolResult(string $text, bool $isError = false): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError],
        ];
    }
}
