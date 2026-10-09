<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentAssistant;
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

/** Production conversation and transports; only the remote HTTP boundaries are faked. */
class SiteAgentPhantomApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const PHANTOM = 'בוקר טוב ריקי, אני מכין את השינוי בדף הבית (עמוד "login"): להחליף את: "איזה כייף שבאת" ב: "כמה נחמד שבאת" לביצוע השיבו "כן". לביטול — "לא".';

    private const MARKDOWN_PHANTOM = 'בוקר טוב ריקי, תודה על הסבלנות. זיהיתי את העמוד (עמוד הבית, מזהה 43) ואת הטקסט המדויק שמופיע בו: **"איזה כייף שבאת"**. אני מכין את השינוי להחלפת הטקסט ל: **"כמה נחמד שבאת"**. לביצוע השינוי, השיבי **"כן"**. לביטול, השיבי **"לא"**.';

    private array $pages = [];

    private array $siteCalls = [];

    private array $agentRequests = [];

    private array $plannerPrompts = [];

    private array $cacheRequests = [];

    private bool $approvalSent = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.disabled_permissions' => '',
            'siteagent.assistant.cache.enabled' => true,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->pages = [
            11 => ['id' => 11, 'title' => 'login', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת'],
            12 => ['id' => 12, 'title' => 'עמוד אחר', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת'],
        ];
        $this->app->instance(ClaudeClient::class, app(ClaudeClient::class));
    }

    public function test_reported_transcript_repairs_a_model_written_preview_into_a_saved_homepage_change(): void
    {
        $question = 'בוקר טוב ריקי, על מנת שאוכל לעדכן את התוכן בדף הבית, אני צריך לדעת איזה טקסט קיים כרגע ומה הטקסט החדש.';
        $this->fakeRemote(function (int $turn, array $body) use ($question): PromiseInterface {
            if ($turn === 1) {
                $this->assertStringContainsString('לעדכן תוכן בדף הבית', data_get($body, 'contents.0.parts.0.text'));

                // Older deployments asked this in prose instead of parking a
                // planner question; the next message must still be recoverable.
                return $this->modelText($question);
            }
            if ($turn === 2) {
                $prompt = data_get($body, 'contents.0.parts.0.text');
                $this->assertStringContainsString('לעדכן תוכן בדף הבית', $prompt);
                $this->assertStringContainsString($question, $prompt);
                $this->assertStringContainsString('להחליף איזה כייף בכמה נחמד', $prompt);

                return $this->modelText(self::PHANTOM);
            }
            if ($turn === 3) {
                $this->assertSame(self::PHANTOM, data_get($body, 'contents.1.parts.0.text'));
                $this->assertStringContainsString('לא נוצרה במערכת הצעה', data_get($body, 'contents.2.parts.0.text'));

                return $this->editPage('בדף הבית להחליף איזה כייף בכמה נחמד');
            }
            $this->assertSame(4, $turn);
            $this->assertSame('verified-page-edit', data_get($body, 'contents.4.parts.0.functionResponse.id'));

            return $this->modelText('בוצע כבר באתר!');
        });
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $this->assertSame($question, $conversation->handle($subscriber, 'לעדכן תוכן בדף הבית', 'request'));
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertSame([], $this->siteCalls);

        $preview = $conversation->handle($subscriber, 'להחליף איזה כייף בכמה נחמד', 'details');
        $pending = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $pending->state);
        $this->assertSame(SiteAgentRequest::OP_REPLACE, $pending->operation);
        $this->assertSame(11, $pending->plan['page_id']);
        $this->assertSame(['mode' => 'page', 'id' => 11, 'blog_id' => 0], $pending->plan['front_page']);
        $this->assertSame('איזה כייף', $pending->plan['find']);
        $this->assertSame('כמה נחמד', $pending->plan['text']);
        $this->assertStringContainsString('login', $preview);
        $this->assertStringContainsString('איזה כייף', $preview);
        $this->assertStringContainsString('כמה נחמד', $preview);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertStringNotContainsString('בוצע כבר באתר', $preview);
        $this->assertNotSame(self::PHANTOM, $preview);
        $this->assertSame($pending->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertSame(0, SiteAgentMessage::where('role', SiteAgentMessage::ASSISTANT)->where('body', self::PHANTOM)->count());
        $this->assertNotContains('wp_content_update', array_column($this->siteCalls, 0));
        $this->assertSame('איזה כייף שבאת', $this->pages[11]['content']);
        $this->assertCount(1, $this->plannerPrompts);
        $this->assertStringContainsString('[דף הבית המוגדר באתר]', $this->plannerPrompts[0]);
        $this->assertStringContainsString('בדף הבית להחליף איזה כייף בכמה נחמד', $this->plannerPrompts[0]);

        $this->approvalSent = true;
        $this->assertStringContainsString('בוצע', $conversation->handle($subscriber, 'כן', 'approval'));
        $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state);
        $this->assertSame('כמה נחמד שבאת', $this->pages[11]['content']);
        $this->assertSame('איזה כייף שבאת', $this->pages[12]['content']);
        $this->assertCount(1, array_filter($this->siteCalls, fn (array $call): bool => $call[0] === 'wp_content_update'));
        $this->assertCount(4, $this->agentRequests, 'Approval must not call the model again.');
        $this->assertCount(1, $this->cacheRequests, 'Messages and the repair share one stable cached catalog.');
        $this->assertSame('models/gemini-3.1-flash-lite', $this->cacheRequests[0]['model']);
    }

    public function test_a_request_to_prepare_a_proposal_is_repaired_before_the_owner_needs_to_say_yes(): void
    {
        $this->pages = [43 => [
            'id' => 43, 'title' => 'עמוד הבית', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת',
        ]];
        $this->fakeRemote(fn (int $turn): PromiseInterface => match ($turn) {
            1 => $this->modelText('מצאתי את עמוד הבית. האם תרצי שאגיש הצעה לשינוי הברכה ל"כמה נחמד שבאת"?'),
            2 => $this->editPage('בדף הבית להחליף איזה כייף שבאת בכמה נחמד שבאת'),
            default => $this->modelText('הוכנה הצעה.'),
        }, plannedPage: 43, frontPage: 43, replacement: ['find' => 'איזה כייף שבאת', 'text' => 'כמה נחמד שבאת']);
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $reply = $conversation->handle($subscriber, 'בדף הבית החלף את "איזה כייף שבאת" ב"כמה נחמד שבאת".', 'prepare-request');

        $this->assertSame(SiteAgentRequest::AWAITING, SiteAgentRequest::sole()->state);
        $this->assertStringContainsString(SiteAgentConversation::CONFIRM_PROMPT, $reply);
        $this->assertStringNotContainsString('האם תרצי שאגיש', $reply);
        $this->assertSame('איזה כייף שבאת', $this->pages[43]['content']);
        $this->approvalSent = true;
        $this->assertStringContainsString('בוצע', $conversation->handle($subscriber, 'כן', 'prepare-confirm'));
        $this->assertSame('כמה נחמד שבאת', $this->pages[43]['content']);
        $this->assertSame(SiteAgentRequest::APPLIED, SiteAgentRequest::sole()->state);
    }

    public function test_repeated_phantom_preview_is_never_sent_or_saved_as_a_proposal(): void
    {
        $this->fakeRemote(fn (): PromiseInterface => $this->modelText(self::PHANTOM));

        $reply = app(SiteAgentConversation::class)->handle($this->subscriber(), 'בדף הבית להחליף איזה כייף בכמה נחמד', 'request');

        $this->assertSame(SiteAgentAssistant::NO_VERIFIED_PROPOSAL, $reply);
        $this->assertCount(2, $this->agentRequests, 'Only one bounded repair is allowed.');
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertSame([], $this->plannerPrompts);
        $this->assertSame([], $this->siteCalls);
        $this->assertSame([SiteAgentAssistant::NO_VERIFIED_PROPOSAL], SiteAgentMessage::where('role', SiteAgentMessage::ASSISTANT)->pluck('body')->all());
    }

    public function test_reported_feminine_markdown_preview_requires_a_real_offer_before_yes_can_execute(): void
    {
        $message = 'אני רוצה לשנות בדף הבית את המילים איזה כייף שבאת לכמה נחמד שבאת';
        $this->pages = [43 => [
            'id' => 43, 'title' => 'עמוד הבית', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת',
        ]];
        $this->fakeRemote(function (int $turn, array $body) use ($message): PromiseInterface {
            if ($turn === 1) {
                $this->assertStringContainsString($message, data_get($body, 'contents.0.parts.0.text'));

                return $this->modelText(self::MARKDOWN_PHANTOM);
            }
            if ($turn === 2) {
                $this->assertSame(self::MARKDOWN_PHANTOM, data_get($body, 'contents.1.parts.0.text'));
                $this->assertStringContainsString('לא נוצרה במערכת הצעה', data_get($body, 'contents.2.parts.0.text'));

                return $this->editPage($message);
            }
            $this->assertSame(3, $turn);
            $this->assertSame('verified-page-edit', data_get($body, 'contents.4.parts.0.functionResponse.id'));

            return $this->modelText('בוצע, הטקסט בדף הבית עודכן.');
        }, plannedPage: 43, frontPage: 43, replacement: ['find' => 'איזה כייף שבאת', 'text' => 'כמה נחמד שבאת']);
        $subscriber = $this->subscriber();
        $conversation = app(SiteAgentConversation::class);

        $preview = $conversation->handle($subscriber, $message, 'markdown-request');
        $pending = SiteAgentRequest::sole();
        $this->assertSame(SiteAgentRequest::AWAITING, $pending->state);
        $this->assertSame(43, $pending->plan['page_id']);
        $this->assertSame(['mode' => 'page', 'id' => 43, 'blog_id' => 0], $pending->plan['front_page']);
        $this->assertSame('איזה כייף שבאת', $pending->plan['find']);
        $this->assertSame('כמה נחמד שבאת', $pending->plan['text']);
        $this->assertSame($pending->preview."\n\n".SiteAgentConversation::CONFIRM_PROMPT, $preview);
        $this->assertStringContainsString('איזה כייף שבאת', $preview);
        $this->assertStringContainsString('כמה נחמד שבאת', $preview);
        $this->assertStringNotContainsString('בוצע', $preview);
        $this->assertSame(0, SiteAgentMessage::where('role', SiteAgentMessage::ASSISTANT)->where('body', self::MARKDOWN_PHANTOM)->count());
        $this->assertNotContains('wp_content_update', array_column($this->siteCalls, 0));
        $this->assertSame('איזה כייף שבאת', $this->pages[43]['content']);

        $this->approvalSent = true;
        $this->assertStringContainsString('בוצע', $conversation->handle($subscriber, 'כן', 'markdown-approval'));
        $this->assertSame('כמה נחמד שבאת', $this->pages[43]['content']);
        $this->assertSame(SiteAgentRequest::APPLIED, $pending->fresh()->state);
        $this->assertCount(1, array_filter($this->siteCalls, fn (array $call): bool => $call[0] === 'wp_content_update'));
        $this->assertCount(3, $this->agentRequests);
        $this->assertCount(1, $this->plannerPrompts);
        $this->assertCount(1, $this->cacheRequests);
    }

    #[DataProvider('legacyPhantomReplies')]
    public function test_yes_to_a_legacy_phantom_preview_does_not_call_ai_or_the_site(string $phantom): void
    {
        $subscriber = $this->subscriber();
        SiteAgentMessage::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id,
            'role' => SiteAgentMessage::ASSISTANT, 'body' => $phantom,
        ]);
        Http::fake();

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'כן', 'legacy-approval');

        $this->assertSame(SiteAgentConversation::NO_PENDING_PROPOSAL, $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        Http::assertNothingSent();
    }

    public static function legacyPhantomReplies(): array
    {
        return ['plural plain reply' => [self::PHANTOM], 'feminine bold reply' => [self::MARKDOWN_PHANTOM]];
    }

    public function test_explicit_homepage_target_survives_model_rewriting_it_as_a_wrong_page_title(): void
    {
        $this->pages[11]['title'] = 'דף ראשי';
        $this->pages[12]['title'] = 'login';
        $this->fakeRemote(fn (int $turn): PromiseInterface => $turn === 1
            ? $this->editPage('בעמוד login להחליף איזה כייף בכמה נחמד')
            : $this->modelText('ההצעה מוכנה, השיבו כן לביצוע.'), 12);

        $reply = app(SiteAgentConversation::class)->handle($this->subscriber(), 'בדף הבית להחליף איזה כייף בכמה נחמד', 'request');

        $this->assertStringContainsString('אינו דף הבית', $reply);
        $this->assertSame(0, SiteAgentRequest::count());
        $this->assertCount(1, $this->plannerPrompts);
        $this->assertStringContainsString('בדף הבית להחליף איזה כייף בכמה נחמד', $this->plannerPrompts[0]);
        $this->assertStringContainsString('בעמוד login להחליף איזה כייף בכמה נחמד', $this->plannerPrompts[0]);
        $this->assertContains('wp_site_settings_get', array_column($this->siteCalls, 0));
        $this->assertNotContains('wp_content_update', array_column($this->siteCalls, 0));
        $this->assertSame('איזה כייף שבאת', $this->pages[11]['content']);
        $this->assertSame('איזה כייף שבאת', $this->pages[12]['content']);
    }

    /** Fake Gemini and the WordPress wire without replacing any application service. */
    private function fakeRemote(Closure $agentReply, int $plannedPage = 11, int $frontPage = 11, array $replacement = []): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/cachedContents' => function (Request $request): PromiseInterface {
                $body = $request->data();
                $this->cacheRequests[] = $body;
                $this->assertContains('edit_page_text', array_column($body['tools'][0]['functionDeclarations'], 'name'));

                return Http::response([
                    'name' => 'cachedContents/phantom-regression', 'model' => 'models/gemini-3.1-flash-lite',
                    'expireTime' => now()->addHour()->toIso8601String(),
                    'usageMetadata' => ['totalTokenCount' => 12000],
                ]);
            },
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => function (Request $request) use ($agentReply, $plannedPage, $replacement): PromiseInterface {
                $body = $request->data();
                if (isset($body['generationConfig']['responseSchema'])) {
                    $this->plannerPrompts[] = data_get($body, 'contents.0.parts.0.text');

                    return $this->modelText(json_encode([
                        'can_do' => true, 'operation' => 'replace_text', 'page_id' => $plannedPage,
                        'find' => 'איזה כייף', 'text' => 'כמה נחמד', 'summary' => 'עדכון ברכת הפתיחה',
                        ...$replacement,
                    ], JSON_UNESCAPED_UNICODE));
                }
                $this->agentRequests[] = $body;
                $this->assertSame('cachedContents/phantom-regression', $body['cachedContent'] ?? null);
                $this->assertArrayNotHasKey('tools', $body);
                $this->assertArrayNotHasKey('systemInstruction', $body);

                return $agentReply(count($this->agentRequests), $body);
            },
            'site.test/*' => function (Request $request) use ($frontPage): PromiseInterface {
                $body = $request->data();
                $this->assertSame('tools/call', $body['method']);
                $tool = $body['params']['name'];
                $arguments = (array) $body['params']['arguments'];
                $this->siteCalls[] = [$tool, $arguments];
                if ($tool === 'wp_content_update') {
                    $this->assertTrue($this->approvalSent, 'No write is allowed before a separate approval.');
                    $this->pages[$arguments['id']]['content'] = $arguments['content'];
                    $result = ['updated_id' => $arguments['id']];
                } else {
                    $result = match ($tool) {
                        'wp_site_settings_get' => ['values' => ['show_on_front' => 'page', 'page_on_front' => $frontPage, 'page_for_posts' => 0]],
                        'wp_content_list' => array_values($this->pages),
                        'wp_content_get' => $this->pages[$arguments['id']] ?? [],
                        default => throw new \RuntimeException('Unexpected site tool: '.$tool),
                    };
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]], 'isError' => false,
                ]]);
            },
        ]);
    }

    private function modelText(string $text): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    private function editPage(string $instruction): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [[
            'functionCall' => ['id' => 'verified-page-edit', 'name' => 'edit_page_text', 'args' => ['instruction' => $instruction]],
            'thoughtSignature' => 'test-signature',
        ]]]]]]);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'domain' => 'site.test', 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'test-site-secret',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(
                fn (string $name): array => ['name' => $name],
                array_unique([...SiteAgentToolbox::pluginTools(), ...app(SiteActionProposer::class)->pluginTools()]),
            )],
        ]);

        return SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
    }
}
