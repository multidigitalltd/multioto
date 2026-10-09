<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteChangeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentHomepageContextTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = ['show_on_front' => 'page', 'page_on_front' => 999, 'page_for_posts' => 0];

    private array $calls = [];

    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->posts = [
            999 => ['id' => 999, 'title' => 'login', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת'],
            7 => ['id' => 7, 'title' => 'דף הבית', 'type' => 'page', 'status' => 'publish', 'content' => 'איזה כייף שבאת'],
        ];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->calls[] = [$tool, $args];
            if ($tool === 'wp_content_update') {
                $this->posts[$args['id']] = [...$this->posts[$args['id']], ...array_diff_key($args, ['id' => true])];

                return ['updated_id' => $args['id']];
            }

            return match ($tool) {
                'wp_site_settings_get' => ['values' => $this->settings],
                'wp_content_get' => $this->posts[$args['id']] ?? [],
                default => throw new \RuntimeException('Unexpected tool: '.$tool),
            };
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);
    }

    #[DataProvider('pageProposals')]
    public function test_a_seen_page_named_home_cannot_replace_the_actual_homepage(string $tool, array $input): void
    {
        $subscriber = $this->subscriber();
        $result = app(SiteActionProposer::class)->propose($subscriber->site, $tool, [...$input, 'id' => 7], [7], 'לעדכן את דף הבית');

        $this->assertStringContainsString('אינו דף הבית', $result['error'] ?? '');
        $this->assertArrayNotHasKey('plan', $result);
        $this->assertSame([['wp_site_settings_get', []]], $this->calls);
    }

    #[DataProvider('pageProposals')]
    public function test_a_page_named_login_can_be_the_verified_homepage(string $tool, array $input): void
    {
        $subscriber = $this->subscriber();
        $result = app(SiteActionProposer::class)->propose($subscriber->site, $tool, [...$input, 'id' => 999], [999], 'לעדכן את דף הבית');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(['mode' => 'page', 'id' => 999, 'blog_id' => 0], $result['plan']['front_page'] ?? null);
        $this->assertStringContainsString('login', $result['preview']);
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
    }

    #[DataProvider('pageProposals')]
    public function test_generic_homepage_proposals_refuse_if_the_homepage_moved_before_approval(string $tool, array $input): void
    {
        $subscriber = $this->subscriber();
        $offer = app(SiteActionProposer::class)->propose($subscriber->site, $tool, [...$input, 'id' => 999], [999], 'לעדכן את דף הבית');
        $request = $this->request($subscriber, $offer);
        $this->settings['page_on_front'] = 7;

        $result = app(SiteChangeApplier::class)->apply($request);

        $this->assertFalse($result['ok']);
        $this->assertSame(SiteChangeApplier::STALE, $result['reason']);
        $this->assertNotContains('wp_content_update', array_column($this->calls, 0));
    }

    #[DataProvider('pageProposals')]
    public function test_a_verified_generic_homepage_proposal_can_apply_without_touching_another_page(string $tool, array $input): void
    {
        $subscriber = $this->subscriber();
        $offer = app(SiteActionProposer::class)->propose($subscriber->site, $tool, [...$input, 'id' => 999], [999], 'לעדכן את דף הבית');

        $result = app(SiteChangeApplier::class)->apply($this->request($subscriber, $offer));

        $this->assertTrue($result['ok']);
        $this->assertSame('איזה כייף שבאת', $this->posts[7]['content']);
        $this->assertSame('דף הבית', $this->posts[7]['title']);
        $this->assertSame($tool === 'propose_text_edit' ? 'כמה נחמד שבאת' : 'איזה כייף שבאת', $this->posts[999]['content']);
        $this->assertSame($tool === 'propose_post_update' ? 'ברוכים הבאים' : 'login', $this->posts[999]['title']);
        $this->assertCount(2, array_filter($this->calls, fn (array $call): bool => $call[0] === 'wp_site_settings_get'));
    }

    public static function pageProposals(): array
    {
        return [
            'text' => ['propose_text_edit', ['action' => 'replace', 'find' => 'איזה כייף', 'text' => 'כמה נחמד']],
            'title' => ['propose_post_update', ['title' => 'ברוכים הבאים']],
        ];
    }

    public function test_blog_index_is_not_guessed_from_a_page_title(): void
    {
        $this->settings['show_on_front'] = 'posts';
        $subscriber = $this->subscriber();
        $result = app(SiteActionProposer::class)->propose($subscriber->site, 'propose_text_edit',
            ['id' => 7, 'action' => 'replace', 'find' => 'איזה כייף', 'text' => 'כמה נחמד'], [7], 'להחליף טקסט בדף הבית');

        $this->assertStringContainsString('רשימת הפוסטים', $result['error'] ?? '');
        $this->assertArrayNotHasKey('plan', $result);
    }

    public function test_absent_homepage_settings_refuse_instead_of_choosing_a_named_page(): void
    {
        $subscriber = $this->subscriber();
        $subscriber->site->update(['mcp_capabilities' => ['tools' => [['name' => 'wp_content_get'], ['name' => 'wp_content_update']]]]);
        $result = app(SiteActionProposer::class)->propose($subscriber->site, 'propose_text_edit',
            ['id' => 7, 'action' => 'replace', 'find' => 'איזה כייף', 'text' => 'כמה נחמד'], [7], 'להחליף טקסט בדף הבית');

        $this->assertStringContainsString('לא הצלחתי לוודא', $result['error'] ?? '');
        $this->assertArrayNotHasKey('plan', $result);
        $this->assertSame([], $this->calls);
    }

    public function test_a_current_named_page_request_does_not_gain_a_homepage_constraint(): void
    {
        $subscriber = $this->subscriber();
        $result = app(SiteActionProposer::class)->propose($subscriber->site, 'propose_text_edit',
            ['id' => 999, 'action' => 'replace', 'find' => 'איזה כייף', 'text' => 'כמה נחמד'], [999], 'בעמוד login להחליף איזה כייף בכמה נחמד');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayNotHasKey('front_page', $result['plan']);
        $this->assertNotContains('wp_site_settings_get', array_column($this->calls, 0));
    }

    public function test_legacy_callers_can_still_propose_a_named_page_edit(): void
    {
        $subscriber = $this->subscriber();
        $result = app(SiteActionProposer::class)->propose($subscriber->site, 'propose_text_edit',
            ['id' => 999, 'action' => 'replace', 'find' => 'איזה כייף', 'text' => 'כמה נחמד'], [999]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayNotHasKey('front_page', $result['plan']);
    }

    private function request(SiteAgentSubscriber $subscriber, array $offer): SiteAgentRequest
    {
        return SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id, 'customer_id' => $subscriber->customer_id,
            'site_id' => $subscriber->site_id, 'operation' => $offer['plan']['operation'],
            'plan' => $offer['plan'], 'preview' => $offer['preview'], 'message' => 'לעדכן את דף הבית',
            'state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'mcp_enabled' => true]);

        return SiteAgentSubscriber::create(['customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now()]);
    }
}
