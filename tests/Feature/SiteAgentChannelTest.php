<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SiteAgentSubscriberResource\Pages\ListSiteAgentSubscribers;
use App\Jobs\HandleSiteAgentMessageJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * סוכן האתר — stage one: the channel and who is on the other end of it.
 *
 * The product lets a customer rewrite their own website from WhatsApp. Before
 * any of that is built, the two questions that decide whether it is safe at all
 * have to hold: is this delivery really from Meta, and is this number really
 * entitled to this site. Both are answered here, on an endpoint that cannot yet
 * change anything — which is the cheapest place to get them wrong.
 */
class SiteAgentChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.app_secret' => 'app-secret',
            'siteagent.whatsapp.verify_token' => 'verify-me',
            'siteagent.whatsapp.phone_number_id' => '123456',
            'siteagent.whatsapp.token' => 'permanent-token',
        ]);
    }

    public function test_metas_subscribe_handshake_needs_the_configured_token(): void
    {
        $this->get(route('webhooks.site-agent.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'verify-me',
            'hub_challenge' => '1158201444',
        ]))->assertOk()->assertSee('1158201444');

        $this->get(route('webhooks.site-agent.verify', [
            'hub_verify_token' => 'guessed',
            'hub_challenge' => '1158201444',
        ]))->assertForbidden();
    }

    public function test_a_blank_verify_token_subscribes_nobody(): void
    {
        // Fail closed: an unconfigured token must never mean "anybody may
        // point a webhook at us".
        config(['siteagent.whatsapp.verify_token' => '']);

        $this->get(route('webhooks.site-agent.verify', [
            'hub_verify_token' => '',
            'hub_challenge' => 'x',
        ]))->assertForbidden();
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        Queue::fake();

        $body = json_encode($this->envelope('972501234567', 'שלום'));

        // No signature at all.
        $this->call('POST', route('webhooks.site-agent'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertForbidden();

        // A signature over different content — i.e. a replayed one.
        $this->postSigned($body, 'sha256='.hash_hmac('sha256', '{"other":"body"}', 'app-secret'))
            ->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_a_signed_delivery_is_recorded_and_queued_once(): void
    {
        Queue::fake();

        $body = json_encode($this->envelope('972501234567', 'תעדכן את שעות הפתיחה'));

        $this->postSigned($body)->assertOk();
        // Meta redelivers anything it did not get a 200 for. The same
        // instruction carried out twice is the customer's price changed twice.
        $this->postSigned($body)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(HandleSiteAgentMessageJob::class, 1);
    }

    public function test_a_delivery_receipt_is_not_mistaken_for_a_message(): void
    {
        Queue::fake();

        // The same envelope carries read markers and delivery statuses with no
        // messages key at all.
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'statuses' => [['id' => 'wamid.X', 'status' => 'delivered']],
            ]]]]],
        ]);

        $this->postSigned($body)->assertOk();

        $this->assertSame(0, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_number_is_told_so_and_learns_nothing_else(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $this->deliver('972509999999', 'תוסיף עמוד');

        // A stranger probing the number must not be able to learn which numbers
        // are registered here — so this reads the same as the product being off.
        $this->assertReplyContains('אינו רשום');
    }

    public function test_a_bound_number_that_never_proved_itself_is_asked_for_the_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $this->subscriber(['verified_at' => null]);

        $this->deliver('972501234567', 'תשנה את המחיר');

        $this->assertReplyContains('קוד האימות');
    }

    public function test_the_right_code_binds_the_number_and_spends_the_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        $this->deliver('972501234567', ' 447291 ');

        $subscriber->refresh();
        $this->assertNotNull($subscriber->verified_at);
        // A code that still works after it was used is a second key under the mat.
        $this->assertNull($subscriber->verification_code);
    }

    public function test_an_expired_code_does_not_bind_the_number(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        config(['siteagent.binding.verification_ttl_minutes' => 30]);

        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now()->subHours(2),
        ]);

        $this->deliver('972501234567', '447291');

        $this->assertNull($subscriber->fresh()->verified_at);
    }

    public function test_a_wrong_code_does_not_bind_the_number(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        $this->deliver('972501234567', '447292');

        $this->assertNull($subscriber->fresh()->verified_at);
    }

    public function test_a_run_of_wrong_codes_burns_the_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        // Six digits is a million tries for a machine; the code has to stop
        // being guessable long before that.
        foreach (range(1, SiteAgentSubscriber::MAX_VERIFICATION_ATTEMPTS) as $ignored) {
            $this->deliver('972501234567', '000000');
        }

        // Even the RIGHT code no longer binds: the attempt is over.
        $this->deliver('972501234567', '447291');

        $this->assertNull($subscriber->fresh()->verified_at);
    }

    public function test_the_code_is_never_stored_in_a_readable_form(): void
    {
        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        // Anybody with read access to the database — or to a backup — must not
        // be able to bind a number to somebody's site with what they find there.
        $stored = (string) DB::table('site_agent_subscribers')
            ->where('id', $subscriber->id)
            ->value('verification_code');

        $this->assertNotSame('447291', $stored);
        $this->assertTrue(Hash::check('447291', $stored));
    }

    public function test_a_subscribed_customer_reaches_the_agent(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        $this->deliver('972501234567', 'תוסיף לדף הבית שאנחנו פתוחים בשישי');

        $this->assertReplyContains('קיבלתי');
        $this->assertNotNull($subscriber->fresh()->last_seen_at);
    }

    public function test_without_a_live_subscription_the_agent_goes_quiet_and_says_why(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber();
        // Payment stopped. The agent stops; the site does not.
        $this->subscribe($subscriber->customer, SubscriptionStatus::PastDue);

        $this->deliver('972501234567', 'תעדכן מחיר');

        $this->assertReplyContains('המנוי');
        $this->assertReplyContains('האתר עצמו ממשיך לעבוד');
    }

    public function test_a_subscription_to_something_else_does_not_buy_the_agent(): void
    {
        $subscriber = $this->subscriber();
        // An ordinary hosting plan, live and paid — and not this product.
        Subscription::factory()->create([
            'customer_id' => $subscriber->customer_id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => false])->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertFalse(app(SiteAgentAccess::class)->subscribed($subscriber->customer));
    }

    public function test_a_trial_counts_as_subscribed(): void
    {
        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer, SubscriptionStatus::Trialing);

        // Somebody evaluating the product has to be able to use it.
        $this->assertTrue(app(SiteAgentAccess::class)->subscribed($subscriber->customer));
    }

    public function test_revoked_access_is_refused_even_with_a_live_subscription(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber(['revoked_at' => now(), 'revoked_reason' => 'עזב את החברה']);
        $this->subscribe($subscriber->customer);

        // An employee who left keeps their phone. The subscription is the
        // customer's; the access is this number's.
        $this->deliver('972501234567', 'תמחק את דף המחירים');

        $this->assertReplyContains('ההרשאה');
    }

    public function test_a_disconnected_site_is_said_out_loud_rather_than_answered_cheerfully(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);
        $subscriber->site->update(['mcp_enabled' => false]);

        $this->deliver('972501234567', 'תעדכן טקסט');

        $this->assertReplyContains('אין כרגע חיבור לאתר');
    }

    public function test_an_israeli_number_is_normalised_the_way_the_provider_wants_it(): void
    {
        $client = app(WhatsAppCloudClient::class);

        // "050-123-4567" sent as-is reaches nobody, and does so silently.
        $this->assertSame('972501234567', $client->normalize('050-123-4567'));
        $this->assertSame('972501234567', $client->normalize('+972 50 123 4567'));
        $this->assertSame('', $client->normalize('לא מספר'));
    }

    public function test_the_product_being_off_answers_rather_than_ignoring(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        config(['siteagent.enabled' => false]);

        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        $this->deliver('972501234567', 'שלום');

        // Silence would read as a broken business, not as a switched-off feature.
        $this->assertReplyContains('אינו רשום');
    }

    public function test_the_panel_screen_lists_who_can_drive_a_site(): void
    {
        $this->actingAs(User::factory()->create());

        $subscriber = $this->subscriber(['name' => 'דנה']);
        $this->subscribe($subscriber->customer);

        Livewire::test(ListSiteAgentSubscribers::class)
            ->assertOk()
            ->assertSee('דנה', false)
            ->assertSee($subscriber->site->domain, false)
            ->assertSee('פעיל', false);
    }

    public function test_the_screen_says_when_a_verified_number_has_no_live_subscription(): void
    {
        $this->actingAs(User::factory()->create());

        // Verified and not revoked — and the agent still will not answer them.
        // Reading that off three separate columns is how a support call about
        // "הסוכן לא עונה" turns into an hour of guessing.
        $this->subscriber(['name' => 'יוסי']);

        Livewire::test(ListSiteAgentSubscribers::class)
            ->assertOk()
            ->assertSee('אין מנוי פעיל', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    private function subscriber(array $attributes = []): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ]);

        return SiteAgentSubscriber::create(array_merge([
            'phone' => '972501234567',
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'verified_at' => now(),
        ], $attributes));
    }

    private function subscribe(Customer $customer, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true])->id,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function envelope(string $from, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [['profile' => ['name' => 'דנה'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => 'wamid.'.md5($from.$text),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function postSigned(string $body, ?string $signature = null): TestResponse
    {
        return $this->call('POST', route('webhooks.site-agent'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    /** Deliver a message end to end, webhook through job. */
    private function deliver(string $from, string $text): void
    {
        $this->postSigned(json_encode($this->envelope($from, $text)))->assertOk();

        $event = WebhookEvent::latest('id')->firstOrFail();
        $event->update(['processed_at' => null]);

        (new HandleSiteAgentMessageJob($event->id))
            ->handle(app(SiteAgentAccess::class), app(WhatsAppCloudClient::class));
    }

    /** The body of the reply the agent actually sent back over the API. */
    private function assertReplyContains(string $needle): void
    {
        $found = false;

        Http::recorded(function ($request) use ($needle, &$found) {
            if (str_contains((string) data_get($request->data(), 'text.body', ''), $needle)) {
                $found = true;
            }

            return true;
        });

        $this->assertTrue($found, "לא נשלחה תשובה שמכילה: {$needle}");
    }
}
