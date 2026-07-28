<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Enums\UserRole;
use App\Filament\Resources\BroadcastResource\Pages\CreateBroadcast;
use App\Filament\Resources\BroadcastResource\Pages\EditBroadcast;
use App\Filament\Resources\BroadcastResource\Pages\ListBroadcasts;
use App\Jobs\SendBroadcastJob;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Support\BroadcastAudience;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A broadcast reaches every customer at once and cannot be recalled, so the
 * count shown before sending must be exactly the set that receives it, and no
 * customer may ever receive the same broadcast twice.
 */
class BroadcastAudienceTest extends TestCase
{
    use RefreshDatabase;

    private function audience(): BroadcastAudience
    {
        return app(BroadcastAudience::class);
    }

    public function test_an_empty_segment_means_every_active_customer(): void
    {
        Customer::factory()->count(3)->create(['status' => CustomerStatus::Active, 'email' => 'a@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Churned, 'email' => 'gone@b.co.il']);

        $this->assertSame(3, $this->audience()->query(null)->count());
    }

    public function test_the_all_status_reaches_suspended_and_churned_customers_too(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active]);
        Customer::factory()->create(['status' => CustomerStatus::Suspended]);
        Customer::factory()->create(['status' => CustomerStatus::Churned]);

        $this->assertSame(3, $this->audience()->query(['status' => 'all'])->count());
        $this->assertSame(1, $this->audience()->query(['status' => 'active'])->count());
    }

