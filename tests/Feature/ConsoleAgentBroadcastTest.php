<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
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

    public function test_an_unstated_classification_defaults_to_advertising_not_service(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        $this->runWith(['subject' => 'מבצע לחג', 'body' => 'תוכן']);

        // The two mistakes are not symmetrical: a service notice labelled
        // advertising is odd; advertising labelled service loses the "פרסומת"
        // heading and the opt-out link and reaches people who opted out.
        $this->assertTrue(Broadcast::sole()->is_marketing);
    }

    public function test_an_opted_out_customer_is_excluded_from_the_count_of_an_unclassified_draft(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'b@b.co.il', 'marketing_opt_out_at' => now(),
        ]);

        $result = $this->runWith(['subject' => 'מבצע', 'body' => 'תוכן']);

        $this->assertStringContainsString('1 לקוחות', $result['summary']);
    }

    public function test_a_request_for_one_plan_narrows_the_draft_to_that_plan(): void
    {
        $wanted = Plan::factory()->create(['name' => 'תחזוקה פלוס']);
        $other = Plan::factory()->create(['name' => 'בסיסי']);

        $in = Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'in@b.co.il']);
        $out = Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'out@b.co.il']);

        Subscription::factory()->create(['customer_id' => $in->id, 'plan_id' => $wanted->id]);
        Subscription::factory()->create(['customer_id' => $out->id, 'plan_id' => $other->id]);

        $result = $this->runWith([
            'subject' => 'נושא', 'body' => 'תוכן', 'is_marketing' => false,
            'plan_names' => ['תחזוקה פלוס'],
        ]);

        $this->assertSame([$wanted->id], Broadcast::sole()->segment['plan_ids']);
        $this->assertStringContainsString('1 לקוחות', $result['summary']);
    }

    public function test_a_plan_name_that_matches_nothing_refuses_rather_than_mailing_everyone(): void
    {
        Plan::factory()->create(['name' => 'תחזוקה פלוס']);
        Customer::factory()->count(5)->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        $result = $this->runWith([
            'subject' => 'נושא', 'body' => 'תוכן', 'is_marketing' => false,
            'plan_names' => ['חבילה שלא קיימת'],
        ]);

        // Silently dropping the filter would hand back a draft aimed at all
        // five — the one direction that cannot be undone once sent.
        $this->assertSame(0, Broadcast::count());
        $this->assertStringContainsString('תחזוקה פלוס', $result['summary']);
    }

    public function test_one_mistyped_plan_among_several_refuses_instead_of_halving_the_audience(): void
    {
        $wanted = Plan::factory()->create(['name' => 'תחזוקה פלוס']);
        Plan::factory()->create(['name' => 'בסיסי']);

        $customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Subscription::factory()->create(['customer_id' => $customer->id, 'plan_id' => $wanted->id]);

        $result = $this->runWith([
            'subject' => 'נושא', 'body' => 'תוכן', 'is_marketing' => false,
            'plan_names' => ['תחזוקה פלוס', 'חבילה שלא קיימת'],
        ]);

        // Matching only the first would drop half the intended audience and
        // still report success.
        $this->assertSame(0, Broadcast::count());
        $this->assertStringContainsString('חבילה שלא קיימת', $result['summary']);
    }

    public function test_a_named_customer_list_narrows_the_draft(): void
    {
        $chosen = Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Customer::factory()->count(4)->create(['status' => CustomerStatus::Active, 'email' => 'b@b.co.il']);

        $result = $this->runWith([
            'subject' => 'נושא', 'body' => 'תוכן', 'is_marketing' => false,
            'customer_ids' => [$chosen->id],
        ]);

        $this->assertSame([$chosen->id], Broadcast::sole()->segment['customer_ids']);
        $this->assertStringContainsString('1 לקוחות', $result['summary']);
    }

    public function test_customer_ids_that_match_nothing_refuse_rather_than_widening(): void
    {
        Customer::factory()->count(3)->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        $this->runWith([
            'subject' => 'נושא', 'body' => 'תוכן', 'is_marketing' => false,
            'customer_ids' => [9991, 9992],
        ]);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_an_over_long_subject_is_trimmed_rather_than_losing_the_whole_draft(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        // broadcasts.subject is varchar(255): letting this reach Postgres would
        // reject the insert and lose the wording along with it.
        $this->runWith([
            'subject' => str_repeat('א', 400),
            'body' => 'תוכן',
            'is_marketing' => false,
        ]);

        $broadcast = Broadcast::sole();

        $this->assertLessThanOrEqual(255, mb_strlen($broadcast->subject));
        $this->assertSame('תוכן', $broadcast->body);
    }

    public function test_a_marketing_draft_is_marked_as_advertising(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);

        $result = $this->runWith(['subject' => 'מבצע', 'body' => 'תוכן', 'is_marketing' => true]);

        $this->assertTrue(Broadcast::sole()->is_marketing);
        $this->assertStringContainsString('פרסומי', $result['summary']);
    }
}
