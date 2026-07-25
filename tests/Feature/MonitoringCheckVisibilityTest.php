<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Jobs\CheckSiteReputationJob;
use App\Models\Site;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\DomainReputationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A monitoring check must never dead-end silently: the site page always shows
 * the reputation/security/defacement/DNS cards (with a "not yet run" state),
 * and every path that skips a check — Shabbat hold, missing domain, manual
 * dispatch — leaves a trace in the event log.
 */
class MonitoringCheckVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_site_page_shows_all_check_cards_even_before_any_run(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['domain' => 'fresh.co.il']);

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->assertSeeText('אבטחה — רכיבים פגיעים')
            ->assertSeeText('טרם הושלמה סריקה מלאה')
            ->assertSeeText('מוניטין דומיין — רשימות חסימה')
            ->assertSeeText('טרם הושלמה בדיקת מוניטין')
            ->assertSeeText('זיהוי השחתה — תוכן דף הבית')
            ->assertSeeText('טרם נלקחה טביעת תוכן')
            ->assertSeeText('רשומות DNS — מעקב שינויים')
            ->assertSeeText('טרם נלקחה תמונת DNS');
    }

    public function test_the_defacement_card_names_the_real_blocker_instead_of_promising_runs(): void
    {
        // A site the scheduler skips (monitoring off) must not claim the check
        // "runs every morning" — it never will until monitoring is enabled.
        $this->actingAs(User::factory()->create());
        $off = Site::factory()->create(['domain' => 'off.co.il', 'monitor_enabled' => false]);

        Livewire::test(ViewSite::class, ['record' => $off->getRouteKey()])
            ->assertSeeText('הניטור לאתר כבוי')
            ->assertDontSeeText('טרם נלקחה טביעת תוכן');
    }

    public function test_an_unexpected_job_crash_is_recorded_in_the_event_log(): void
    {
        $site = Site::factory()->create(['domain' => 'crash.co.il']);

        (new CheckSiteReputationJob($site->id))->failed(new \RuntimeException('boom'));

        $this->assertTrue(
            SystemLog::query()
                ->where('level', 'error')
                ->where('message', 'like', '%שגיאה לא צפויה%boom%')
                ->exists(),
        );
    }

    public function test_a_manual_check_click_is_recorded_in_the_event_log(): void
    {
        // If this entry appears but no result follows, the operator can tell
        // the queue worker is down — previously the click left zero traces.
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['domain' => 'clicked.co.il']);
        Queue::fake();

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->callAction('checkReputation');

        Queue::assertPushed(CheckSiteReputationJob::class, fn (CheckSiteReputationJob $job): bool => $job->siteId === $site->id);
        $this->assertTrue(
            SystemLog::query()->where('message', 'like', '%בדיקת מוניטין נשלחה ידנית לתור%')->exists(),
        );
    }

    public function test_monitoring_checks_run_immediately_even_during_shabbat(): void
    {
        // Monitoring is INTERNAL — only customer-facing automations pause for
        // Shabbat. A check requested on Saturday runs now, not the day after.
        config(['billing.shabbat.block_automations' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00', 'Asia/Jerusalem'));

        $site = Site::factory()->create(['domain' => '']);
        Queue::fake();

        (new CheckSiteReputationJob($site->id))->handle(app(DomainReputationClient::class), app(TeamNotifier::class));

        // It proceeded past where the old hold used to be (the no-domain
        // warning was written) and was NOT re-queued for after Shabbat.
        Queue::assertNothingPushed();
        $this->assertTrue(SystemLog::query()->where('message', 'like', '%לא מוגדר דומיין%')->exists());
        $this->assertFalse(SystemLog::query()->where('message', 'like', '%הושהתה לשבת%')->exists());
    }

    public function test_a_reputation_check_without_a_domain_logs_a_warning(): void
    {
        config(['billing.shabbat.block_automations' => false]);
        $site = Site::factory()->create(['domain' => '']);

        (new CheckSiteReputationJob($site->id))->handle(app(DomainReputationClient::class), app(TeamNotifier::class));

        $this->assertTrue(
            SystemLog::query()
                ->where('level', 'warning')
                ->where('message', 'like', '%לא מוגדר דומיין%')
                ->exists(),
        );
    }

    public function test_a_disabled_reputation_config_logs_why_nothing_ran(): void
    {
        config(['billing.shabbat.block_automations' => false, 'security.reputation.enabled' => false]);
        $site = Site::factory()->create(['domain' => 'disabled.co.il']);

        (new CheckSiteReputationJob($site->id))->handle(app(DomainReputationClient::class), app(TeamNotifier::class));

        $this->assertTrue(
            SystemLog::query()->where('message', 'like', '%מושבתות בהגדרות%')->exists(),
        );
    }
}
