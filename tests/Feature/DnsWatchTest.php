<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteDnsJob;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\DnsLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DnsWatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a DnsLookup whose low-level query is stubbed per record type.
     * MX entries are "priority host" strings (e.g. "10 mail.example.com").
     */
    private function fakeDns(?array $a, ?array $mx, ?array $ns, mixed $expectedHost = null): void
    {
        $host = $expectedHost ?? Mockery::any();

        $lookup = Mockery::mock(DnsLookup::class.'[query]')
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();
        $lookup->shouldReceive('query')->with($host, DNS_A)
            ->andReturn($a === null ? null : array_map(fn (string $ip): array => ['ip' => $ip], $a));
        $lookup->shouldReceive('query')->with($host, DNS_MX)
            ->andReturn($mx === null ? null : array_map(function (string $entry): array {
                [$pri, $target] = explode(' ', $entry, 2);

                return ['target' => $target, 'pri' => (int) $pri];
            }, $mx));
        $lookup->shouldReceive('query')->with($host, DNS_NS)
            ->andReturn($ns === null ? null : array_map(fn (string $h): array => ['target' => $h], $ns));

        $this->app->instance(DnsLookup::class, $lookup);
    }

    private function quietTeam(): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');
        $this->app->instance(TeamNotifier::class, $team);
    }

    public function test_the_first_run_stores_a_baseline_without_alerting(): void
    {
        $this->fakeDns(['1.2.3.4'], ['10 Mail.Example.com.'], ['ns1.example.com', 'ns2.example.com']);
        $this->quietTeam();

        $site = Site::factory()->create(['domain' => 'watched.co.il']);

        CheckSiteDnsJob::dispatchSync($site->id);

        $snap = $site->refresh()->dns_snapshot;
        $this->assertSame(['1.2.3.4'], $snap['records']['a']);
        // Normalized: lowercase, trailing dot stripped.
        $this->assertSame(['10 mail.example.com'], $snap['records']['mx']);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $snap['records']['ns']);
        $this->assertNull($snap['changed_at']);
    }

    public function test_a_changed_a_record_alerts_the_team_with_the_diff(): void
    {
        $site = Site::factory()->create([
            'domain' => 'hijacked.co.il',
            'dns_snapshot' => [
                'domain' => 'hijacked.co.il',
                'checked_at' => now()->subDay()->toIso8601String(),
                'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.example.com'], 'ns' => ['ns1.example.com']],
                'changed_at' => null,
            ],
        ]);

        $this->fakeDns(['9.9.9.9'], ['10 mail.example.com'], ['ns1.example.com']);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(function (string $title, string $body): bool {
            return str_contains($title, 'שינוי DNS')
                && str_contains($body, '9.9.9.9')
                && str_contains($body, '1.2.3.4');
        });
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteDnsJob::dispatchSync($site->id);

        $snap = $site->refresh()->dns_snapshot;
        $this->assertSame(['9.9.9.9'], $snap['records']['a']);
        $this->assertNotNull($snap['changed_at']);
    }

    public function test_unchanged_records_do_not_alert_or_stamp_a_change(): void
    {
        $site = Site::factory()->create([
            'domain' => 'stable.co.il',
            'dns_snapshot' => [
                'domain' => 'stable.co.il',
                'checked_at' => now()->subDay()->toIso8601String(),
                'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.example.com'], 'ns' => ['ns1.example.com']],
                'changed_at' => null,
            ],
        ]);

        // Same values, different order/case — normalization must equalize them.
        $this->fakeDns(['1.2.3.4'], ['10 MAIL.example.com.'], ['NS1.EXAMPLE.COM']);
        $this->quietTeam();

        CheckSiteDnsJob::dispatchSync($site->id);

        $this->assertNull($site->refresh()->dns_snapshot['changed_at']);
    }

    public function test_a_resolver_outage_keeps_the_last_known_state(): void
    {
        $site = Site::factory()->create([
            'domain' => 'flaky.co.il',
            'dns_snapshot' => [
                'domain' => 'flaky.co.il',
                'checked_at' => now()->subDay()->toIso8601String(),
                'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.example.com'], 'ns' => ['ns1.example.com']],
                'changed_at' => null,
            ],
        ]);

        // MX lookup failed this cycle; A and NS answered unchanged.
        $this->fakeDns(['1.2.3.4'], null, ['ns1.example.com']);
        $this->quietTeam();

        CheckSiteDnsJob::dispatchSync($site->id);

        $snap = $site->refresh()->dns_snapshot;
        // The failed type keeps its last known value and no change is claimed.
        $this->assertSame(['10 mail.example.com'], $snap['records']['mx']);
        $this->assertNull($snap['changed_at']);
    }

    public function test_a_total_outage_leaves_the_snapshot_untouched(): void
    {
        $before = [
            'domain' => 'down-resolver.co.il',
            'checked_at' => now()->subDay()->toIso8601String(),
            'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.example.com'], 'ns' => ['ns1.example.com']],
            'changed_at' => null,
        ];
        $site = Site::factory()->create(['domain' => 'down-resolver.co.il', 'dns_snapshot' => $before]);

        $this->fakeDns(null, null, null);
        $this->quietTeam();

        CheckSiteDnsJob::dispatchSync($site->id);

        $this->assertSame($before['checked_at'], $site->refresh()->dns_snapshot['checked_at']);
    }

    public function test_a_subdirectory_install_is_queried_by_its_bare_host(): void
    {
        // The sites table can hold "host/path" (subdirectory installs) — the
        // DNS query must use the bare hostname or every lookup fails.
        $this->fakeDns(['1.2.3.4'], ['10 mail.example.com'], ['ns1.example.com'], expectedHost: 'sub.example.com');
        $this->quietTeam();

        $site = Site::factory()->create(['domain' => 'sub.example.com/blog']);

        CheckSiteDnsJob::dispatchSync($site->id);

        $snap = $site->refresh()->dns_snapshot;
        $this->assertSame('sub.example.com', $snap['domain']);
        $this->assertSame(['1.2.3.4'], $snap['records']['a']);
    }

    public function test_a_domain_change_re_baselines_silently(): void
    {
        // The operator moved the site to a new domain — the old snapshot
        // describes another hostname, so no "hijack" alert; a fresh baseline.
        $site = Site::factory()->create([
            'domain' => 'new-domain.co.il',
            'dns_snapshot' => [
                'domain' => 'old-domain.co.il',
                'checked_at' => now()->subDay()->toIso8601String(),
                'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.old.co.il'], 'ns' => ['ns1.old.co.il']],
                'changed_at' => null,
            ],
        ]);

        $this->fakeDns(['9.9.9.9'], null, ['ns1.new.co.il']);
        $this->quietTeam();

        CheckSiteDnsJob::dispatchSync($site->id);

        $snap = $site->refresh()->dns_snapshot;
        $this->assertSame('new-domain.co.il', $snap['domain']);
        $this->assertSame(['9.9.9.9'], $snap['records']['a']);
        // The old host's MX must NOT carry over into the new host's baseline.
        $this->assertNull($snap['records']['mx']);
        $this->assertNull($snap['changed_at']);
    }

    public function test_an_mx_priority_change_alerts(): void
    {
        $site = Site::factory()->create([
            'domain' => 'mailflip.co.il',
            'dns_snapshot' => [
                'domain' => 'mailflip.co.il',
                'checked_at' => now()->subDay()->toIso8601String(),
                'records' => ['a' => ['1.2.3.4'], 'mx' => ['10 mail.example.com', '20 backup.example.com'], 'ns' => ['ns1.example.com']],
                'changed_at' => null,
            ],
        ]);

        // Same hosts — but the backup was promoted to receive mail first.
        $this->fakeDns(['1.2.3.4'], ['10 backup.example.com', '20 mail.example.com'], ['ns1.example.com']);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, 'שינוי DNS'));
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteDnsJob::dispatchSync($site->id);

        $this->assertNotNull($site->refresh()->dns_snapshot['changed_at']);
    }

    public function test_the_watch_can_be_disabled_by_config(): void
    {
        config(['security.dns_watch.enabled' => false]);
        $this->quietTeam();

        $site = Site::factory()->create(['domain' => 'off.co.il']);

        CheckSiteDnsJob::dispatchSync($site->id);

        $this->assertNull($site->refresh()->dns_snapshot);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
