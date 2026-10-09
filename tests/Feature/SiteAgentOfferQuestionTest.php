<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentToolbox;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A related question preserves its offer; only HTTP boundaries are faked. */
class SiteAgentOfferQuestionTest extends TestCase
{
    use RefreshDatabase;

    private array $modelRequests = [];

    private array $classifications = [];

    private array $siteCalls = [];

    private array $product = ['id' => 90, 'name' => 'ספר', 'type' => 'simple', 'virtual' => false,
        'regular_price' => '150.00', 'sale_price' => '', 'status' => 'publish'];

    private bool $approved = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => '', 'siteagent.assistant.cache.enabled' => false,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->app->instance(ClaudeClient::class, app(ClaudeClient::class));
    }

    #[DataProvider('approvalStates')]
    public function test_related_question_keeps_the_exact_offer_and_its_original_deadline(bool $changedBeforeYes): void
    {
        [$subscriber, $offer] = $this->offer();
        $deadline = $offer->expires_at->toIso8601String();
        $this->fakeRemote(function (int $turn, array $body) use ($offer): PromiseInterface {
            $this->assertReadOnlyCatalog($body);
            $prompt = data_get($body, 'contents.0.parts.0.text');
            $this->assertStringContainsString('הצעה שמורה שטרם בוצעה', $prompt);
            $this->assertStringContainsString('וירטואלי', $prompt);
            $this->assertStringContainsString((string) $offer->plan['product_id'], $prompt);
            if ($turn === 1) {
                return $this->tool('get_product', ['product_id' => 90]);
            }
            $read = json_decode(data_get($body, 'contents.2.parts.0.functionResponse.response.result'), true);
            $this->assertFalse($read['virtual']);

            return $this->textReply('כרגע המוצר פיזי. ההצעה תהפוך אותו לווירטואלי ללא משלוח.');
        });
        $conversation = app(SiteAgentConversation::class);

        $reply = $conversation->handle($subscriber, 'האם המוצר מצריך משלוח?', 'offer-question');

        $this->assertStringStartsWith('כרגע המוצר פיזי.', $reply);
        $this->assertStringEndsWith($offer->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame(SiteAgentRequest::AWAITING, $offer->fresh()->state);
        $this->assertSame($deadline, $offer->fresh()->expires_at->toIso8601String());
        $this->assertSame($offer->plan, $offer->fresh()->plan);
        $this->assertSame(1, SiteAgentRequest::count());
        $this->assertSame([['wc_product_get', ['product_id' => 90]]], $this->siteCalls);
        $this->assertCount(1, $this->classifications);

        $this->product['virtual'] = $changedBeforeYes;
        $this->approved = true;
        $result = $conversation->handle($subscriber, 'כן', 'offer-confirmation');
        $writes = array_filter($this->siteCalls, fn (array $call): bool => $call[0] === 'wc_product_update');
        $this->assertCount(2, $this->modelRequests, 'Approval never calls the model again.');
        if ($changedBeforeYes) {
            $this->assertStringContainsString('השתנה באתר', $result);
            $this->assertSame(SiteAgentRequest::FAILED, $offer->fresh()->state);
            $this->assertCount(0, $writes);
        } else {
            $this->assertStringContainsString('בוצע', $result);
            $this->assertSame(SiteAgentRequest::APPLIED, $offer->fresh()->state);
            $this->assertTrue($this->product['virtual']);
            $this->assertCount(1, $writes);
        }
    }

    public static function approvalStates(): array
    {
        return ['unchanged before approval' => [false], 'changed after explanation' => [true]];
    }

    #[DataProvider('unrelatedClassifications')]
    public function test_revisions_and_unknown_classifications_cancel_the_old_offer_before_a_new_turn(array $classification): void
    {
        [$subscriber, $offer] = $this->offer();
        $this->fakeRemote(function (int $turn, array $body) use ($offer): PromiseInterface {
            $this->assertSame(SiteAgentRequest::CANCELED, $offer->fresh()->state);
            $this->assertContains('propose_product_update', array_column($body['tools'][0]['functionDeclarations'], 'name'));
            $this->assertStringContainsString('בעצם תשנה את המחיר ל־120', data_get($body, 'contents.0.parts.0.text'));

            return $this->textReply('אכין שינוי במחיר לפי הבקשה החדשה.');
        }, $classification);

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'בעצם תשנה את המחיר ל־120', 'revised-offer');

        $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame(SiteAgentRequest::CANCELED, $offer->fresh()->state);
        $this->assertSame([], $this->siteCalls);
    }

    public static function unrelatedClassifications(): array
    {
        return [
            'revision or new topic' => [['related_question' => false]],
            'wrong primitive' => [['related_question' => 'true']],
            'missing decision' => [[]],
            'contradictory extra fields' => [['related_question' => true, 'new_request' => true]],
        ];
    }

    #[DataProvider('forbiddenTools')]
    public function test_read_only_explanation_blocks_unadvertised_mutation_calls(string $name, array $arguments): void
    {
        [$subscriber, $offer] = $this->offer();
        $this->fakeRemote(function (int $turn, array $body) use ($name, $arguments): PromiseInterface {
            $this->assertReadOnlyCatalog($body);
            if ($turn === 1) {
                return $this->tool($name, $arguments);
            }
            $this->assertStringContainsString('מותר לקרוא מידע בלבד', data_get($body, 'contents.2.parts.0.functionResponse.response.result'));

            return $this->textReply('ההצעה מסמנת את המוצר כווירטואלי ללא משלוח.');
        });

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'מה בדיוק ישתנה?', 'read-only-question');

        $this->assertStringEndsWith($offer->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame(SiteAgentRequest::AWAITING, $offer->fresh()->state);
        $this->assertSame(1, SiteAgentRequest::count());
        $this->assertSame([], $this->siteCalls);
        $this->assertFalse($this->product['virtual']);
    }

    public static function forbiddenTools(): array
    {
        return [
            'new product proposal' => ['propose_product_create', ['name' => 'לא ליצור']],
            'page editor' => ['edit_page_text', ['instruction' => 'עדכן עמוד']],
            'billing setting' => ['message_cap', ['cap' => 0]],
            'report subscription' => ['schedule_report', ['frequency' => 'daily']],
            'invented tool' => ['wc_product_update', ['product_id' => 90, 'virtual' => true]],
        ];
    }

    public function test_an_offer_expiring_during_the_explanation_is_not_presented_for_approval(): void
    {
        [$subscriber, $offer] = $this->offer();
        $this->fakeRemote(function (): PromiseInterface {
            $this->travel(31)->minutes();

            return $this->textReply('ההצעה נועדה לבטל את הצורך במשלוח.');
        });

        try {
            $reply = app(SiteAgentConversation::class)->handle($subscriber, 'למה הכוונה וירטואלי?', 'expiring-question');
            $this->assertStringEndsWith(SiteAgentConversation::NO_PENDING_PROPOSAL, $reply);
            $this->assertStringNotContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
            $this->assertStringNotContainsString($offer->preview, $reply);
            $this->assertSame([], $this->siteCalls);
        } finally {
            $this->travelBack();
        }
    }

    public function test_a_long_explanation_preserves_the_exact_offer_and_stays_within_the_message_budget(): void
    {
        [$subscriber, $offer] = $this->offer();
        $this->fakeRemote(fn (): PromiseInterface => $this->textReply(str_repeat('הסבר על משמעות ההצעה. ', 300)));

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'מה המשמעות?', 'long-explanation');

        $this->assertLessThanOrEqual(3500, mb_strlen($reply));
        $this->assertStringEndsWith($offer->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertSame(SiteAgentRequest::AWAITING, $offer->fresh()->state);
    }

    public function test_a_new_image_captioned_yes_cannot_confirm_the_previous_offer(): void
    {
        config(['siteagent.whatsapp.phone_number_id' => '123456', 'siteagent.whatsapp.token' => 'test-token']);
        [$subscriber, $offer] = $this->offer();
        Http::fake(['graph.facebook.com/*' => Http::response([], 503)]);

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'כן', 'new-image', mediaId: 'new-photo');

        $this->assertStringContainsString('לא הצלחתי לקרוא את התמונה', $reply);
        $this->assertSame(SiteAgentRequest::CANCELED, $offer->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com/'));
        $this->assertFalse($this->product['virtual']);
    }

    public function test_an_unavailable_assistant_does_not_run_an_extra_classifier(): void
    {
        config(['billing.ai.enabled' => false]);
        [$subscriber, $offer] = $this->offer();
        Http::fake();

        app(SiteAgentConversation::class)->handle($subscriber, 'בעצם בקשה חדשה', 'unavailable');

        $this->assertSame(SiteAgentRequest::CANCELED, $offer->fresh()->state);
        Http::assertNothingSent();
    }

    private function assertReadOnlyCatalog(array $body): void
    {
        foreach ($body['tools'][0]['functionDeclarations'] as $tool) {
            $this->assertTrue(app(SiteAgentToolbox::class)->isRead($tool['name']), $tool['name']);
        }
    }

    private function fakeRemote(Closure $answer, array $classification = ['related_question' => true]): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => function (Request $request) use ($answer, $classification): PromiseInterface {
                $body = $request->data();
                if (isset($body['generationConfig']['responseSchema'])) {
                    $this->classifications[] = $body;

                    return $this->textReply(json_encode($classification));
                }
                $this->modelRequests[] = $body;

                return $answer(count($this->modelRequests), $body);
            },
            'offers.test/*' => function (Request $request): PromiseInterface {
                $body = $request->data();
                $tool = $body['params']['name'];
                $arguments = (array) $body['params']['arguments'];
                $this->siteCalls[] = [$tool, $arguments];
                if ($tool === 'wc_product_update') {
                    $this->assertTrue($this->approved);
                    $previous = $this->product;
                    $this->product['virtual'] = $arguments['virtual'];
                    $result = ['changed' => true, 'previous' => $previous];
                } else {
                    $this->assertSame('wc_product_get', $tool);
                    $result = $this->product;
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]], 'isError' => false,
                ]]);
            },
        ]);
    }

    private function textReply(string $text): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    private function tool(string $name, array $arguments): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['id' => 'question-read', 'name' => $name, 'args' => $arguments],
        ]]]]]]);
    }

    private function offer(): array
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'offers.test', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://offers.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'test-site-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.12.0'], 'tools' => array_map(
                fn (string $name): array => ['name' => $name],
                array_unique([...SiteAgentToolbox::pluginTools(), ...app(SiteActionProposer::class)->pluginTools()]),
            )],
        ]);
        $subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id, 'phone' => '972501234567', 'verified_at' => now(),
        ]);
        $offer = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $site->id, 'customer_id' => $customer->id,
            'message' => 'תהפוך את הספר לווירטואלי', 'operation' => SiteAgentRequest::OP_PRODUCT,
            'state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addMinutes(30),
            'preview' => '🛒 ספר: סוג מוצר פיזי ← וירטואלי — ללא משלוח',
            'plan' => ['operation' => SiteAgentRequest::OP_PRODUCT, 'product_id' => 90, 'product_name' => 'ספר',
                'fields' => ['virtual' => true], 'current' => ['virtual' => false], 'summary' => 'הפיכת הספר לווירטואלי'],
        ]);

        return [$subscriber, $offer];
    }
}
