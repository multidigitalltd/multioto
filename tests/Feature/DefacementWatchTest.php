<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteContentJob;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\ContentFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DefacementWatchTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $domain = 'watched.co.il'): Site
    {
        return Site::factory()->create([
            'domain' => $domain,
            'monitor_url' => "https://{$domain}",
            'monitor_enabled' => true,
        ]);
    }

    private function quietTeam(): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');
        $this->app->instance(TeamNotifier::class, $team);
    }

    private function page(string $title, string $body): string
    {
        return "<html><head><title>{$title}</title><style>.x{color:red}</style></head><body>{$body}</body></html>";
    }

    public function test_the_first_run_stores_a_baseline_silently(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response($this->page('חנות הפרחים של דנה', 'ברוכים הבאים לחנות הפרחים שלנו. משלוחים בכל הארץ.'))]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($site->id);

        $snap = $site->refresh()->content_snapshot;
        $this->assertSame('חנות הפרחים של דנה', $snap['title']);
        $this->assertFalse($snap['suspected']);
        $this->assertStringContainsString('משלוחים בכל הארץ', $snap['text']);
        // Styles/scripts are stripped from the fingerprint.
        $this->assertStringNotContainsString('color:red', $snap['text']);
    }

    public function test_ordinary_edits_roll_the_baseline_forward_without_alerting(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response($this->page('חנות הפרחים של דנה', 'ברוכים הבאים לחנות הפרחים שלנו. משלוחים בכל הארץ. עכשיו גם זרי כלות.'))]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($this->baselined($site,
            'חנות הפרחים של דנה', 'ברוכים הבאים לחנות הפרחים שלנו. משלוחים בכל הארץ.')->id);

        $snap = $site->refresh()->content_snapshot;
        $this->assertFalse($snap['suspected']);
        // Baseline rolled forward to the edited content.
        $this->assertStringContainsString('זרי כלות', $snap['text']);
        $this->assertGreaterThan(45, $snap['similarity']);
    }

    public function test_a_drastic_content_change_alerts_once_and_keeps_the_baseline(): void
    {
        $site = $this->baselined($this->site('hacked.co.il'),
            'חנות הפרחים של דנה', 'ברוכים הבאים לחנות הפרחים שלנו. משלוחים בכל הארץ.');

        Http::fake(['*' => Http::response($this->page('pwned', 'completely different content zzz qqq xxx yyy nothing alike whatsoever'))]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, 'חשד להשחתת האתר hacked.co.il'));
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteContentJob::dispatchSync($site->id);
        // Second run with the same defaced content — no second alert.
        CheckSiteContentJob::dispatchSync($site->id);

        $snap = $site->refresh()->content_snapshot;
        $this->assertTrue($snap['suspected']);
        $this->assertSame('pwned', $snap['suspected_title']);
        $this->assertNotNull($snap['alerted_at']);
        // The BASELINE text is kept for comparison, not overwritten by the hack.
        $this->assertStringContainsString('חנות הפרחים', $snap['text']);
    }

    public function test_a_defacement_marker_alerts_even_with_high_similarity(): void
    {
        $base = 'ברוכים הבאים לחנות הפרחים שלנו. משלוחים בכל הארץ. זרי כלה, זרי אבל, סידורי שולחן ועציצים לכל אירוע.';
        $site = $this->baselined($this->site('injected.co.il'), 'חנות הפרחים של דנה', $base);

        // Same page — plus an injected banner.
        Http::fake(['*' => Http::response($this->page('חנות הפרחים של דנה', 'Hacked By DarkTeam. '.$base))]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title, string $body): bool => str_contains($body, 'hacked by'));
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteContentJob::dispatchSync($site->id);

        $this->assertTrue($site->refresh()->content_snapshot['suspected']);
    }

    public function test_accepting_the_current_content_clears_the_suspicion(): void
    {
        $site = $this->baselined($this->site(), 'ישן', 'תוכן ישן לגמרי שכבר לא רלוונטי בכלל.');
        // Standing suspicion from a previous run.
        $site->update(['content_snapshot' => array_merge($site->content_snapshot, [
            'suspected' => true, 'alerted_at' => now()->toIso8601String(),
        ])]);

        Http::fake(['*' => Http::response($this->page('עיצוב חדש', 'האתר החדש והמרהיב שלנו עלה לאוויר עם תוכן חדש לגמרי.'))]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($site->id, true);

        $snap = $site->refresh()->content_snapshot;
        $this->assertFalse($snap['suspected']);
        $this->assertNull($snap['alerted_at']);
        $this->assertSame('עיצוב חדש', $snap['title']);
    }

    public function test_a_fetch_failure_changes_nothing(): void
    {
        $site = $this->baselined($this->site(), 'כותרת', 'תוכן קיים ומוכר של האתר.');
        $before = $site->content_snapshot;

        Http::fake(['*' => Http::response('', 500)]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($site->id);

        $this->assertSame($before['checked_at'], $site->refresh()->content_snapshot['checked_at']);
    }

    public function test_an_error_page_is_not_treated_as_site_content(): void
    {
        $site = $this->baselined($this->site(), 'כותרת', 'תוכן קיים ומוכר של האתר.');
        $before = $site->content_snapshot;

        // A non-empty 403 challenge page — not the site's real content: it must
        // neither roll the baseline forward nor read as a defacement.
        Http::fake(['*' => Http::response($this->page('Access denied', 'You have been blocked by the firewall.'), 403)]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($site->id);

        $this->assertSame($before['checked_at'], $site->refresh()->content_snapshot['checked_at']);
    }

    public function test_the_homepage_is_fingerprinted_even_when_monitoring_probes_a_health_endpoint(): void
    {
        // The uptime probe deliberately watches /health — but a hacked homepage
        // can leave /health intact, so the fingerprint must read the HOMEPAGE.
        $site = $this->baselined(Site::factory()->create([
            'domain' => 'shop.co.il',
            'monitor_url' => 'https://shop.co.il/health',
            'monitor_enabled' => true,
        ]), 'החנות שלנו', 'קטלוג המוצרים המלא שלנו עם משלוחים עד הבית.');

        Http::fake([
            'https://shop.co.il/health' => Http::response('ok'),
            'https://shop.co.il' => Http::response($this->page('pwned', 'totally different english takeover page content here')),
        ]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once();
        $this->app->instance(TeamNotifier::class, $team);

        CheckSiteContentJob::dispatchSync($site->id);

        $this->assertTrue($site->refresh()->content_snapshot['suspected']);
    }

    public function test_similarity_is_word_based_and_unicode_safe(): void
    {
        $fp = app(ContentFingerprint::class);

        // Unrelated Hebrew texts share UTF-8 lead bytes — a byte-based measure
        // would overscore them; word-based similarity must stay near zero.
        $this->assertLessThan(10, $fp->similarity(
            'חנות פרחים משלוחים זרים כלות אירועים עציצים',
            'מוסך רכב תיקונים צמיגים שמן מנוע בדיקת חורף',
        ));

        $this->assertSame(100.0, $fp->similarity('שלום עולם', 'שלום עולם'));
    }

    public function test_the_watch_can_be_disabled_by_config(): void
    {
        config(['security.defacement.enabled' => false]);
        $site = $this->site();
        Http::fake(['*' => Http::response($this->page('x', 'y'))]);
        $this->quietTeam();

        CheckSiteContentJob::dispatchSync($site->id);

        $this->assertNull($site->refresh()->content_snapshot);
    }

    /** Store a clean baseline on the site directly (no HTTP round-trip). */
    private function baselined(Site $site, string $title, string $body): Site
    {
        $fp = app(ContentFingerprint::class)->make($this->page($title, $body));

        $site->update(['content_snapshot' => [
            'checked_at' => now()->subDay()->toIso8601String(),
            'title' => $fp['title'],
            'text' => $fp['text'],
            'hash' => $fp['hash'],
            'length' => $fp['length'],
            'similarity' => null,
            'suspected' => false,
            'suspected_title' => null,
            'marker' => null,
            'alerted_at' => null,
        ]]);

        return $site->refresh();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
