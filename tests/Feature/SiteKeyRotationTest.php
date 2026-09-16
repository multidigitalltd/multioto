<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\LockOutSiteSessionsJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

        // The single most common real failure: the host will not let anything
        // write wp-config.php. If that stopped the job, the one containment
        // still available would never run.
        Http::fakeSequence()
            ->push($this->toolResult('{"error":"read only"}', isError: true))
            ->push($this->toolResult('{"ok":true,"users_signed_out":4}'));

        $this->lockOut($site);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('sessions_lock_failed', $event->type);
        $this->assertSame('critical', $event->severity);
        // And the report says which half happened, rather than one verdict for
        // two different things.
        $this->assertStringContainsString('✗ החלפת מפתחות נכשלה', $event->detail);
        $this->assertStringContainsString('✓ אסימוני ההתחברות נמחקו', $event->detail);
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
