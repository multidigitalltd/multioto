<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentDraftContentTest extends TestCase
{
    use RefreshDatabase;

    private array $post;

    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['siteagent.assistant.enabled' => false]);
        $this->post = ['id' => 46, 'title' => 'חדשות החברה', 'content' => 'העדכונים האחרונים של החברה.',
            'status' => 'draft', 'built_with_elementor' => false];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame(46, $args['id']);
            if ($tool === 'wp_content_get') {
                return $this->post;
            }
            $this->assertSame('wp_content_update', $tool);
            $this->assertArrayNotHasKey('status', $args, 'Text edits must never publish or reschedule a post.');
            $this->assertArrayNotHasKey('publish_at', $args);
            $this->writes[] = $args;
            $previous = array_intersect_key($this->post, $args);
            $this->post = [...$this->post, ...$args];

            return ['updated_id' => 46, 'previous' => $previous];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);
    }

    #[DataProvider('editableStates')]
    public function test_explicit_content_edit_preserves_status_through_confirmation_and_undo(string $status, string $action): void
    {
        $this->post['status'] = $status;
        [$subscriber, $request] = $this->proposal($action);
        $this->assertSame($status, $request->plan['page_status']);
        $this->assertSame([], $this->writes);
        if ($status !== 'publish') {
            $this->assertStringContainsString('העריכה אינה משנה את מצב הפרסום', $request->preview);
        }

        $conversation = app(SiteAgentConversation::class);
        $this->assertStringContainsString('בוצע', $conversation->handle($subscriber, 'כן', 'approve-draft'));
        $this->assertSame($status, $this->post['status']);
        $this->assertSame($action === 'append'
            ? "העדכונים האחרונים של החברה.\n\nפרטים נוספים יפורסמו בקרוב"
            : 'העדכונים החדשים של החברה.', $this->post['content']);
        $this->assertSame(SiteAgentRequest::APPLIED, $request->refresh()->state);

        $this->assertStringContainsString('הוחזר', $conversation->handle($subscriber, 'בטל', 'undo-draft'));
        $this->assertSame('העדכונים האחרונים של החברה.', $this->post['content']);
        $this->assertSame($status, $this->post['status']);
        $this->assertSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
        $this->assertCount(2, $this->writes);
    }

    public static function editableStates(): array
    {
        $cases = [];
        foreach (['publish', 'draft', 'pending', 'private', 'future'] as $status) {
            foreach (['append', 'replace'] as $action) {
                $cases[$status.' '.$action] = [$status, $action];
            }
        }

        return $cases;
    }

    public function test_publication_after_preview_blocks_the_old_draft_proposal(): void
    {
        [$subscriber, $request] = $this->proposal('append');
        $this->post['status'] = 'publish';

        $reply = app(SiteAgentConversation::class)->handle($subscriber, 'כן', 'stale-draft');

        $this->assertStringContainsString('השתנה', $reply);
        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertSame([], $this->writes);
        $this->assertSame('העדכונים האחרונים של החברה.', $this->post['content']);
    }

    public function test_publication_after_edit_blocks_undo_of_the_old_draft(): void
    {
        [$subscriber, $request] = $this->proposal('append');
        $conversation = app(SiteAgentConversation::class);
        $conversation->handle($subscriber, 'כן', 'approved-draft');
        $this->post['status'] = 'publish';
        $after = $this->post['content'];

        $reply = $conversation->handle($subscriber, 'בטל', 'stale-draft-undo');

        $this->assertStringContainsString('לא החזרתי', $reply);
        $this->assertSame($after, $this->post['content']);
        $this->assertSame('publish', $this->post['status']);
        $this->assertCount(1, $this->writes);
        $this->assertNotSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
    }

    #[DataProvider('invalidContentIdentities')]
    public function test_wrong_or_missing_read_identity_cannot_produce_a_preview(?int $reportedId): void
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $this->post['id'] = $reportedId;
        $offer = app(SiteActionProposer::class)->propose($site, 'propose_text_edit',
            ['id' => 46, 'action' => 'append', 'text' => 'הודעה חדשה'], [46]);

        $this->assertArrayHasKey('error', $offer);
        $this->assertSame([], $this->writes);
    }

    #[DataProvider('invalidContentIdentities')]
    public function test_wrong_or_missing_read_identity_cannot_apply_or_undo(?int $reportedId): void
    {
        [$subscriber, $request] = $this->proposal('append');
        $conversation = app(SiteAgentConversation::class);
        $this->post['id'] = $reportedId;
        $reply = $conversation->handle($subscriber, 'כן', 'wrong-id-approval');
        $this->assertStringContainsString('לא הצלחתי לקרוא', $reply);
        $this->assertSame(SiteAgentRequest::FAILED, $request->refresh()->state);
        $this->assertSame([], $this->writes);

        $this->post['id'] = 46;
        $request->update(['state' => SiteAgentRequest::AWAITING]);
        $conversation->handle($subscriber, 'כן', 'correct-id-approval');
        $this->assertCount(1, $this->writes);
        $this->post['id'] = $reportedId;
        $conversation->handle($subscriber, 'בטל', 'wrong-id-undo');
        $this->assertCount(1, $this->writes);
        $this->assertNotSame(SiteAgentRequest::REVERTED, $request->refresh()->state);
    }

    public static function invalidContentIdentities(): array
    {
        return ['another post' => [47], 'missing identifier' => [null]];
    }

    public function test_trash_and_auto_drafts_remain_ineligible(): void
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        foreach (['trash', 'auto-draft', 'inherit', 'unknown'] as $status) {
            $this->post['status'] = $status;
            $offer = app(SiteActionProposer::class)->propose($site, 'propose_text_edit',
                ['id' => 46, 'action' => 'append', 'text' => 'הודעה חדשה'], [46]);
            $this->assertArrayHasKey('error', $offer);
        }
        $this->assertSame([], $this->writes);
    }

    private function proposal(string $action): array
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);
        $offer = app(SiteActionProposer::class)->propose($site, 'propose_text_edit', [
            'id' => 46, 'action' => $action, 'find' => 'האחרונים',
            'text' => $action === 'append' ? 'פרטים נוספים יפורסמו בקרוב' : 'החדשים',
        ], [46], 'עדכן את התוכן של חדשות החברה בלי לפרסם אותו');
        $this->assertArrayNotHasKey('error', $offer);
        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $site->id,
            'customer_id' => $customer->id, 'message' => 'עדכן תוכן בלי לשנות את מצב הפרסום',
            'operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview'],
            'state' => SiteAgentRequest::AWAITING, 'expires_at' => now()->addMinutes(30),
        ]);

        return [$subscriber, $request];
    }
}
