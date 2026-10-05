<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Everything the site's plugin can do, the owner can do from WhatsApp — or it
 * is on a short list, with the reason it is not.
 *
 * The first test is the inventory: it reads the plugin's own tool list and
 * fails the moment a tool exists that the bot neither reaches nor deliberately
 * leaves out. Adding a capability to the plugin and forgetting the bot is then
 * a red build, not a gap discovered by a customer.
 *
 * The rest are the new changes themselves, each checked the way the others
 * are: shown before, re-checked at execution, undone only while nothing moved.
 */
class SiteAgentCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What the bot deliberately does not do from a phone, and why. A tool on
     * this list that no longer exists in the plugin fails the test too, so the
     * list cannot rot into a set of excuses for tools long gone.
     */
    private const NOT_FROM_WHATSAPP = [
        // Security and incident response — the team's, behind its own approval gate.
        'wp_guard_status' => 'security', 'wp_guard_purge' => 'security', 'wp_guard_rules' => 'security',
        'wp_salts_rotate' => 'security', 'wp_sessions_destroy' => 'security', 'wp_admin_list' => 'security',
        // Diagnostics that expose configuration and server paths.
        'wp_option_get' => 'diagnostics', 'wp_error_log_tail' => 'diagnostics',
        // Updates and plugin switches can take a site down; they run under the
        // team's maintenance, with backups, not from a chat.
        'wp_plugin_update' => 'maintenance', 'wp_core_update' => 'maintenance', 'wp_core_rollback' => 'maintenance',
        'wp_plugin_activate' => 'maintenance', 'wp_plugin_deactivate' => 'maintenance',
        // Code on the server — never from a phone.
        'wp_file_list' => 'code', 'wp_file_get' => 'code', 'wp_file_put' => 'code',
        // Permanent deletion of a file other pages may still use.
        'wp_media_delete' => 'permanent',
    ];

    /** Plugin tools the bot reaches through its fixed planners rather than a tool of its own. */
    private const THROUGH_PLANNERS = [
        'wp_content_update', 'wp_elementor_text_update', 'wp_media_upload', 'wp_post_thumbnail_set',
        'wc_product_update',
    ];

    /**
     * Reached only as the undo of another change. Restoring from the trash is
     * here because the plugin cannot list what is IN the trash, so the bot has
     * no way to find an older trashed item — "בטל" right after is the path.
     */
    private const THROUGH_UNDO = ['wp_content_restore'];

    private array $calls = [];

    private array $site = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['siteagent.enabled' => true, 'siteagent.assistant.enabled' => true]);
        Cache::flush();
        $this->fakeSite();
    }

    public function test_every_plugin_capability_is_reachable_or_deliberately_excluded(): void
    {
        $source = file_get_contents(base_path('wordpress-plugin/multioto-agent/includes/class-mcp-server.php'));
        preg_match_all("/\\['name' => '((?:wp|wc|wcs)_[a-z_]+)'/", $source, $matches);
        $plugin = array_values(array_unique($matches[1]));

        $this->assertGreaterThan(50, count($plugin), 'The plugin tool list could not be read.');

        $covered = array_unique([
            ...SiteAgentToolbox::pluginTools(),
            ...app(SiteActionProposer::class)->pluginTools(),
            ...self::THROUGH_PLANNERS,
            ...self::THROUGH_UNDO,
        ]);

        $missing = array_values(array_diff($plugin, $covered, array_keys(self::NOT_FROM_WHATSAPP)));
        $stale = array_values(array_diff(array_keys(self::NOT_FROM_WHATSAPP), $plugin));

        $this->assertSame([], $missing, 'The plugin can do this and the bot cannot: '.implode(', ', $missing));
        $this->assertSame([], $stale, 'Excluded tools the plugin no longer has: '.implode(', ', $stale));
    }

    public function test_approving_a_comment_shows_it_and_undoes_only_while_unchanged(): void
    {
        $subscriber = $this->subscriber();
        $comments = fn (string $status): array => ['comments' => [
            ['id' => 41, 'author' => 'רון', 'post_title' => 'מבצע', 'status' => $status, 'text' => 'מחיר מעולה!'],
        ]];
        $this->site['wp_comment_list'] = $comments('hold');

        $this->model(function (Closure $tool): string {
            $tool('find_comments', ['status' => 'hold']);
            $tool('propose_comment_moderation', ['comment_id' => 41, 'status' => 'approve']);

            return '';
        });

        $preview = $this->talk($subscriber, 'תאשר את התגובה של רון');
        $this->assertStringContainsString('מחיר מעולה!', $preview);
        $this->assertStringContainsString('ממתינה לאישור ← מאושרת', $preview);

        $this->site['wp_comment_moderate'] = ['comment_id' => 41, 'status' => 'approve', 'changed' => true, 'previous' => ['status' => 'hold']];
        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));

        // Somebody marked it spam since: the undo leaves that alone.
        $this->site['wp_comment_list'] = $comments('spam');
        $this->assertStringContainsString('לא החזרתי', $this->talk($subscriber, 'בטל'));
    }

    public function test_categories_that_changed_since_the_preview_are_not_overwritten(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 7, 'title' => 'חולצה']];
        $this->site['wp_post_terms_get'] = ['id' => 7, 'taxonomy' => 'product_cat', 'term_ids' => [3], 'terms' => ['חולצות']];

        $this->model(function (Closure $tool): string {
            $tool('find_content', ['type' => 'product']);
            $tool('propose_item_terms', ['id' => 7, 'taxonomy' => 'product_cat', 'terms' => ['מבצעים']]);

            return '';
        });

        $preview = $this->talk($subscriber, 'תוסיף את החולצה למבצעים');
        $this->assertStringContainsString('אחרי: חולצות, מבצעים', $preview);

        // Re-categorised in wp-admin while the offer waited.
        $this->site['wp_post_terms_get'] = ['id' => 7, 'taxonomy' => 'product_cat', 'term_ids' => [3, 9], 'terms' => ['חולצות', 'חדש']];
        $this->talk($subscriber, 'כן');

        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        $this->assertNotContains('wp_post_terms_set', array_column($this->calls, 0));
    }

    public function test_a_custom_field_the_item_does_not_have_is_refused(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 12, 'title' => 'דירה ברמת גן']];
        $this->site['wp_content_get'] = ['id' => 12, 'title' => 'דירה ברמת גן', 'type' => 'property', 'fields' => ['price' => '2500000']];
        $this->site['wp_fields_schema'] = [['key' => 'price'], ['key' => 'rooms']];

        $this->model(function (Closure $tool): string {
            $tool('find_content', ['type' => 'property']);
            $bad = $tool('propose_fields_update', ['id' => 12, 'fields' => ['prcie' => '2400000']]);
            $this->assertTrue($bad['is_error']);
            $this->assertStringContainsString('prcie', $bad['content']);

            $tool('propose_fields_update', ['id' => 12, 'fields' => ['price' => '2400000', 'rooms' => '4']]);

            return '';
        });

        $preview = $this->talk($subscriber, 'תוריד את המחיר של הדירה ל-2.4 מיליון');

        $this->assertStringContainsString('price: 2500000 ← 2400000', $preview);
        $this->assertStringContainsString('rooms: — ← 4', $preview);
    }

    public function test_a_menu_item_added_from_the_phone_comes_off_again_with_undo(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 30, 'title' => 'מבצעים']];
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => []]];

        $this->model(function (Closure $tool): string {
            $tool('find_content', ['search' => 'מבצעים']);
            $tool('propose_menu_item_add', ['menu' => 'ראשי', 'title' => 'מבצעים', 'page_id' => 30]);

            return '';
        });

        $this->assertStringContainsString('תפריט "ראשי"', $this->talk($subscriber, 'תוסיף לתפריט את עמוד המבצעים'));

        $this->site['wp_menu_item_add'] = ['added_item_id' => 88, 'menu_id' => 2];
        $this->talk($subscriber, 'כן');

        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => [['item_id' => 88, 'title' => 'מבצעים', 'url' => '/sale']]]];
        $this->calls = [];
        $this->talk($subscriber, 'בטל');

        $this->assertContains(['wp_menu_item_unlink', ['item_id' => 88]], $this->calls);
    }

    public function test_a_post_moved_to_the_trash_comes_back_out_with_undo(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 50, 'title' => 'פוסט ישן']];
        $this->site['wp_content_get'] = ['id' => 50, 'title' => 'פוסט ישן', 'status' => 'publish', 'content' => 'x'];

        $this->model(function (Closure $tool): string {
            $tool('find_content', ['type' => 'post']);
            $tool('propose_trash', ['id' => 50]);

            return '';
        });

        $this->assertStringContainsString('ייעלם מהאתר מיד', $this->talk($subscriber, 'תמחק את הפוסט הישן'));

        $this->site['wp_content_trash'] = ['trashed_id' => 50];
        $this->assertStringContainsString('בטל', $this->talk($subscriber, 'כן'));

        $this->site['wp_content_restore'] = ['restored_id' => 50, 'status' => 'publish'];
        $this->talk($subscriber, 'בטל');

        $this->assertContains(['wp_content_restore', ['id' => 50]], $this->calls);
    }

    public function test_an_older_plugin_is_not_promised_an_undo_it_cannot_do(): void
    {
        $subscriber = $this->subscriber(['tools' => [['name' => 'wp_content_list'], ['name' => 'wp_content_get'], ['name' => 'wp_content_trash']]]);
        $this->site['wp_content_list'] = [['id' => 50, 'title' => 'פוסט ישן']];
        $this->site['wp_content_get'] = ['id' => 50, 'title' => 'פוסט ישן', 'status' => 'publish', 'content' => 'x'];

        $this->model(function (Closure $tool): string {
            $tool('find_content', []);
            $tool('propose_trash', ['id' => 50]);

            return '';
        });

        $this->assertStringContainsString('בניהול האתר', $this->talk($subscriber, 'לפח'));

        $this->site['wp_content_trash'] = ['trashed_id' => 50];
        $this->assertStringNotContainsString('בטל', $this->talk($subscriber, 'כן'));
    }

    public function test_clearing_the_cache_asks_first_and_changes_no_content(): void
    {
        $subscriber = $this->subscriber();

        $this->model(function (Closure $tool): string {
            $tool('propose_cache_flush', []);

            return '';
        });

        $this->assertStringContainsString('לא משנה שום תוכן', $this->talk($subscriber, 'השינוי לא מופיע באתר'));
        $this->assertNotContains('wp_cache_flush', array_column($this->calls, 0));

        $this->talk($subscriber, 'כן');
        $this->assertContains(['wp_cache_flush', []], $this->calls);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function subscriber(array $capabilities = []): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/md-agent/v1/mcp', 'mcp_secret' => 's',
            'mcp_capabilities' => $capabilities ?: ['server' => ['version' => '1.8.1']],
        ]);

        return SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id, 'site_id' => $site->id, 'verified_at' => now(),
        ]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $text): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $text, 'wamid.'.md5($text.microtime()));
    }

    private function model(Closure $script): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn(null);
        $ai->shouldReceive('converse')->andReturnUsing(fn (string $s, string $p, array $t, callable $handler): ?string => $script(
            fn (string $name, array $input): array => $handler($name, $input) + ['is_error' => false],
        ));

        $this->app->instance(ClaudeClient::class, $ai);
    }

    private function fakeSite(): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments = []) {
            $this->calls[] = [$tool, $arguments];

            return $this->site[$tool] ?? [];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->app->instance(McpClient::class, $mcp);
    }
}
