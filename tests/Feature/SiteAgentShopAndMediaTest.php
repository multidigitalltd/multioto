<?php

namespace Tests\Feature;

use App\Jobs\PruneSiteAgentRequestsJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\ImageChangePlanner;
use App\Services\SiteAgent\ProductChangePlanner;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteChangeApplier;
use App\Services\SiteAgent\SiteChangePlanner;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Prices, stock, and pictures.
 *
 * These are the operations where being almost right is expensive: a price is
 * money out of somebody's till until they notice, and a picture published with
 * no description quietly makes their site less accessible than it was. Both are
 * guarded by refusing rather than guessing.
 */
class SiteAgentShopAndMediaTest extends TestCase
{
    use RefreshDatabase;

    private ?ResponseSequence $sequence = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.phone_number_id' => '123456',
            'siteagent.whatsapp.token' => 'permanent-token',
            'siteagent.media.max_megabytes' => 8,
        ]);

        Cache::flush();
        Storage::fake('local');
        $this->sequence = null;
    }

    public function test_a_price_change_is_previewed_with_the_price_it_replaces(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(['can_do' => true, 'operation' => 'update_price',
            'product_query' => 'חולצה כחולה', 'regular_price' => '90', 'summary' => 'הורדת מחיר']);

        $reply = $this->talk($subscriber, 'תוריד את החולצה הכחולה ל-90');

        // The old price is quoted too: "עדכון מחיר ל-90" gives the owner no way
        // to notice they are about to halve something by accident.
        $this->assertStringContainsString('120', $reply);
        $this->assertStringContainsString('90', $reply);
        $this->assertSame(SiteAgentRequest::OP_PRICE, SiteAgentRequest::sole()->operation);
    }

    public function test_a_price_that_is_not_a_number_is_refused(): void
    {
        $subscriber = $this->subscriber();
        // Coercing "בערך 90" into 90 would be the agent deciding what a
        // business charges.
        $this->shopAnswers(['can_do' => true, 'operation' => 'update_price',
            'product_query' => 'חולצה כחולה', 'regular_price' => 'בערך 90']);

        $reply = $this->talk($subscriber, 'תוריד את החולצה לבערך 90');

        $this->assertStringContainsString('לא נגעתי בכלום', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_several_matching_products_become_a_question_naming_them(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_price', 'product_query' => 'חולצה', 'regular_price' => '90'],
            products: [
                ['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120'],
                ['id' => 6, 'name' => 'חולצה אדומה', 'regular_price' => '110'],
            ],
        );

        $reply = $this->talk($subscriber, 'תוריד את החולצה ל-90');

        // "איזה מהם?" with no list is a question the customer cannot answer
        // without opening their own shop.
        $this->assertStringContainsString('חולצה כחולה', $reply);
        $this->assertStringContainsString('חולצה אדומה', $reply);

        // The question is held with their own words, because the price they
        // asked for is in THIS message and not in the name they answer with.
        // Nothing to confirm yet, so no preview.
        $held = SiteAgentRequest::sole();
        $this->assertNull($held->preview);
        $this->assertSame('תוריד את החולצה ל-90', data_get($held->plan, 'text'));
    }

    public function test_the_answer_to_which_product_completes_the_price_change(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_price', 'product_query' => 'חולצה', 'regular_price' => '90'],
            products: [
                ['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120'],
                ['id' => 6, 'name' => 'חולצה אדומה', 'regular_price' => '110'],
            ],
        );

        $this->talk($subscriber, 'תוריד את החולצה ל-90');

        // They answer with the name only. The 90 was in the message before it.
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_price', 'product_query' => 'חולצה כחולה', 'regular_price' => '90'],
            products: [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'sale_price' => '']],
        );

        $reply = $this->talk($subscriber, 'חולצה כחולה');

        $this->assertStringContainsString('90', $reply);
        $this->assertStringContainsString('חולצה כחולה', $reply);

        // The same row, completed — the price survived the question.
        $request = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::OP_PRICE, $request->operation);
        $this->assertSame('90', data_get($request->plan, 'fields.regular_price'));
        $this->assertSame(5, (int) data_get($request->plan, 'product_id'));
    }

    public function test_a_product_that_does_not_exist_is_said_plainly(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_price', 'product_query' => 'מכנסיים', 'regular_price' => '90'],
            products: [],
        );

        $reply = $this->talk($subscriber, 'תוריד את המכנסיים ל-90');

        $this->assertStringContainsString('לא מצאתי', $reply);

        // Held the same way: they may answer with the right name, and the
        // price they wanted was in the message that asked.
        $this->assertNull(SiteAgentRequest::sole()->preview);
    }

    public function test_ending_a_sale_is_an_empty_sale_price_and_says_so(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(['can_do' => true, 'operation' => 'update_price',
            'product_query' => 'חולצה כחולה', 'sale_price' => '', 'summary' => 'סיום מבצע']);

        $reply = $this->talk($subscriber, 'תסיים את המבצע על החולצה הכחולה');

        $this->assertStringContainsString('סיום המבצע', $reply);
        $this->assertSame('', SiteAgentRequest::sole()->plan['fields']['sale_price']);
    }

    public function test_the_shops_own_previous_values_are_what_the_undo_keeps(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(['can_do' => true, 'operation' => 'update_price',
            'product_query' => 'חולצה כחולה', 'regular_price' => '90', 'summary' => 'הורדת מחיר']);
        $this->talk($subscriber, 'תוריד ל-90');

        // WooCommerce hands back what the product was. Between our read and the
        // write a sale could have started, so what we saw earlier is not what
        // to put back.
        $this->siteReturns([
            // Checked before writing: the price is still the 120 the customer
            // was shown, so nobody repriced while the offer waited.
            $this->tool(json_encode(['id' => 5, 'regular_price' => '120', 'sale_price' => ''])),
            $this->tool(json_encode(['previous' => ['regular_price' => '135', 'sale_price' => '99']])),
            // And the product read back, so the undo has something to compare
            // the shop against later.
            $this->tool(json_encode(['id' => 5, 'regular_price' => '90.00', 'sale_price' => ''])),
        ]);

        $this->talk($subscriber, 'כן');

        $restore = SiteAgentRequest::sole()->restore;
        $this->assertSame('135', $restore['fields']['regular_price']);
        // Only the fields we changed are put back — not the whole product.
        $this->assertArrayNotHasKey('sale_price', $restore['fields']);
        // And what the change left behind, read from the shop, so the undo can
        // tell whether anything has happened to the product since.
        $this->assertSame('90.00', $restore['after']['regular_price']);
    }

    public function test_a_product_undo_refuses_after_the_shop_moved(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_stock',
                'product_query' => 'חולצה כחולה', 'stock_quantity' => 40, 'summary' => 'עדכון מלאי'],
            products: [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'stock_quantity' => 12]],
        );
        $this->talk($subscriber, 'תעדכן מלאי ל-40');

        $this->siteReturns([
            // Still the 12 they were shown — nothing sold while they decided.
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 12])),
            $this->tool(json_encode(['previous' => ['stock_quantity' => 12]])),
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 40])),
        ]);
        $this->talk($subscriber, 'כן');

        // Two were sold since. Restoring the quantity from before our change
        // would hand back stock the shop no longer has.
        $this->siteReturns([$this->tool(json_encode(['id' => 5, 'stock_quantity' => 38]))]);

        $before = Http::recorded()->count();
        $reply = $this->talk($subscriber, 'בטל');

        $this->assertStringContainsString('המוצר השתנה', $reply);
        $this->assertSame(SiteAgentRequest::APPLIED, SiteAgentRequest::sole()->state);
        // Reading the product is the only thing the undo did — nothing was
        // written back over the sale.
        $this->assertSame($before + 1, Http::recorded()->count());
    }

    public function test_the_undo_puts_back_what_woocommerce_changed_by_itself(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_price',
                'product_query' => 'חולצה כחולה', 'sale_price' => '', 'summary' => 'סיום מבצע'],
            products: [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'sale_price' => '99']],
        );
        $this->talk($subscriber, 'תסיים את המבצע');

        $this->siteReturns([
            $this->tool(json_encode(['id' => 5, 'sale_price' => '99'])),
            // Ending a sale also clears the window it was scheduled for — the
            // shop does that on its own, and the undo has to know.
            $this->tool(json_encode(['previous' => [
                'sale_price' => '99', 'sale_from' => '2026-09-01', 'sale_to' => '2026-09-30',
                'regular_price' => '120',
            ]])),
            $this->tool(json_encode(['id' => 5, 'sale_price' => '', 'sale_from' => null, 'sale_to' => null])),
        ]);

        $this->talk($subscriber, 'כן');

        $restore = SiteAgentRequest::sole()->restore;

        // Restoring only sale_price would put the discount back with no end
        // date — a sale that was meant to stop running for ever, reported as a
        // successful undo.
        $this->assertSame('99', $restore['fields']['sale_price']);
        $this->assertSame('2026-09-01', $restore['fields']['sale_from']);
        $this->assertSame('2026-09-30', $restore['fields']['sale_to']);
    }

    public function test_a_stock_change_remembers_that_the_product_did_not_manage_stock(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_stock',
                'product_query' => 'חולצה כחולה', 'stock_quantity' => 40, 'summary' => 'עדכון מלאי'],
            products: [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'stock_quantity' => null]],
        );
        $this->talk($subscriber, 'תעדכן מלאי ל-40');

        $this->siteReturns([
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => null])),
            $this->tool(json_encode(['previous' => [
                'stock_quantity' => null, 'manage_stock' => false, 'stock_status' => 'instock',
            ]])),
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 40, 'manage_stock' => true, 'stock_status' => 'instock'])),
        ]);

        $this->talk($subscriber, 'כן');

        // Setting a quantity switches stock management ON. Without capturing
        // that, the undo leaves the product managing stock it never managed.
        $this->assertArrayHasKey('manage_stock', SiteAgentRequest::sole()->restore['fields']);
        $this->assertFalse(SiteAgentRequest::sole()->restore['fields']['manage_stock']);
    }

    public function test_a_product_undo_goes_through_when_nothing_moved(): void
    {
        $subscriber = $this->subscriber();
        $this->shopAnswers(
            ['can_do' => true, 'operation' => 'update_stock',
                'product_query' => 'חולצה כחולה', 'stock_quantity' => 40, 'summary' => 'עדכון מלאי'],
            products: [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'stock_quantity' => 12]],
        );
        $this->talk($subscriber, 'תעדכן מלאי ל-40');

        $this->siteReturns([
            // Still the 12 they were shown — nothing sold while they decided.
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 12])),
            $this->tool(json_encode(['previous' => ['stock_quantity' => 12]])),
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 40])),
        ]);
        $this->talk($subscriber, 'כן');

        $this->siteReturns([
            $this->tool(json_encode(['id' => 5, 'stock_quantity' => 40])),
            $this->tool('{"ok":true}'),
        ]);

        $this->assertStringContainsString('הוחזר', $this->talk($subscriber, 'בטל'));
        $this->assertSame(SiteAgentRequest::REVERTED, SiteAgentRequest::sole()->state);

        // Put back to what it was before the agent touched it.
        Http::assertSent(fn ($request): bool => (int) data_get($request->data(), 'params.arguments.stock_quantity') === 12);
    }

    public function test_an_image_without_a_description_is_not_published(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => false, 'needs' => 'alt']);

        $reply = $this->talk($subscriber, 'תשים את זה בדף הבית', mediaId: 'media-1');

        // An empty alt attribute is a site made less accessible, one image at a
        // time — and the plugin refuses it anyway.
        $this->assertStringContainsString('לתאר את התמונה', $reply);

        // The picture is kept while the question is open, so the answer can
        // complete it. A question is not an offer, so there is no preview and
        // nothing a "כן" could confirm.
        $held = SiteAgentRequest::sole();
        $this->assertNull($held->preview);
        $this->assertSame('תשים את זה בדף הבית', data_get($held->plan, 'caption'));
        Storage::disk('local')->assertExists((string) data_get($held->plan, 'image_path'));
    }

    public function test_the_answer_to_a_question_completes_the_image_already_sent(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => false, 'needs' => 'alt']);

        $this->talk($subscriber, 'תשים את זה בדף הבית', mediaId: 'media-1');

        // They answer with the description. The photograph is already here —
        // asking them to send it again is the product wasting their time.
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'כיכר לחם על שולחן עץ', 'summary' => 'תמונה']);

        $reply = $this->talk($subscriber, 'כיכר לחם על שולחן עץ');

        $this->assertStringContainsString('כיכר לחם על שולחן עץ', $reply);
        $this->assertStringContainsString('כן', $reply);

        // The same row, completed — not a second one, and not a lost picture.
        $request = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
        $this->assertNotNull($request->preview);
        Storage::disk('local')->assertExists((string) data_get($request->plan, 'image_path'));

        // Both halves of what they said, kept together: the page came from the
        // first message and the description from the second.
        $this->assertStringContainsString('דף הבית', (string) $request->message);
        $this->assertStringContainsString('כיכר לחם', (string) $request->message);
    }

    public function test_saying_no_to_a_question_about_an_image_deletes_it(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => false, 'needs' => 'alt']);

        $this->talk($subscriber, 'תשים את זה בדף הבית', mediaId: 'media-1');
        $path = (string) data_get(SiteAgentRequest::sole()->plan, 'image_path');

        $this->talk($subscriber, 'לא');

        // Held only while the question is open. A customer who dropped the idea
        // has not agreed to us keeping their photograph.
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(SiteAgentRequest::CANCELED, SiteAgentRequest::sole()->state);
    }

    public function test_an_image_with_a_description_is_previewed_with_it(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'כיכר לחם על שולחן עץ', 'summary' => 'תמונה']);

        $reply = $this->talk($subscriber, 'תשים את זה בדף הבית — כיכר לחם', mediaId: 'media-1');

        $this->assertStringContainsString('כיכר לחם על שולחן עץ', $reply);
        $this->assertSame(SiteAgentRequest::OP_IMAGE, SiteAgentRequest::sole()->operation);
    }

    public function test_the_image_waits_on_disk_and_not_in_the_database_row(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'לחם', 'summary' => 'תמונה']);

        $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        $plan = SiteAgentRequest::sole()->plan;
        $this->assertArrayNotHasKey('bytes', $plan);
        Storage::disk('local')->assertExists($plan['image_path']);
    }

    public function test_a_file_that_is_not_an_image_never_reaches_the_site(): void
    {
        $subscriber = $this->subscriber();
        // Named like a picture, and actually a PHP script.
        $this->imageArrives(bytes: '<?php system($_GET[\'c\']); ?>');

        $reply = $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        // The type is read from the CONTENT. What the sender called it is a
        // claim, and this one is a claim with a shell in it.
        $this->assertStringContainsString('לא הצלחתי לקרוא את התמונה', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_an_oversized_image_is_refused_before_it_reaches_the_site(): void
    {
        $subscriber = $this->subscriber();
        config(['siteagent.media.max_megabytes' => 1]);
        $this->imageArrives(bytes: $this->png().str_repeat('A', 2 * 1024 * 1024));

        $reply = $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        // A page that takes eight seconds to load because somebody sent a photo
        // straight off a phone is a site the agent made worse.
        $this->assertStringContainsString('לא הצלחתי לקרוא את התמונה', $reply);
    }

    public function test_media_is_only_ever_fetched_from_metas_own_hosts(): void
    {
        $subscriber = $this->subscriber();

        // The download URL arrives inside a response. Following wherever a
        // response points is a request forger waiting for one bad day.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['url' => 'https://attacker.example/internal']),
            '*' => Http::response('should never be fetched'),
        ]);

        $reply = $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        $this->assertStringContainsString('לא הצלחתי לקרוא את התמונה', $reply);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'attacker.example'));
    }

    public function test_an_unanswered_image_offer_does_not_keep_the_customers_photo(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'לחם', 'summary' => 'תמונה']);
        $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        $path = SiteAgentRequest::sole()->plan['image_path'];

        $this->travel(2)->hours();
        (new PruneSiteAgentRequestsJob)->handle();

        // A customer's photograph, held for a change they never agreed to.
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(SiteAgentRequest::EXPIRED, SiteAgentRequest::sole()->state);
    }

    public function test_an_image_change_that_failed_does_not_keep_the_photo_either(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'לחם', 'summary' => 'תמונה']);
        $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        $path = SiteAgentRequest::sole()->plan['image_path'];

        // The upload does not go through. The change is over either way.
        $this->siteReturns([['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'upload failed']]]);

        $this->talk($subscriber, 'כן');

        // The pruning job only ever visits offers still awaiting an answer, so
        // without this the photograph would sit on our disk forever — for a
        // change that never happened.
        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_saying_no_to_an_image_deletes_it_too(): void
    {
        $subscriber = $this->subscriber();
        $this->imageArrives();
        $this->aiAnswers(['can_do' => true, 'target_id' => 11, 'alt' => 'לחם', 'summary' => 'תמונה']);
        $this->talk($subscriber, 'תשים בדף הבית', mediaId: 'media-1');

        $path = SiteAgentRequest::sole()->plan['image_path'];

        $this->talk($subscriber, 'לא');

        Storage::disk('local')->assertMissing($path);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ]);

        return SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $text, ?string $mediaId = null): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $text, 'wamid.'.md5($text.microtime()), $mediaId);
    }

    /** What the model answers, and what the shop contains. */
    private function shopAnswers(array $intent, ?array $products = null): void
    {
        $this->aiAnswers($intent);

        $products ??= [['id' => 5, 'name' => 'חולצה כחולה', 'regular_price' => '120', 'sale_price' => '']];

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool) => match ($tool) {
            'wc_product_search' => ['products' => $products],
            'wp_content_list' => ['items' => [['id' => 11, 'title' => 'דף הבית']]],
            'wp_content_get' => ['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish'],
            default => [],
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($r): string => json_encode($r));
        $this->app->instance(McpClient::class, $mcp);
        $this->forgetServices();
    }

    private function aiAnswers(?array $answer): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn($answer);
        $this->app->instance(ClaudeClient::class, $ai);
        $this->forgetServices();
    }

    /** Meta serves an image: an id resolves to a URL, the URL to bytes. */
    private function imageArrives(?string $bytes = null): void
    {
        Http::fake([
            'graph.facebook.com/*/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-1']),
            'lookaside.fbsbx.com/*' => Http::response($bytes ?? $this->png()),
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool) => match ($tool) {
            'wp_content_list' => ['items' => [['id' => 11, 'title' => 'דף הבית']]],
            'wp_content_get' => ['id' => 11, 'title' => 'דף הבית', 'content' => 'טקסט', 'status' => 'publish'],
            'wc_product_search' => ['products' => []],
            default => [],
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($r): string => json_encode($r));
        $this->app->instance(McpClient::class, $mcp);
        $this->forgetServices();
    }

    /** Responses the real MCP client gets back, in order. */
    private function siteReturns(array $responses): void
    {
        $this->app->forgetInstance(McpClient::class);
        $this->forgetServices();

        // ONE sequence for the whole test, appended to. Http::fake never
        // replaces an existing stub — a second call would build a rival
        // sequence the client never reaches while the first quietly ran dry,
        // and the test would then be exercising the error path it was written
        // to avoid.
        $this->sequence ??= Http::fakeSequence();

        foreach ($responses as $response) {
            $this->sequence->push($response);
        }
    }

    /** @return array<string, mixed> */
    private function tool(string $text): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]];
    }

    /** The smallest real PNG, so the type check sees an actual image. */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    private function forgetServices(): void
    {
        foreach ([
            SiteChangePlanner::class,
            ProductChangePlanner::class,
            ImageChangePlanner::class,
            SiteChangeApplier::class,
            WhatsAppCloudClient::class,
            SiteAgentConversation::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }
}
