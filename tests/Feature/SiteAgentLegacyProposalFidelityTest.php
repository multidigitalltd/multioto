<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\ImageChangePlanner;
use App\Services\SiteAgent\ProductChangePlanner;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentProposalFidelity;
use App\Services\SiteAgent\SiteChangeApplier;
use App\Services\SiteAgent\SiteChangePlanner;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Legacy planners share the same semantic gate before an offer becomes approvable. */
class SiteAgentLegacyProposalFidelityTest extends TestCase
{
    use RefreshDatabase;

    private const REJECTED = 'לא הוכנה הצעה תואמת. יש להשלים את הפרט החסר.';

    private SiteAgentSubscriber $subscriber;

    private $planner;

    private $products;

    private $images;

    private $assistant;

    private $proposer;

    private $applier;

    private $fidelity;

    private int $message = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $this->subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);

        foreach ([
            'planner' => SiteChangePlanner::class, 'products' => ProductChangePlanner::class,
            'images' => ImageChangePlanner::class, 'assistant' => SiteAgentAssistant::class,
            'proposer' => SiteActionProposer::class, 'applier' => SiteChangeApplier::class,
            'fidelity' => SiteAgentProposalFidelity::class,
        ] as $property => $class) {
            $this->{$property} = Mockery::mock($class);
            $this->app->instance($class, $this->{$property});
        }
        $this->assistant->shouldReceive('available')->byDefault()->andReturnFalse();
        $this->assistant->shouldReceive('remember')->byDefault();
        $this->products->shouldReceive('plan')->byDefault()->andReturnNull();
        $this->planner->shouldReceive('targets')->byDefault()->andReturn([]);
        $this->planner->shouldReceive('thumbnailOf')->byDefault()->andReturn(4);
        $whatsapp = Mockery::mock(WhatsAppCloudClient::class);
        $whatsapp->shouldReceive('downloadMedia')->andReturn(['bytes' => 'PRIVATE-PHOTO-BYTES', 'extension' => 'png']);
        $this->app->instance(WhatsAppCloudClient::class, $whatsapp);
    }

    #[DataProvider('nonAllowVerdicts')]
    public function test_nonmatching_or_unavailable_plain_offer_is_never_saved_or_confirmable(string $verdict): void
    {
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $this->review($verdict, 'עדכן את הברכה בבית', SiteAgentRequest::OP_REPLACE, 'הברכה החדשה');
        $this->assertSame(self::REJECTED, $this->talk('עדכן את הברכה בבית'));
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertSame(SiteAgentConversation::NO_PENDING_PROPOSAL, $this->talk('כן'));
        $this->applier->shouldNotHaveReceived('apply');
        Http::assertNothingSent();
    }

    public static function nonAllowVerdicts(): array
    {
        return [['revise'], ['clarify'], ['refuse'], ['unavailable']];
    }

    public function test_allowed_plain_offer_is_checked_once_before_save_and_never_on_confirmation_or_undo(): void
    {
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $this->review('allow', 'עדכן את הברכה בבית', SiteAgentRequest::OP_REPLACE, 'הברכה החדשה');
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $this->talk('עדכן את הברכה בבית'));
        $request = SiteAgentRequest::sole();
        $this->applier->shouldReceive('apply')->once()->andReturn(['ok' => true, 'restore' => ['kind' => 'page', 'before' => 'original']]);
        $this->applier->shouldReceive('revert')->once()->andReturn(['ok' => true]);
        $this->talk('כן');
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);
        $this->talk('בטל');
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
    }

    public function test_rewritten_page_tool_instruction_cannot_replace_the_actual_owner_message_in_review(): void
    {
        $owner = 'הוסף קישור מהמילים בדף הבית לעמוד צור קשר';
        $rewritten = 'הוסף לדף הבית את המילים עמוד צור קשר';
        $this->assistant->shouldReceive('available')->andReturnTrue();
        $this->assistant->shouldReceive('handle')->once()->andReturnUsing(function ($subscriber, $site, $text, $messageId, $editPages) use ($owner, $rewritten): string {
            $this->assertSame($owner, $text);

            return $editPages($rewritten);
        });
        $this->planner->shouldReceive('mentionsFrontPage')->with($owner)->andReturnFalse();
        $this->planner->shouldReceive('plan')->with(Mockery::type(Site::class), $rewritten)->once()->andReturn($this->pagePlan());
        $this->review('revise', $owner, SiteAgentRequest::OP_REPLACE, 'הברכה החדשה');
        $this->assertSame(self::REJECTED, $this->talk($owner));
        $this->assertSame(0, SiteAgentRequest::count());
    }

    #[DataProvider('questionKinds')]
    public function test_question_answer_cannot_leave_an_unreviewed_offer_or_stale_preview(string $kind): void
    {
        $question = $this->held(['kind' => $kind, 'question' => 'באיזה פריט?', 'text' => 'בקשה מקורית'], null);
        $plan = $kind === 'page' ? $this->pagePlan() : $this->pricePlan();
        $planner = $kind === 'page' ? $this->planner : $this->products;
        $planner->shouldReceive('plan')->once()->andReturn($plan);
        $this->review('clarify', 'הראשון', $plan['operation'], $kind === 'page' ? 'הברכה החדשה' : '90');
        $this->assertSame(self::REJECTED, $this->talk('הראשון'));
        $question->refresh();
        $this->assertNull($question->preview);
        $this->assertNull($question->operation);
        $this->assertSame($kind, $question->plan['kind']);
        $this->assertArrayNotHasKey('target_id', $question->plan);
        $this->assertStringContainsString('הראשון', $question->plan['text']);
        $this->assertSame(self::REJECTED, $this->talk('כן'));
        $this->applier->shouldNotHaveReceived('apply');
    }

    public static function questionKinds(): array
    {
        return [['page'], ['product']];
    }

    #[DataProvider('imageKinds')]
    public function test_initial_image_and_new_product_rejection_retains_file_without_an_approvable_target(string $kind): void
    {
        $plan = $this->imagePlan($kind);
        $operation = $kind === 'product' ? SiteAgentRequest::OP_PRODUCT_CREATE : $plan['operation'];
        $this->images->shouldReceive('plan')->once()->andReturn($plan);
        $this->review('revise', 'שים את התמונה בפריט שביקשתי', $operation, $kind === 'product' ? 'מוצר חדש' : 'תיאור לנגישות');
        $this->assertSame(self::REJECTED, $this->talk('שים את התמונה בפריט שביקשתי', 'photo'));
        $request = SiteAgentRequest::sole();
        Storage::disk('local')->assertExists($request->plan['image_path']);
        $this->assertNull($request->preview);
        $this->assertSame(SiteAgentRequest::OP_IMAGE, $request->operation);
        $this->assertSame([], $request->plan['image_draft']);
        foreach (['target_id', 'fields', 'prepared', 'expected', 'new_product'] as $key) {
            $this->assertArrayNotHasKey($key, $request->plan);
        }
        $this->assertSame(self::REJECTED, $this->talk('כן'));
        $this->applier->shouldNotHaveReceived('apply');
    }

    #[DataProvider('imageKinds')]
    public function test_image_corrections_clear_the_old_offer_on_failed_review_but_keep_the_file(string $kind): void
    {
        $path = 'site-agent/private-image-sentinel.png';
        Storage::disk('local')->put($path, 'PRIVATE-PHOTO-BYTES');
        $request = $this->held([
            ...$this->imagePlan('attach'), 'image_path' => $path, 'extension' => 'png', 'caption' => 'בקשה מקורית',
            'image_draft' => ['target_id' => 43, 'target_title' => 'דף הבית'],
        ], 'old verified preview', SiteAgentRequest::OP_IMAGE);
        $plan = $this->imagePlan($kind);
        $operation = $kind === 'product' ? SiteAgentRequest::OP_PRODUCT_CREATE : $plan['operation'];
        $this->images->shouldReceive('plan')->once()->andReturn($plan);
        $this->review('unavailable', 'בעצם רק לשמור במדיה', $operation, $kind === 'product' ? 'מוצר חדש' : 'תיאור לנגישות');
        $this->assertSame(self::REJECTED, $this->talk('בעצם רק לשמור במדיה'));
        $request->refresh();
        $this->assertNull($request->preview);
        $this->assertSame($path, $request->plan['image_path']);
        $this->assertSame(['target_id' => 43, 'target_title' => 'דף הבית'], $request->plan['image_draft']);
        foreach (['target_id', 'fields', 'prepared', 'expected', 'new_product'] as $key) {
            $this->assertArrayNotHasKey($key, $request->plan);
        }
        Storage::disk('local')->assertExists($path);
        $this->assertSame(self::REJECTED, $this->talk('כן'));
        $this->applier->shouldNotHaveReceived('apply');
        $this->talk('לא');
        Storage::disk('local')->assertMissing($path);
    }

    public static function imageKinds(): array
    {
        return [['attach'], ['media'], ['product']];
    }

    public function test_rejected_new_topic_cannot_confirm_the_previous_image_offer(): void
    {
        $path = 'site-agent/topic-switch.png';
        Storage::disk('local')->put($path, 'PRIVATE-PHOTO-BYTES');
        $request = $this->held([...$this->imagePlan('attach'), 'image_path' => $path, 'caption' => 'תמונה לבית'], 'old image preview', SiteAgentRequest::OP_IMAGE);
        $this->images->shouldReceive('plan')->once()->andReturn(['topic_switch' => true]);
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $this->review('revise', 'עכשיו תערוך את הטקסט', SiteAgentRequest::OP_REPLACE, 'הברכה החדשה');
        $this->assertSame(self::REJECTED, $this->talk('עכשיו תערוך את הטקסט'));
        $this->assertNull($request->refresh()->preview);
        $this->assertNotEmpty($request->plan['question']);
        Storage::disk('local')->assertExists($path);
        $this->assertSame($request->plan['question'], $this->talk('כן'));
        $this->applier->shouldNotHaveReceived('apply');
    }

    public function test_real_fidelity_service_receives_prior_owner_words_when_the_assistant_transcript_is_disabled(): void
    {
        config(['siteagent.assistant.enabled' => false]);
        $owner = 'בדף הבית תשנה את הברכה, אך אל תשנה את מספר הטלפון';
        $this->planner->shouldReceive('plan')->once()->andReturn(['question' => 'מה הברכה החדשה? MODEL-QUESTION-NOT-OWNER']);
        $this->talk($owner);
        $this->assertSame([$owner], SiteAgentRequest::sole()->plan['owner_messages']);
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->once()->andReturnTrue();
        $ai->shouldReceive('structured')->once()->andReturnUsing(function (string $system, string $prompt) use ($owner): array {
            $data = json_decode($prompt, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('הברכה החדשה', $data['current_owner_message']);
            $this->assertSame([$owner], $data['earlier_owner_messages']);
            $this->assertSame([], $data['recent_conversation']);
            $this->assertStringNotContainsString('MODEL-QUESTION-NOT-OWNER', json_encode($data['earlier_owner_messages']));

            return ['verdict' => 'allow', 'reason' => 'matched', 'feedback' => ''];
        });
        $this->app->instance(SiteAgentProposalFidelity::class, new SiteAgentProposalFidelity($ai));
        $reply = $this->talk('הברכה החדשה');
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertNotNull(SiteAgentRequest::sole()->preview);
    }

    public function test_unknown_legacy_question_context_requires_full_request_instead_of_using_rewritten_instructions(): void
    {
        $request = $this->held(['kind' => 'page', 'question' => 'איזו ברכה?', 'text' => 'MODEL-REWRITTEN-REQUEST'], null);
        $request->update(['plan' => array_diff_key($request->plan, ['owner_messages' => true])]);
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $reply = $this->talk('הברכה החדשה');
        $this->assertStringContainsString('הבקשה המלאה', $reply);
        $this->assertNull($request->refresh()->preview);
        $this->assertSame('', $request->plan['text']);
        $this->assertSame([], $request->plan['owner_messages']);
        $this->fidelity->shouldNotHaveReceived('review');
    }

    public function test_owner_context_overflow_is_not_silently_truncated_into_an_approvable_request(): void
    {
        $owner = str_repeat('א', 12000).' אל תשנה את הטלפון';
        $this->planner->shouldReceive('plan')->once()->andReturn(['question' => 'מה הטקסט?']);
        $this->talk($owner);
        $request = SiteAgentRequest::sole();
        $this->assertFalse($request->plan['owner_messages_complete']);
        $this->planner->shouldReceive('plan')->once()->andReturn($this->pagePlan());
        $this->assertStringContainsString('הבקשה המלאה', $this->talk('חדש'));
        $this->assertNull($request->refresh()->preview);
        $this->fidelity->shouldNotHaveReceived('review');
    }

    #[DataProvider('homepageEvidence')]
    public function test_homepage_role_is_shown_only_for_matching_server_bound_identity(array $front, bool $verified): void
    {
        $plan = [...$this->pagePlan(), 'page_title' => 'login', 'front_page' => $front];
        $this->planner->shouldReceive('plan')->once()->andReturn($plan);
        $this->fidelity->shouldReceive('review')->once()->andReturnUsing(function ($subscriber, $owner, $operation, $preview) use ($verified): array {
            $this->assertSame($verified, str_contains($preview, 'דף הבית — עמוד "login"'));

            return ['verdict' => 'allow', 'reason' => 'matched', 'feedback' => '', 'reply' => ''];
        });
        $this->talk('עדכן את הברכה');
    }

    public static function homepageEvidence(): array
    {
        return [[['mode' => 'page', 'id' => 43, 'blog_id' => 0], true],
            [['mode' => 'page', 'id' => 44, 'blog_id' => 0], false],
            [['mode' => 'posts', 'id' => 43, 'blog_id' => 0], false]];
    }

    private function review(string $verdict, string $owner, string $operation, string $previewPart): void
    {
        $this->fidelity->shouldReceive('review')->once()->andReturnUsing(function ($subscriber, $actualOwner, $actualOperation, $preview) use ($verdict, $owner, $operation, $previewPart): array {
            $this->assertSame($this->subscriber->id, $subscriber->id);
            $this->assertSame($owner, $actualOwner);
            $this->assertSame($operation, $actualOperation);
            $this->assertStringContainsString($previewPart, $preview);
            foreach (['PRIVATE-PHOTO-BYTES', 'private-image-sentinel', 'OPAQUE-SNAPSHOT'] as $secret) {
                $this->assertStringNotContainsString($secret, $preview);
            }

            return ['verdict' => $verdict, 'reason' => $verdict === 'allow' ? 'matched' : 'wrong_action', 'feedback' => '', 'reply' => $verdict === 'allow' ? '' : self::REJECTED];
        });
    }

    private function pagePlan(): array
    {
        return ['operation' => SiteAgentRequest::OP_REPLACE, 'page_id' => 43, 'page_title' => 'דף הבית',
            'find' => 'הברכה הישנה', 'text' => 'הברכה החדשה', 'summary' => 'ברכה'];
    }

    private function pricePlan(): array
    {
        return ['operation' => SiteAgentRequest::OP_PRICE, 'target_id' => 7, 'product_name' => 'חולצה',
            'current' => ['regular_price' => '100'], 'fields' => ['regular_price' => '90']];
    }

    private function imagePlan(string $kind): array
    {
        if ($kind === 'product') {
            $this->proposer->shouldReceive('newProduct')->once()->andReturn([
                'plan' => ['operation' => SiteAgentRequest::OP_PRODUCT_CREATE, 'fields' => ['name' => 'חולצה'], 'prepared' => 'OPAQUE-SNAPSHOT'],
                'preview' => 'מוצר חדש: חולצה. מחיר: 90. תמונה עם תיאור לנגישות.',
            ]);

            return ['new_product' => ['name' => 'חולצה', 'price' => '90'], 'alt' => 'חולצה'];
        }

        return $kind === 'media'
            ? ['operation' => SiteAgentRequest::OP_MEDIA_UPLOAD, 'title' => 'חולצה', 'alt' => 'חולצה']
            : ['operation' => SiteAgentRequest::OP_IMAGE, 'target_id' => 43, 'target_title' => 'דף הבית', 'alt' => 'חולצה'];
    }

    private function held(array $plan, ?string $preview, ?string $operation = null): SiteAgentRequest
    {
        return SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->subscriber->id, 'customer_id' => $this->subscriber->customer_id,
            'site_id' => $this->subscriber->site_id, 'message' => 'בקשה מקורית', 'plan' => $plan + ['owner_messages' => ['בקשה מקורית']],
            'operation' => $operation, 'preview' => $preview, 'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function talk(string $text, ?string $media = null): string
    {
        return app(SiteAgentConversation::class)->handle($this->subscriber, $text, 'fidelity.legacy.'.++$this->message, $media);
    }
}
