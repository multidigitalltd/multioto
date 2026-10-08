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
use Illuminate\Support\Facades\Http;
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
        // Raw configuration values.
        'wp_option_get' => 'configuration',
        // Updates need a verified file and database restoration path.
        'wp_core_update' => 'core', 'wp_core_rollback' => 'core',
        'wp_plugin_update' => 'no verified restoration', 'wp_theme_update' => 'no verified restoration',
        'wp_media_delete' => 'permanent deletion',
        // Code on the server — never from a phone.
        'wp_file_list' => 'code', 'wp_file_get' => 'code', 'wp_file_put' => 'code',
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
    private const THROUGH_UNDO = ['wp_content_restore', 'wc_product_restore', 'wp_menu_item_unlink'];

    private array $calls = [];

    private bool $siteUp = true;

    private array $site = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['siteagent.enabled' => true, 'siteagent.assistant.enabled' => true]);
        Cache::flush();
        $this->fakeSite();

        // The homepage answers unless a test takes it down.
        Http::fake(fn () => Http::response($this->siteUp ? 'ok' : 'error', $this->siteUp ? 200 : 500));
    }

    public function test_every_plugin_capability_is_reachable_or_deliberately_excluded(): void
    {
        // The catalog includes provider definitions as well as the original
        // inline entries; JetEngine tools are first-class inventory members.
        $source = '';
        foreach (['mcp-server', 'cct', 'media-management', 'content-management', 'site-administration', 'acf-schema', 'acf-management'] as $provider) {
            $source .= file_get_contents(base_path('wordpress-plugin/multioto-agent/includes/class-'.$provider.'.php'));
        }
        preg_match_all("/(?:\\['name' =>\\s*|self::definition\\(\\s*|\\[\\s*)'((?:wp|wc|wcs|jet)_[a-z_]+)'\\s*,/", $source, $matches);
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

        // The site as it reads once the item is in — recorded for the undo.
        $this->site['wp_menu_item_add'] = ['added_item_id' => 88, 'menu_id' => 2];
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => [['item_id' => 88, 'title' => 'מבצעים', 'url' => '/sale']]]];
        $this->talk($subscriber, 'כן');
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

    public function test_an_older_comment_is_looked_up_by_its_id(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_comment_list'] = ['comments' => [['id' => 7, 'author' => 'דנה', 'post_title' => 'ישן', 'status' => 'hold', 'text' => 'שאלה']]];

        $this->model(function (Closure $tool): string {
            $tool('find_comments', ['search' => 'שאלה']);
            $tool('propose_comment_moderation', ['comment_id' => 7, 'status' => 'approve']);

            return '';
        });

        $this->talk($subscriber, 'תאשר');

        $this->assertContains(['wp_comment_list', ['status' => 'all', 'id' => 7, 'limit' => 50]], $this->calls);
        $this->assertSame(1, SiteAgentRequest::count());
    }

    public function test_a_field_holding_a_list_is_not_flattened_by_an_update(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 12, 'title' => 'דירה']];
        $this->site['wp_content_get'] = ['id' => 12, 'title' => 'דירה', 'type' => 'property', 'fields' => ['features' => ['מעלית', 'חניה']]];

        $this->model(function (Closure $tool): string {
            $tool('find_content', []);
            $result = $tool('propose_fields_update', ['id' => 12, 'fields' => ['features' => 'מעלית']]);
            $this->assertTrue($result['is_error']);
            $this->assertStringContainsString('ערך מורכב', $result['content']);

            return '';
        });

        $this->talk($subscriber, 'תעדכן מאפיינים');
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_menu_removal_is_refused_before_a_confirmation_can_be_created(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => [
            ['item_id' => 5, 'title' => 'ישן', 'url' => '/a', 'parent_id' => 0, 'order' => 1],
        ]]];

        $this->model(function (Closure $tool): string {
            $tool('list_menus', []);
            $result = $tool('propose_menu_item_remove', ['item_id' => 5]);
            $this->assertTrue($result['is_error']);

            return '';
        });
        $this->talk($subscriber, 'תסיר את הפריט מהתפריט');
        $this->talk($subscriber, 'כן');

        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertNotContains('wp_menu_item_unlink', array_column($this->calls, 0));
    }

    public function test_an_added_menu_item_somebody_renamed_is_not_undone(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_content_list'] = [['id' => 30, 'title' => 'מבצעים']];
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => []]];

        $this->model(function (Closure $tool): string {
            $tool('find_content', []);
            $tool('propose_menu_item_add', ['menu' => 'ראשי', 'title' => 'מבצעים', 'page_id' => 30]);

            return '';
        });
        $this->talk($subscriber, 'תוסיף');

        $this->site['wp_menu_item_add'] = ['added_item_id' => 88];
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => [['item_id' => 88, 'title' => 'מבצעים', 'url' => '/sale']]]];
        $this->talk($subscriber, 'כן');

        // Renamed in wp-admin before the undo.
        $this->site['wp_menu_list'] = [['menu' => 'ראשי', 'menu_id' => 2, 'items' => [['item_id' => 88, 'title' => 'מבצעי חג', 'url' => '/sale']]]];
        $this->calls = [];

        $this->assertStringContainsString('לא החזרתי', $this->talk($subscriber, 'בטל'));
        $this->assertNotContains('wp_menu_item_unlink', array_column($this->calls, 0));
    }

    public function test_a_coupon_extended_since_the_preview_is_not_ended(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wc_coupon_list'] = [['code' => 'sale10', 'expires' => '2026-10-31', 'usage_count' => 3]];

        $this->model(function (Closure $tool): string {
            $tool('propose_coupon_expire', ['code' => 'SALE10']);

            return '';
        });
        $this->talk($subscriber, 'תסיים את הקופון');

        $this->site['wc_coupon_list'] = [['code' => 'sale10', 'expires' => '2026-12-31', 'usage_count' => 3]];
        $this->talk($subscriber, 'כן');

        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        $this->assertNotContains('wc_coupon_expire', array_column($this->calls, 0));
    }

    public function test_plugin_updates_are_refused_without_a_verified_restoration_path(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_plugin_list'] = [
            ['plugin' => 'forms/forms.php', 'name' => 'Forms', 'version' => '1.0', 'active' => true, 'update_available' => true],
        ];

        $this->model(function (Closure $tool): string {
            $result = $tool('propose_plugin_update', ['plugins' => ['all']]);
            $this->assertTrue($result['is_error']);

            return '';
        });
        $this->talk($subscriber, 'תעדכן את כל התוספים');
        $this->talk($subscriber, 'כן');

        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertNotContains('wp_plugin_update', array_column($this->calls, 0));
    }

    public function test_theme_updates_are_refused_without_a_verified_restoration_path(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_theme_list'] = [
            ['stylesheet' => 'theme', 'name' => 'Theme', 'version' => '1.0', 'active' => true, 'update_available' => true],
        ];

        $this->model(function (Closure $tool): string {
            $result = $tool('propose_theme_update', ['stylesheet' => 'theme']);
            $this->assertTrue($result['is_error']);

            return '';
        });
        $this->talk($subscriber, 'תעדכן את התבנית');
        $this->talk($subscriber, 'כן');

        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertNotContains('wp_theme_update', array_column($this->calls, 0));
    }

    public function test_switching_off_a_plugin_that_breaks_the_site_is_put_back_at_once(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_plugin_list'] = [['plugin' => 'slider/slider.php', 'name' => 'Slider', 'active' => true, 'update_available' => false]];

        $this->model(function (Closure $tool): string {
            $tool('propose_plugin_toggle', ['plugin' => 'Slider', 'active' => false]);

            return '';
        });
        $this->assertStringContainsString('יפסיק לעבוד', $this->talk($subscriber, 'תכבה את הסליידר'));

        $this->siteUp = false;
        $reply = $this->talk($subscriber, 'כן');

        $this->assertStringContainsString('החזרתי אותו מיד', $reply);
        $this->assertContains(['wp_plugin_deactivate', ['plugin' => 'slider/slider.php']], $this->calls);
        $this->assertContains(['wp_plugin_activate', ['plugin' => 'slider/slider.php']], $this->calls);
    }

    public function test_the_shop_and_security_plugins_are_never_switched_off_from_a_phone(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_plugin_list'] = [
            ['plugin' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'active' => true],
            ['plugin' => 'wordfence/wordfence.php', 'name' => 'Wordfence Security', 'active' => true],
        ];

        $this->model(function (Closure $tool): string {
            foreach (['WooCommerce', 'Wordfence Security'] as $plugin) {
                $result = $tool('propose_plugin_toggle', ['plugin' => $plugin, 'active' => false]);
                $this->assertTrue($result['is_error'], $plugin);
            }

            return '';
        });

        $this->talk($subscriber, 'תכבה');
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_a_media_file_is_never_permanently_deleted_from_the_bot(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_media_list'] = ['items' => [['id' => 61, 'title' => 'banner-old', 'url' => 'https://example.test/banner-old.jpg']]];

        $this->model(function (Closure $tool): string {
            $tool('find_media', ['search' => 'banner']);
            $result = $tool('propose_media_delete', ['attachment_id' => 61]);
            $this->assertTrue($result['is_error']);

            return '';
        });
        $this->talk($subscriber, 'תמחק את הבאנר הישן');
        $this->talk($subscriber, 'כן');

        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertNotContains('wp_media_delete', array_column($this->calls, 0));
    }

    public function test_the_error_log_reaches_the_model_without_the_servers_internals(): void
    {
        $subscriber = $this->subscriber();
        $this->site['wp_error_log_tail'] = 'PHP Fatal error in /home/u1/public_html/wp-content/plugins/forms/inc/a.php on line 9 from 10.1.2.3 DB_PASSWORD=hunter2';

        $this->model(function (Closure $tool): string {
            $log = $tool('site_errors', [])['content'];

            $this->assertStringContainsString('plugins/forms/a.php', $log);
            $this->assertStringNotContainsString('/home/u1', $log);
            $this->assertStringNotContainsString('10.1.2.3', $log);
            $this->assertStringNotContainsString('hunter2', $log);

            return 'תוסף הטפסים מתקלקל.';
        });

        $this->talk($subscriber, 'למה הטופס לא עובד?');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function subscriber(array $capabilities = []): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/md-agent/v1/mcp', 'mcp_secret' => 's',
            'mcp_capabilities' => $capabilities ?: ['server' => ['version' => '1.8.5']],
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

            $answer = $this->site[$tool] ?? [];
            $answer = $answer instanceof Closure ? $answer($arguments) : $answer;

            // A plain-text answer travels the way the plugin sends one.
            return is_string($answer) ? ['content' => [['type' => 'text', 'text' => $answer]], '_text' => true] : $answer;
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => ($result['_text'] ?? false) === true
            ? (string) $result['content'][0]['text']
            : json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->app->instance(McpClient::class, $mcp);
    }
}
