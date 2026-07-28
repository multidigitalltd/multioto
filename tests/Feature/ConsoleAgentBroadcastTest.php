<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Services\Agent\ConsoleAgent;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * The console agent can prepare a broadcast for the owner.
 *
 * It drafts and stops there: a broadcast reaches every customer at once and
 * cannot be recalled, so the send stays a deliberate human press behind a
 * confirmation naming the recipient count.
 */
class ConsoleAgentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drive the agent's tool loop with a scripted model: it calls
     * draft_broadcast with $input, then answers with a closing line.
     */
    private function runWith(array $input): array
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler) use ($input): string {
                $result = $handler('draft_broadcast', $input);

                return (string) $result['content'];
            },
        );

        $agent = new ConsoleAgent($ai, app(ApprovalGate::class));

        return $agent->run('תודיע לכל הלקוחות על עדכון אבטחה');
    }

    public function test_the_agent_writes_the_text_and_leaves_a_draft_nobody_received(): void
    {
        Mail::fake();

        Customer::factory()->count(3)->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $result = $this->runWith([
            'subject' => 'עדכון אבטחה בשרתים',
            'body' => 'שלום {{שם}}, ביצענו עדכון אבטחה בשרתים. לא נדרשת פעולה מצדך.',
            'is_marketing' => false,
        ]);

        $broadcast = Broadcast::sole();

        $this->assertSame('עדכון אבטחה בשרתים', $broadcast->subject);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
        $this->assertSame(BroadcastChannel::Email, $broadcast->channel);
        $this->assertFalse($broadcast->is_marketing);

        // The whole point: preparing a broadcast messages nobody.
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // The reply tells the owner where to find it and how many it would reach.
        $this->assertStringContainsString('3 לקוחות', $result['summary']);
        $this->assertStringContainsString('דיוורים', $result['summary']);
    }

    public function test_a_draft_is_not_something_the_owner_has_to_approve(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $result = $this->runWith(['subject' => 'נושא', 'body' => 'תוכן']);

        // Approving text that reaches nobody would be ceremony; the real
        // decision is the send, and that already has a human in front of it.
        $this->assertSame([], $result['proposed']);
        $this->assertSame(1, Broadcast::count());
    }

    public function test_the_agent_writes_the_wording_itself_from_a_one_line_brief(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()->andReturn([
            'subject' => 'תחזוקה מתוכננת בשבת',
            'body' => 'שלום {{שם}}, בשבת בין 02:00 ל-05:00 נבצע תחזוקה.',
        ]);
        $ai->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler): string {
                return (string) $handler('draft_broadcast', [
                    'brief' => 'תחזוקה בשבת בלילה בין 2 ל-5',
                ])['content'];
            },
        );

        // The composer is resolved from the container inside the tool.
        $this->app->instance(ClaudeClient::class, $ai);

        (new ConsoleAgent($ai, app(ApprovalGate::class)))
            ->run('תודיע ללקוחות על התחזוקה');

        $broadcast = Broadcast::sole();

        $this->assertSame('תחזוקה מתוכננת בשבת', $broadcast->subject);
        $this->assertStringContainsString('{{שם}}', $broadcast->body);
    }

    public function test_a_brief_that_produces_nothing_does_not_leave_an_empty_draft(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn(null);
        $ai->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler): string {
                $result = $handler('draft_broadcast', ['brief' => 'משהו']);

                return (string) $result['content'];
            },
        );

        $this->app->instance(ClaudeClient::class, $ai);

        (new ConsoleAgent($ai, app(ApprovalGate::class)))->run('תדוור');

        $this->assertSame(0, Broadcast::count());
    }

    public function test_the_default_audience_is_active_customers_and_an_unknown_status_falls_back(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Churned, 'email' => 'b@b.co.il']);

        $this->runWith(['subject' => 'נושא', 'body' => 'תוכן', 'audience_status' => 'לא-קיים']);

        // A typo must not silently widen the audience to everyone.
        $this->assertSame(['status' => CustomerStatus::Active->value], Broadcast::sole()->segment);
    }

    public function test_the_agent_can_widen_the_audience_to_every_customer_when_asked(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Churned, 'email' => 'b@b.co.il']);

        $result = $this->runWith(['subject' => 'נושא', 'body' => 'תוכן', 'audience_status' => 'all']);

        $this->assertSame(['status' => 'all'], Broadcast::sole()->segment);
        $this->assertStringContainsString('2 לקוחות', $result['summary']);
    }

    public function test_a_marketing_draft_is_marked_as_advertising(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        $result = $this->runWith(['subject' => 'מבצע', 'body' => 'תוכן', 'is_marketing' => true]);

        $this->assertTrue(Broadcast::sole()->is_marketing);
        $this->assertStringContainsString('פרסומי', $result['summary']);
    }
}