    public function test_a_customer_without_an_address_is_counted_as_unreachable_not_as_a_recipient(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'has@mail.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => null]);
        // An empty string is not an address either — a NOT NULL check alone
        // would count this one and make every send look partly failed.
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => '']);

        $summary = $this->audience()->summary(BroadcastChannel::Email, null);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['reachable']);
        $this->assertSame(2, $summary['unreachable']);
    }

    public function test_whatsapp_reach_accepts_either_a_jid_or_a_plain_phone(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'whatsapp_jid' => '972500000001@c.us', 'phone' => null]);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'whatsapp_jid' => null, 'phone' => '0501234567']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'whatsapp_jid' => null, 'phone' => null]);

        $summary = $this->audience()->summary(BroadcastChannel::Whatsapp, null);

        $this->assertSame(2, $summary['reachable']);
        $this->assertSame(1, $summary['unreachable']);
    }

    public function test_a_plan_filter_narrows_the_segment_to_subscribers_of_those_plans(): void
    {
        $wanted = Plan::factory()->create();
        $other = Plan::factory()->create();

        $in = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $out = Customer::factory()->create(['status' => CustomerStatus::Active]);

        Subscription::factory()->create(['customer_id' => $in->id, 'plan_id' => $wanted->id]);
        Subscription::factory()->create(['customer_id' => $out->id, 'plan_id' => $other->id]);

        $ids = $this->audience()->query(['plan_ids' => [$wanted->id]])->pluck('id')->all();

        $this->assertSame([$in->id], $ids);
    }

    public function test_a_named_customer_list_wins_over_the_wider_segment(): void
    {
        $chosen = Customer::factory()->create(['status' => CustomerStatus::Active]);
        Customer::factory()->count(4)->create(['status' => CustomerStatus::Active]);

        $ids = $this->audience()->query(['customer_ids' => [$chosen->id]])->pluck('id')->all();

        $this->assertSame([$chosen->id], $ids);
    }

    public function test_the_send_job_mails_only_the_reachable_segment(): void
    {
        Mail::fake();

        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'one@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'two@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => null]);
        Customer::factory()->create(['status' => CustomerStatus::Churned, 'email' => 'churned@b.co.il']);

        $broadcast = Broadcast::create([
            'subject' => 'עדכון מערכת',
            'body' => 'שלום, יש לנו עדכון.',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Draft,
        ]);

        $this->runSend($broadcast);

        Mail::assertQueued(BroadcastMail::class, 2);
        Mail::assertNotQueued(BroadcastMail::class, fn (BroadcastMail $m) => $m->hasTo('churned@b.co.il'));

        $this->assertSame(BroadcastStatus::Sent, $broadcast->fresh()->status);
        $this->assertSame(2, $broadcast->fresh()->sent_count);
    }

    public function test_a_second_run_of_the_same_broadcast_never_mails_anyone_twice(): void
    {
        Mail::fake();

        Customer::factory()->count(2)->create(['status' => CustomerStatus::Active, 'email' => 'dup@b.co.il']);

        $broadcast = Broadcast::create([
            'subject' => 'עדכון',
            'body' => 'תוכן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Scheduled,
            'scheduled_at' => now(),
        ]);

        // "שלח עכשיו" dispatches directly while the five-minute scheduler picks
        // up the same scheduled row — the classic double-send race.
        $this->runSend($broadcast);
        $this->runSend($broadcast);

        Mail::assertQueued(BroadcastMail::class, 2);
        $this->assertSame(2, $broadcast->fresh()->sent_count);
    }

    public function test_a_broadcast_already_marked_sent_is_not_resent(): void
    {
        Mail::fake();

        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $broadcast = Broadcast::create([
            'subject' => 'ישן',
            'body' => 'תוכן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Sent,
            'sent_count' => 7,
        ]);

        $this->runSend($broadcast);

        Mail::assertNothingQueued();
        $this->assertSame(7, $broadcast->fresh()->sent_count);
    }

    public function test_the_panel_screen_lists_broadcasts_and_can_queue_a_send(): void
    {
        Mail::fake();
        Bus::fake([SendBroadcastJob::class]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Customer::factory()->count(2)->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $broadcast = Broadcast::create([
            'subject' => 'עדכון',
            'body' => 'תוכן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Draft,
        ]);

        Livewire::test(ListBroadcasts::class)
            ->assertOk()
            ->assertCountTableRecords(1)
            ->callTableAction('sendNow', $broadcast);

        // The action only queues the job; the claim and the send happen there.
        Bus::assertDispatched(SendBroadcastJob::class);
        $this->assertSame(BroadcastStatus::Scheduled, $broadcast->fresh()->status);
    }

    public function test_a_whitespace_only_address_is_not_counted_as_a_recipient(): void
    {
        // The send job trims and skips these, so counting them here would
        // promise deliveries that never happen.
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => '   ']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'real@b.co.il']);

        $summary = $this->audience()->summary(BroadcastChannel::Email, null);

        $this->assertSame(1, $summary['reachable']);
        $this->assertSame(1, $summary['unreachable']);
    }

    public function test_a_broadcast_scheduled_at_creation_time_is_actually_dispatchable(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $at = now()->addDay();

        Livewire::test(CreateBroadcast::class)
            ->fillForm([
                'subject' => 'עדכון מתוזמן',
                'body' => 'תוכן',
                'channel' => BroadcastChannel::Email->value,
                'scheduled_at' => $at,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The scheduler only picks up "scheduled" rows; a create screen that
        // left the column default would mean the broadcast never goes out.
        $this->assertSame(BroadcastStatus::Scheduled, Broadcast::sole()->status);
    }

    public function test_clearing_the_send_time_returns_a_broadcast_to_draft(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $broadcast = Broadcast::create([
            'subject' => 'עדכון',
            'body' => 'תוכן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
        ]);

        Livewire::test(EditBroadcast::class, ['record' => $broadcast->getKey()])
            ->fillForm(['scheduled_at' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_sending_from_the_edit_screen_uses_the_text_on_screen_not_the_last_save(): void
    {
        Mail::fake();
        Bus::fake([SendBroadcastJob::class]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'x@b.co.il']);

        $broadcast = Broadcast::create([
            'subject' => 'נוסח ישן',
            'body' => 'תוכן ישן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Draft,
        ]);

        Livewire::test(EditBroadcast::class, ['record' => $broadcast->getKey()])
            ->fillForm(['subject' => 'נוסח חדש', 'body' => 'תוכן חדש'])
            // No save() — the operator edits and presses send straight away.
            ->callAction('sendNow');

        $this->assertSame('נוסח חדש', $broadcast->fresh()->subject);
        $this->assertSame('תוכן חדש', $broadcast->fresh()->body);
        Bus::assertDispatched(SendBroadcastJob::class);
    }

    private function runSend(Broadcast $broadcast): void
    {
        (new SendBroadcastJob($broadcast->id))->handle(app(WahaClient::class), $this->audience());
    }
}
