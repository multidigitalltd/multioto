<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use App\Services\SiteAgent\SiteChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The agent against the shapes the bundled plugin actually sends.
 *
 * Every other test here mocks the MCP client, which means every other test is
 * agreeing with whatever contract this side of the code believes in. Three real
 * bugs lived in exactly that gap and all three were invisible: we read the page
 * list from an `items` wrapper the plugin never sends, and asked the shop to
 * search on a `query` key it rejects. Both failures were swallowed by a catch,
 * so the agent politely told every customer it could not understand them — with
 * a green suite the whole time.
 *
 * So the responses below are copied from the plugin's own handlers
 * (wordpress-plugin/multioto-agent/includes/class-mcp-server.php), and the
 * requests are asserted as the plugin's own argument checks read them. When the
 * plugin changes, this is what says so.
 */
class SiteAgentPluginContractTest extends TestCase
{
    use RefreshDatabase;

    private ?ResponseSequence $sequence = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['siteagent.enabled' => true, 'siteagent.confirmation_minutes' => 30]);
        Cache::flush();
        Storage::fake('local');
        $this->sequence = null;
    }

    public function test_the_page_list_is_read_as_the_plugin_sends_it(): void
    {
        // contentList() returns a BARE array — no wrapper of any kind.
        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 11, 'title' => 'צור קשר', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => false],
            ])),
            $this->tool(json_encode(['id' => 11, 'title' => 'צור קשר', 'content' => 'טלפון: 03-1234567', 'status' => 'publish', 'built_with_elementor' => false])),
        ]);

        $this->planning(['can_do' => true, 'operation' => 'replace_text', 'page_id' => 11,
            'find' => '03-1234567', 'text' => '03-7654321', 'summary' => 'עדכון טלפון']);

        $reply = $this->talk('תחליף את הטלפון');

        // Reading `items` found nothing on every real site, and the customer
        // was told the agent could not understand a perfectly clear request.
        $this->assertStringContainsString('03-7654321', $reply);
        $this->assertSame(11, (int) SiteAgentRequest::sole()->plan['page_id']);
    }

    public function test_the_shop_is_searched_on_the_key_the_plugin_requires(): void
    {
        // wcProductSearch() reads `search` and throws when it is missing.
        $this->siteSends([
            $this->tool(json_encode([
                'total' => 1, 'returned' => 1, 'page' => 1, 'pages' => 1,
                'products' => [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'sale_price' => '', 'stock_quantity' => 8, 'stock_status' => 'instock']],
            ])),
        ]);

        $this->planning(['can_do' => true, 'operation' => 'update_price',
            'product_query' => 'חולצה כחולה', 'regular_price' => '90', 'summary' => 'הורדת מחיר']);

        $reply = $this->talk('תוריד את החולצה הכחולה ל-90');

        $this->assertStringContainsString('120', $reply);
        $this->assertStringContainsString('90', $reply);

        // Sending `query` made the plugin throw on every call, which the catch
        // turned into "no such product" — an endless "which one did you mean?"
        Http::assertSent(function ($request): bool {
            $arguments = (array) data_get($request->data(), 'params.arguments', []);

            return data_get($request->data(), 'params.name') !== 'wc_product_search'
                || (($arguments['search'] ?? '') !== '' && ! array_key_exists('query', $arguments));
        });
    }

    public function test_an_elementor_page_is_edited_where_its_text_actually_lives(): void
    {
        // The plugin flags the page and says content is not what is displayed.
        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 12, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => true],
            ])),
            // Its visible text, from the builder.
            $this->tool(json_encode(['id' => 12, 'texts' => [
                ['widget_id' => 'a1b2c3d', 'type' => 'heading', 'setting' => 'title', 'text' => 'שעות: 08:00-16:00'],
                ['widget_id' => 'e4f5g6h', 'type' => 'text-editor', 'setting' => 'editor', 'text' => 'ברוכים הבאים'],
            ]])),
        ]);

        $this->planning(['can_do' => true, 'operation' => 'replace_text', 'page_id' => 12,
            'find' => '08:00-16:00', 'text' => '09:00-17:00', 'summary' => 'עדכון שעות']);

        $this->talk('תעדכן שעות');

        // Confirmation reads the page (is it still published?), then re-reads
        // the builder, then writes the ONE widget.
        $this->siteSends([
            $this->tool(json_encode(['id' => 12, 'title' => 'דף הבית', 'content' => '', 'status' => 'publish', 'built_with_elementor' => true])),
            $this->tool(json_encode(['id' => 12, 'texts' => [
                ['widget_id' => 'a1b2c3d', 'type' => 'heading', 'setting' => 'title', 'text' => 'שעות: 08:00-16:00'],
            ]])),
            $this->tool(json_encode(['updated_id' => 12, 'widget_id' => 'a1b2c3d', 'setting' => 'title', 'previous' => 'שעות: 08:00-16:00'])),
        ]);

        $this->assertStringContainsString('בוצע', $this->talk('כן'));

        // The `content` field of an Elementor page is a leftover nobody reads.
        // Writing there reports success and changes nothing the visitor sees —
        // the agent lying to the customer about their own website.
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'params.name') !== 'wp_content_update');
        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'params.name') !== 'wp_elementor_text_update'
                || data_get($request->data(), 'params.arguments.widget_id') === 'a1b2c3d';
        });

        $this->assertSame('elementor', SiteAgentRequest::sole()->restore['kind']);
    }

    public function test_an_elementor_page_cannot_be_appended_to_and_says_so(): void
    {
        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 12, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => true],
            ])),
            $this->tool(json_encode(['id' => 12, 'texts' => [
                ['widget_id' => 'a1b2c3d', 'setting' => 'title', 'text' => 'ברוכים הבאים'],
            ]])),
        ]);

        $this->planning(['can_do' => true, 'operation' => 'append_text', 'page_id' => 12,
            'text' => 'אנחנו פתוחים בשישי', 'summary' => 'הוספה']);

        $reply = $this->talk('תוסיף שאנחנו פתוחים בשישי');

        // Said plainly, with what IS possible — rather than appending to a
        // field the page does not render and calling it done.
        $this->assertStringContainsString('אלמנטור', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_the_displaced_image_is_read_from_the_shape_the_plugin_sends(): void
    {
        // The media stubs go up FIRST: Http::fake never replaces a stub, and a
        // catch-all sequence registered before them would answer Meta's media
        // URLs with a tool envelope.
        $this->imageArrives();

        $this->siteSends([
            // The page catalogue, then the page's own text.
            $this->tool(json_encode([
                ['id' => 11, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => false],
            ])),
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish'])),
        ]);

        $this->planning(['can_do' => true, 'target_id' => 11, 'alt' => 'כיכר לחם', 'summary' => 'תמונה']);

        $this->talk('תשים את זה בדף הבית — כיכר לחם', mediaId: 'media-1');

        $this->siteSends([
            $this->tool(json_encode(['id' => 77, 'url' => 'https://example.test/bread.png'])),
            // setThumbnail() answers with previous as an OBJECT.
            $this->tool(json_encode(['id' => 11, 'attachment_id' => 77, 'previous' => ['attachment_id' => 42]])),
        ]);

        $this->assertStringContainsString('בוצע', $this->talk('כן'));

        // Casting that object to int yields 1 — so the undo used to restore
        // attachment #1, a picture belonging to somebody else's upload or to
        // nothing at all. The real displaced image is 42.
        $restore = SiteAgentRequest::sole()->restore;
        $this->assertSame(42, $restore['attachment_id']);
        $this->assertSame(77, $restore['after']);
    }

    public function test_an_image_set_by_somebody_else_since_the_preview_is_not_replaced(): void
    {
        $this->imageArrives();

        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 11, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => false],
            ])),
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish'])),
            // The shop, searched with the caption in case they named a product.
            $this->tool(json_encode(['total' => 0, 'returned' => 0, 'page' => 1, 'pages' => 1, 'products' => []])),
            // The featured image at preview time — reported by the plugin from
            // 1.6.1, which is the only way to know before overwriting it.
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish', 'thumbnail_id' => 42])),
        ]);

        $this->planning(['can_do' => true, 'target_id' => 11, 'alt' => 'כיכר לחם', 'summary' => 'תמונה']);
        $this->talk('תשים את זה בדף הבית — כיכר לחם', mediaId: 'media-1');

        // An administrator put a different picture there while the offer waited.
        $this->siteSends([
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish', 'thumbnail_id' => 99])),
        ]);

        $reply = $this->talk('כן');

        $this->assertStringContainsString('השתנה', $reply);
        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        // And nothing was uploaded to their media library either.
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'params.name') !== 'wp_media_upload');
    }

    public function test_a_products_featured_image_is_read_through_the_shop_tool(): void
    {
        $this->imageArrives();

        $this->siteSends([
            // No pages at all; the target is a product.
            $this->tool(json_encode([])),
            $this->tool(json_encode([
                'total' => 1, 'returned' => 1, 'page' => 1, 'pages' => 1,
                'products' => [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'thumbnail_id' => 42]],
            ])),
            // Products are deliberately NOT readable through the content tool,
            // so this is the error a real site answers with.
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'סוג התוכן product אינו קיים באתר.']],
            // ...and the shop tool is where the answer actually lives.
            $this->tool(json_encode(['id' => 5, 'name' => 'חולצה כחולה', 'thumbnail_id' => 42])),
        ]);

        $this->planning(['can_do' => true, 'target_id' => 5, 'alt' => 'חולצה כחולה', 'summary' => 'תמונה']);
        $this->talk('חולצה כחולה', mediaId: 'media-1');

        // Without the shop fallback the check stood down on every product, and
        // an image an administrator set after the preview was overwritten in
        // silence — the one case the guard was added for.
        $this->assertSame(42, SiteAgentRequest::sole()->plan['thumbnail_id']);
    }

    public function test_a_product_image_still_applies_when_the_check_re_reads_it(): void
    {
        $this->imageArrives();

        $this->siteSends([
            $this->tool(json_encode([])),
            $this->tool(json_encode([
                'total' => 1, 'returned' => 1, 'page' => 1, 'pages' => 1,
                'products' => [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'thumbnail_id' => 42]],
            ])),
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'סוג התוכן product אינו קיים באתר.']],
            $this->tool(json_encode(['id' => 5, 'name' => 'חולצה כחולה', 'thumbnail_id' => 42])),
        ]);

        $this->planning(['can_do' => true, 'target_id' => 5, 'alt' => 'חולצה כחולה', 'summary' => 'תמונה']);
        $this->talk('חולצה כחולה', mediaId: 'media-1');

        // Carrying it out re-reads the featured image, and has to re-read it
        // THE SAME WAY. Asking only the content tool throws on every product,
        // the throw is caught as a failure, and the customer who said yes is
        // told the change did not work — an image that could never be set on
        // any product, on a plugin built to allow exactly that.
        $this->siteSends([
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'סוג התוכן product אינו קיים באתר.']],
            $this->tool(json_encode(['id' => 5, 'name' => 'חולצה כחולה', 'thumbnail_id' => 42])),
            $this->tool(json_encode(['id' => 77, 'url' => 'https://example.test/shirt.png'])),
            $this->tool(json_encode(['id' => 5, 'attachment_id' => 77, 'previous' => ['attachment_id' => 42]])),
        ]);

        $this->assertStringContainsString('בוצע', $this->talk('כן'));
        $this->assertSame(SiteAgentRequest::APPLIED, SiteAgentRequest::sole()->state);
        $this->assertSame(42, SiteAgentRequest::sole()->restore['attachment_id']);
    }

    public function test_an_unreadable_featured_image_stops_the_upload_rather_than_guessing(): void
    {
        $this->imageArrives();

        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 11, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => false],
            ])),
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish'])),
            $this->tool(json_encode(['total' => 0, 'returned' => 0, 'page' => 1, 'pages' => 1, 'products' => []])),
            // The site answered at preview time, so it DOES report the image.
            $this->tool(json_encode(['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish', 'thumbnail_id' => 42])),
        ]);

        $this->planning(['can_do' => true, 'target_id' => 11, 'alt' => 'כיכר לחם', 'summary' => 'תמונה']);
        $this->talk('תשים את זה בדף הבית — כיכר לחם', mediaId: 'media-1');

        // ...and at confirmation time it answers nothing: the read failed.
        // Reading that as "a plugin too old to answer" would stand the guard
        // down at the only moment it is needed, and the upload right after it
        // may reach a site that recovered a second later — overwriting whatever
        // an administrator put there, in silence.
        // Both reads time out, and then the connection RECOVERS: the upload and
        // the thumbnail set that follow would go through perfectly. That is the
        // whole danger — standing down leaves a working write with no check in
        // front of it.
        $this->siteSends([
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'timeout']],
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'timeout']],
            $this->tool(json_encode(['id' => 77, 'url' => 'https://example.test/bread.png'])),
            $this->tool(json_encode(['id' => 11, 'attachment_id' => 77, 'previous' => ['attachment_id' => 99]])),
        ]);

        $reply = $this->talk('כן');

        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'params.name') !== 'wp_media_upload');
        // The customer is told it did not work — never why, in the site's
        // words — and the sentence they read is one sentence, not the lead-in
        // twice over.
        $this->assertStringNotContainsString('timeout', $reply);
        $this->assertSame(
            'לא הצלחתי לבצע את השינוי: משהו השתבש מול האתר. נסו שוב, ואם זה חוזר — נשמח לעזור.',
            $reply,
        );
    }

    public function test_an_elementor_undo_matches_the_setting_and_not_only_the_widget(): void
    {
        $widget = [
            ['widget_id' => 'a1b2c3d', 'type' => 'icon-box', 'setting' => 'title_text', 'text' => 'כותרת'],
            ['widget_id' => 'a1b2c3d', 'type' => 'icon-box', 'setting' => 'description_text', 'text' => 'שעות: 08:00-16:00'],
        ];

        $this->siteSends([
            $this->tool(json_encode([
                ['id' => 12, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'built_with_elementor' => true],
            ])),
            $this->tool(json_encode(['id' => 12, 'texts' => $widget])),
        ]);

        $this->planning(['can_do' => true, 'operation' => 'replace_text', 'page_id' => 12,
            'find' => '08:00-16:00', 'text' => '09:00-17:00', 'summary' => 'עדכון שעות']);

        $this->talk('תעדכן שעות');

        $this->siteSends([
            $this->tool(json_encode(['id' => 12, 'title' => 'דף הבית', 'content' => '', 'status' => 'publish', 'built_with_elementor' => true])),
            $this->tool(json_encode(['id' => 12, 'texts' => $widget])),
            $this->tool(json_encode(['updated_id' => 12, 'widget_id' => 'a1b2c3d', 'setting' => 'description_text', 'previous' => 'שעות: 08:00-16:00'])),
        ]);

        $this->talk('כן');

        // One widget, two editable settings. Looking it up by id alone finds
        // the TITLE, compares it against the description we changed, and calls
        // a perfectly safe undo stale.
        $changed = $widget;
        $changed[1]['text'] = 'שעות: 09:00-17:00';

        $this->siteSends([
            $this->tool(json_encode(['id' => 12, 'texts' => $changed])),
            $this->tool(json_encode(['updated_id' => 12, 'widget_id' => 'a1b2c3d', 'setting' => 'description_text', 'previous' => 'שעות: 09:00-17:00'])),
        ]);

        $this->assertStringContainsString('הוחזר', $this->talk('בטל'));
        $this->assertSame(SiteAgentRequest::REVERTED, SiteAgentRequest::sole()->state);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Meta serves the picture: an id resolves to a URL, the URL to bytes. */
    private function imageArrives(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-1']),
            'lookaside.fbsbx.com/*' => Http::response(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            )),
        ]);

        config([
            'siteagent.whatsapp.phone_number_id' => '123456',
            'siteagent.whatsapp.token' => 'permanent-token',
            'siteagent.media.max_megabytes' => 8,
        ]);
    }

    private function talk(string $text, ?string $mediaId = null): string
    {
        return app(SiteAgentConversation::class)->handle(
            $this->subscriber(),
            $text,
            'wamid.'.md5($text.microtime()),
            $mediaId,
        );
    }

    private ?SiteAgentSubscriber $subscriber = null;

    private function subscriber(): SiteAgentSubscriber
    {
        if ($this->subscriber !== null) {
            return $this->subscriber;
        }

        $site = $this->site();

        return $this->subscriber = SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ]);
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ]);
    }

    /** The model's answer; the site is left to the real MCP client over HTTP. */
    private function planning(?array $answer): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn($answer);
        $this->app->instance(ClaudeClient::class, $ai);

        foreach ([SiteChangePlanner::class, SiteChangeApplier::class, SiteAgentConversation::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    /** Responses in the plugin's own shape, appended to one sequence. */
    private function siteSends(array $responses): void
    {
        // Scoped to the site's own host. A catch-all sequence would also
        // answer Meta's media URLs and run dry before the site was asked
        // anything — the test would then be exercising the failure path.
        $this->sequence ??= Http::fakeSequence('example.test/*');

        foreach ($responses as $response) {
            $this->sequence->push($response);
        }
    }

    /** @return array<string, mixed> */
    private function tool(string $text): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]];
    }
}
