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
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real conversation state transitions; only model/site/transport boundaries are faked. */
class SiteAgentImageDialogueTest extends TestCase
{
    use RefreshDatabase;

    private array $answers = [];

    private array $prompts = [];

    private array $calls = [];

    private array $products = [];

    private ?array $searchResponse = null;

    private ?array $pageResponse = null;

    private bool $allowWrites = false;

    private SiteAgentSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['siteagent.assistant.enabled' => false]);
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'example.test']);
        $this->subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
        // More than thirty products exist; only a real targeted name search
        // can find the photographed product, regardless of catalogue windows.
        for ($i = 1; $i <= 35; $i++) {
            $this->products[$i] = ['id' => $i, 'name' => 'מוצר ותיק '.$i, 'status' => 'publish', 'thumbnail_id' => 0];
        }
        $this->products[33852] = ['id' => 33852, 'name' => 'מוצר דוגמה', 'status' => 'publish', 'thumbnail_id' => 42];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments = []): array {
            $this->assertSame($this->subscriber->site_id, $site->id);
            $this->calls[] = [$tool, $arguments];

            return match ($tool) {
                'wp_content_list' => $this->pageResponse ?? [],
                'wp_content_get' => throw new \RuntimeException('Products use their native getter.'),
                'wc_product_search' => $this->searchResponse ?? ['products' => array_values(array_filter($this->products,
                    fn (array $product): bool => $product['name'] === ($arguments['search'] ?? null)))],
                'wc_product_get' => $this->products[$arguments['product_id']] ?? [],
                'wp_media_upload' => $this->allowWrites ? ['id' => 99] : throw new \RuntimeException('Unapproved upload'),
                'wp_post_thumbnail_set' => $this->allowWrites ? ['id' => $arguments['id'], 'attachment_id' => 99, 'previous' => ['attachment_id' => 42], 'changed' => true] : throw new \RuntimeException('Unapproved thumbnail change'),
                default => throw new \RuntimeException('Unexpected write before confirmation: '.$tool),
            };
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);

        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturnUsing(function (string $system, string $prompt): ?array {
            $this->prompts[] = $prompt;
            $this->assertNotEmpty($this->answers, 'Unexpected extra model call.');

            return array_shift($this->answers);
        });
        $this->app->instance(ClaudeClient::class, $ai);
        $whatsapp = Mockery::mock(WhatsAppCloudClient::class);
        $whatsapp->shouldReceive('downloadMedia')->andReturn(['bytes' => 'private-image-fixture', 'extension' => 'png']);
        $this->app->instance(WhatsAppCloudClient::class, $whatsapp);
    }

    public function test_the_reported_product_name_then_description_completes_without_the_repeating_destination_question(): void
    {
        $first = $this->talk('', 'photo-1');
        $this->assertStringContainsString('קיבלתי את התמונה', $first);
        $request = SiteAgentRequest::sole();
        $path = $request->plan['image_path'];
        $this->answers = [
            ['can_do' => false, 'target_query' => 'מוצר דוגמא', 'target_kind' => 'product', 'needs' => 'alt'],
            ['can_do' => false, 'target_query' => 'מוצר דוגמה', 'target_kind' => 'product', 'needs' => 'alt'],
            ['can_do' => false, 'target_id' => 33852, 'needs' => 'alt'],
        ];

        $this->assertStringContainsString('איך לתאר', $this->talk('מוצר דוגמא'));
        $this->assertSame(33852, $request->refresh()->plan['image_draft']['target_id']);
        $this->assertNull($request->preview);
        $this->assertSame(3, count($this->prompts));
        // Even if the model omits the already known destination, the verified
        // draft retains it while this message answers the alt question.
        $this->answers = [['can_do' => false, 'alt' => 'מוצר דוגמא', 'needs' => 'target']];
        $preview = $this->talk('מוצר דוגמא');
        $this->assertStringContainsString('מוצר דוגמה', $preview);
        $this->assertStringContainsString('תיאור לנגישות: "מוצר דוגמא"', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertSame(33852, $request->refresh()->plan['target_id']);
        $this->assertSame(42, $request->plan['thumbnail_id']);
        $this->assertSame(1, SiteAgentRequest::count());
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('איך לתאר', end($this->prompts));
        $this->assertStringContainsString('saved_image_draft', end($this->prompts));
        $this->assertSame([], $this->answers);
        $this->assertFalse(collect($this->calls)->contains(fn (array $call): bool => str_contains($call[0], 'upload') || str_contains($call[0], 'update')));

        $this->allowWrites = true;
        $this->assertStringContainsString('בוצע', $this->talk('כן'));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $uploads = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'wp_media_upload'));
        $sets = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'wp_post_thumbnail_set'));
        $this->assertCount(1, $uploads);
        $this->assertCount(1, $sets);
        $this->assertSame('מוצר דוגמא', $uploads[0][1]['alt']);
        $this->assertSame(['id' => 33852, 'attachment_id' => 99, 'if_current' => 42], $sets[0][1]);
    }

    public function test_explicit_alt_in_the_original_caption_survives_a_model_omission(): void
    {
        $this->answers = [
            ['can_do' => false, 'relation' => 'image', 'destination' => 'attach', 'target_id' => 33852, 'alt' => '', 'needs' => 'alt'],
        ];
        // This target is present in the verified search results. The omitted
        // model field must not cause another question about the supplied alt.
        $this->searchResponse = ['products' => [$this->products[33852]]];

        $reply = $this->talk('הגדר את התמונה הזאת כתמונה הראשית של מוצר דוגמה. הטקסט החלופי שלה: "הכניסה לחנות שלנו".', 'photo-explicit-alt');
        $request = SiteAgentRequest::sole();

        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame('הכניסה לחנות שלנו', $request->plan['alt']);
        $this->assertSame(33852, $request->plan['target_id']);
        $this->assertNotContains('wp_media_upload', array_column($this->calls, 0));
        $this->allowWrites = true;
        $this->assertStringContainsString('בוצע', $this->talk('כן'));
        $upload = collect($this->calls)->first(fn (array $call): bool => $call[0] === 'wp_media_upload');
        $this->assertSame('הכניסה לחנות שלנו', $upload[1]['alt']);
    }

    public function test_a_negated_alt_label_is_not_used_as_an_accessibility_description(): void
    {
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->searchResponse = ['products' => [$this->products[33852]]];

        $reply = $this->talk('שים את התמונה במוצר דוגמה. אל תשתמש בטקסט החלופי: "לא נכון".', 'photo-negated-alt');

        $this->assertStringContainsString('איך לתאר', $reply);
        $this->assertArrayNotHasKey('alt', SiteAgentRequest::sole()->plan['image_draft']);
    }

    public function test_saving_in_media_and_setting_the_product_picture_are_one_attach_proposal(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'alt' => 'קופסה כחולה על שולחן', 'needs' => 'target']];
        $this->talk('בתמונה קופסה כחולה על שולחן');
        $this->answers = [
            ['can_do' => false, 'upload_only' => true, 'target_query' => 'מוצר דוגמה', 'target_kind' => 'product'],
            ['can_do' => true, 'upload_only' => true, 'target_id' => 33852],
        ];

        $reply = $this->talk('לשמור את התמונה במדיה ולהשים אותה תמונת מוצר במוצר דןגמא');
        $request = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::OP_IMAGE, $request->operation);
        $this->assertSame('קופסה כחולה על שולחן', $request->plan['alt']);
        $this->assertStringContainsString('כתמונה הראשית', $reply);
        $this->assertStringNotContainsString('ספריית המדיה בלבד', $reply);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('site-agent')));
    }

    public function test_a_count_question_does_not_become_alt_text_or_discard_the_waiting_image(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->talk('מוצר דוגמה');
        $request = SiteAgentRequest::sole();
        $path = $request->plan['image_path'];
        $this->answers = [['can_do' => false, 'relation' => 'topic_switch']];
        $assistant = Mockery::mock(SiteAgentAssistant::class);
        $assistant->shouldReceive('remember');
        $assistant->shouldReceive('available')->andReturn(true);
        $assistant->shouldReceive('handle')->once()->withArgs(fn ($subscriber, $site, $text): bool => $text === 'כמה מוצרים יש לי באתר')
            ->andReturn('יש באתר 36 מוצרים.');
        $this->app->instance(SiteAgentAssistant::class, $assistant);

        $this->assertSame('יש באתר 36 מוצרים.', $this->talk('כמה מוצרים יש לי באתר'));
        $this->assertNull($request->refresh()->preview);
        $this->assertArrayNotHasKey('alt', $request->plan['image_draft']);
        Storage::disk('local')->assertExists($path);
        $this->answers = [['can_do' => true, 'alt' => 'אריזה כחולה']];
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk('התיאור הוא אריזה כחולה'));
        $this->assertSame(33852, $request->refresh()->plan['target_id']);
    }

    public function test_a_changed_target_is_live_validated_and_does_not_reuse_the_previous_product(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->talk('מוצר דוגמה');
        $this->answers = [
            ['can_do' => false, 'target_changed' => true, 'target_query' => 'מוצר ותיק 35', 'target_kind' => 'product', 'alt' => 'כוס כחולה'],
            ['can_do' => true, 'target_id' => 35, 'alt' => 'כוס כחולה'],
        ];

        $this->assertStringContainsString('מוצר ותיק 35', $this->talk('בעצם שים במוצר ותיק 35, רואים כוס כחולה'));
        $this->assertSame(35, SiteAgentRequest::sole()->plan['target_id']);
    }

    public function test_a_deleted_saved_target_and_an_invented_id_never_produce_a_preview(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->talk('מוצר דוגמה');
        unset($this->products[33852]);
        $this->answers = [['can_do' => true, 'target_id' => 33852, 'alt' => 'כוס כחולה']];

        $reply = $this->talk('כוס כחולה');
        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $request = SiteAgentRequest::sole();
        $this->assertNull($request->preview);
        $this->assertArrayNotHasKey('target_id', $request->plan['image_draft']);
        $this->assertSame('כוס כחולה', $request->plan['image_draft']['alt']);
    }

    public function test_a_trashed_saved_target_is_not_offered_again_when_the_description_arrives(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->talk('מוצר דוגמה');
        $this->products[33852]['status'] = 'trash';
        $this->answers = [['can_do' => true, 'target_id' => 33852, 'alt' => 'אריזה כחולה']];

        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk('אריזה כחולה'));
        $this->assertNull(SiteAgentRequest::sole()->preview);
        $this->assertArrayNotHasKey('target_id', SiteAgentRequest::sole()->plan['image_draft']);
    }

    public function test_a_missing_attachment_target_cannot_degrade_to_a_library_only_upload(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [
            ['can_do' => false, 'upload_only' => true, 'target_query' => 'לא קיים', 'alt' => 'אריזה כחולה'],
            ['can_do' => true, 'upload_only' => true, 'target_id' => 999999, 'alt' => 'אריזה כחולה'],
        ];

        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk('שמור בספרייה ושים במוצר לא קיים'));
        $this->assertNull(SiteAgentRequest::sole()->preview);
        $this->assertFalse(SiteAgentRequest::sole()->plan['image_draft']['upload_only']);
    }

    public function test_a_new_mutation_after_a_topic_switch_cleans_the_waiting_photo_in_both_entry_paths(): void
    {
        $assistant = Mockery::mock(SiteAgentAssistant::class);
        $assistant->shouldReceive('remember');
        $assistant->shouldReceive('available')->andReturn(true);
        $assistant->shouldReceive('handle')->twice()->andReturnUsing(function (): string {
            SiteAgentRequest::create([
                'site_agent_subscriber_id' => $this->subscriber->id,
                'site_id' => $this->subscriber->site_id,
                'customer_id' => $this->subscriber->customer_id,
                'message' => 'שינוי כותרת', 'operation' => SiteAgentRequest::OP_TITLE,
                'plan' => ['page_id' => 1, 'text' => 'כותרת'], 'preview' => 'שינוי כותרת',
                'state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addMinutes(30),
            ]);

            return 'שינוי כותרת '.SiteAgentConversation::CONFIRM_PROMPT;
        });
        $this->app->instance(SiteAgentAssistant::class, $assistant);
        $this->answers = [['can_do' => false, 'relation' => 'topic_switch']];
        $this->talk('שנה כותרת', 'photo-1');
        $first = SiteAgentRequest::oldest('id')->first();
        $this->assertSame(SiteAgentRequest::CANCELED, $first->state);
        Storage::disk('local')->assertMissing($first->plan['image_path']);
        $this->talk('לא');

        $this->talk('', 'photo-2');
        $second = SiteAgentRequest::latest('id')->first();
        $this->answers = [['can_do' => false, 'relation' => 'topic_switch']];
        $this->talk('שנה כותרת');
        $this->assertSame(SiteAgentRequest::CANCELED, $second->refresh()->state);
        Storage::disk('local')->assertMissing($second->plan['image_path']);
        $this->assertSame(1, SiteAgentRequest::awaitingConfirmation()->count());
    }

    public function test_a_renamed_saved_target_uses_the_current_site_title_in_the_preview(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = [['can_do' => false, 'target_id' => 33852, 'needs' => 'alt']];
        $this->talk('מוצר דוגמה');
        $this->products[33852]['name'] = 'השם הנוכחי';
        $this->answers = [['can_do' => true, 'alt' => 'אריזה כחולה']];

        $this->assertStringContainsString('השם הנוכחי', $this->talk('אריזה כחולה'));
        $this->assertSame('השם הנוכחי', SiteAgentRequest::sole()->plan['target_title']);
    }

    public function test_repeated_unresolved_answers_get_an_honest_recovery_message_while_the_photo_is_kept(): void
    {
        $this->talk('', 'photo-1');
        $this->answers = array_fill(0, 3, ['can_do' => false, 'needs' => 'target']);
        $this->talk('עליו');
        $reply = $this->talk('התכוונתי למוצר הזה');

        $this->assertStringContainsString(ImageChangePlanner::UNRESOLVED_IMAGE, $reply);
        $this->assertNull(SiteAgentRequest::sole()->preview);
        Storage::disk('local')->assertExists(SiteAgentRequest::sole()->plan['image_path']);
        $this->assertStringContainsString(ImageChangePlanner::UNRESOLVED_IMAGE, $this->talk('אותו מוצר'));
    }

    public function test_library_recovery_only_asks_for_the_missing_description_and_retains_its_destination(): void
    {
        $this->answers = array_fill(0, 3, ['can_do' => false, 'upload_only' => true, 'needs' => 'alt']);
        $this->talk('שמור רק בספריית המדיה', 'photo-1');
        $this->talk('בספרייה בלבד');
        $reply = $this->talk('אמרתי בספריית המדיה');

        $this->assertStringContainsString(ImageChangePlanner::UNRESOLVED_IMAGE, $reply);
        $this->assertStringContainsString('איך לתאר', $reply);
        $this->assertStringNotContainsString('לשים אותה בעמוד', $reply);
        $this->assertTrue(SiteAgentRequest::sole()->plan['image_draft']['upload_only']);
        $this->answers = [['can_do' => true, 'alt' => 'כוס כחולה']];
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk('כוס כחולה'));
        $this->assertSame(SiteAgentRequest::OP_MEDIA_UPLOAD, SiteAgentRequest::sole()->operation);
    }

    #[DataProvider('imageSearchResponses')]
    public function test_lookup_failure_is_distinct_from_a_real_empty_search(array $response, bool $available): void
    {
        $this->searchResponse = $response;
        $this->talk('', 'photo-1');
        $this->answers = [
            ['can_do' => false, 'target_query' => 'מוצר דוגמה', 'alt' => 'כוס כחולה'],
            ['can_do' => false, 'needs' => 'target'],
        ];

        $reply = $this->talk('שים במוצר דוגמה, רואים כוס כחולה');
        if ($available) {
            $this->assertStringContainsString('לא מצאתי יעד חד־משמעי', $reply);
            $this->assertStringNotContainsString('לא הצלחתי לחפש כרגע באתר', $reply);
        } else {
            $this->assertStringContainsString('לא הצלחתי לחפש כרגע באתר', $reply);
            $this->assertStringNotContainsString('לא מצאתי יעד חד־משמעי', $reply);
        }
        $this->assertNull(SiteAgentRequest::sole()->preview);
        Storage::disk('local')->assertExists(SiteAgentRequest::sole()->plan['image_path']);
    }

    public static function imageSearchResponses(): array
    {
        return [
            'true empty' => [['products' => [], 'total' => 0, 'returned' => 0], true],
            'legacy empty' => [['products' => []], true],
            'error envelope' => [['error' => 'PRIVATE_SERVER_ERROR'], false],
            'missing rows' => [['total' => 0], false],
            'rows are not a list' => [['products' => ['message' => 'PRIVATE_SERVER_ERROR']], false],
            'malformed row' => [['products' => [['name' => 'מוצר ללא מזהה']]], false],
            'malformed id' => [['products' => [['id' => true, 'name' => 'מוצר']]], false],
            'missing title' => [['products' => [['id' => 7]]], false],
            'empty despite positive total' => [['products' => [], 'total' => 36], false],
            'returned mismatch' => [['products' => [], 'returned' => 1], false],
        ];
    }

    public function test_legacy_bare_page_lists_are_still_valid_target_search_results(): void
    {
        $this->pageResponse = [['id' => 43, 'title' => 'עמוד ישן', 'status' => 'publish']];
        $this->answers = [
            ['can_do' => false, 'target_query' => 'עמוד ישן', 'target_kind' => 'page', 'alt' => 'כוס כחולה'],
            ['can_do' => true, 'target_id' => 43],
        ];

        $plan = app(ImageChangePlanner::class)->plan($this->subscriber->site, 'תמונה לעמוד הישן', []);
        $this->assertSame(SiteAgentRequest::OP_IMAGE, $plan['operation']);
        $this->assertSame(43, $plan['target_id']);
        $this->assertSame('עמוד ישן', $plan['target_title']);
        $this->assertSame('כוס כחולה', $plan['alt']);
    }

    public function test_cancel_replacement_and_expiry_remove_private_files_without_any_site_write(): void
    {
        $this->talk('', 'photo-1');
        $first = SiteAgentRequest::sole();
        $this->talk('', 'photo-2');
        Storage::disk('local')->assertMissing($first->plan['image_path']);
        $this->assertSame(SiteAgentRequest::CANCELED, $first->refresh()->state);
        $second = SiteAgentRequest::latest('id')->first();
        $this->answers = [['can_do' => false, 'relation' => 'cancel']];
        $this->assertStringContainsString('בוטל', $this->talk('עזוב את התמונה הזאת כרגע'));
        Storage::disk('local')->assertMissing($second->plan['image_path']);

        $this->talk('', 'photo-3');
        $third = SiteAgentRequest::latest('id')->first();
        $this->travel(2)->hours();
        (new PruneSiteAgentRequestsJob)->handle();
        Storage::disk('local')->assertMissing($third->plan['image_path']);
        $this->assertSame(SiteAgentRequest::EXPIRED, $third->refresh()->state);
    }

    public function test_yes_cannot_confirm_an_unanswered_image_question_or_trigger_extra_model_calls(): void
    {
        $question = $this->talk('', 'photo-1');

        $this->assertSame($question, $this->talk('כן'));
        $this->assertNull(SiteAgentRequest::sole()->preview);
        $this->assertSame([], $this->prompts);
    }

    private function talk(string $text, ?string $mediaId = null): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $text, 'wamid.'.uniqid(), $mediaId);
    }
}
