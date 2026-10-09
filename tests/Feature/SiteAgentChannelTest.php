<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SiteAgentSubscriberResource\Pages\ListSiteAgentSubscribers;
use App\Jobs\HandleSiteAgentMessageJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Providers\SettingsServiceProvider;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentWelcome;
use App\Services\SiteAgent\SiteChoice;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\FakesSiteAgentProposalFidelity;
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
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();

        config([
            'siteagent.enabled' => true,
            'siteagent.whatsapp.app_secret' => 'app-secret',
            'siteagent.whatsapp.verify_token' => 'verify-me',
            'siteagent.whatsapp.token' => 'permanent-token',
        ]);

        // The number itself is arranged the way production holds it — as a
        // stored setting — and not with config(). It is one of the values the
        // settings overlay reverts when no row backs it, so a bare config()
        // here would be wiped again the moment anything dispatched a job.
        Setting::put('siteagent.phone_number_id', '123456');
        SettingsServiceProvider::refreshFromDatabase();
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

    public function test_an_unknown_number_is_told_what_the_bot_does_and_where_to_join(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        Plan::factory()->create(['active' => true, 'is_public' => true, 'includes_site_agent' => true, 'trial_days' => 7]);

        $this->deliver('972509999999', 'מה זה?');

        $pitch = $this->lastReply();
        $this->assertStringContainsString('בוט ניהול האתר', $pitch);
        $this->assertStringContainsString('יצירת מוצרים חדשים', $pitch);
        $this->assertStringContainsString('7 ימי ניסיון', $pitch);
        $this->assertStringContainsString(route('store.agent'), $pitch);

        // Not the whole brochure on every message they send that day.
        $this->deliver('972509999999', 'שלום?');

        $this->assertStringNotContainsString('יצירת מוצרים חדשים', $this->lastReply());
        $this->assertStringContainsString(route('store.agent'), $this->lastReply());
    }

    public function test_a_stranger_is_not_billed_for_the_pitch(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $this->deliver('972509999999', 'היי');

        $this->assertSame(0, SiteAgentUsage::count());
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

    public function test_a_code_is_only_tested_while_the_number_is_held(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        // The five-attempt limit is read off a LOADED row. Workers that each
        // load their copy before any of them charges all see a budget that is
        // already spent, so six guesses sent at once would get six checks
        // against a live code however many attempts the row had left — a limit
        // that holds only when nobody is in a hurry is not a limit.
        //
        // What stops that is reading the rows while this number is held. Proved
        // by trying to take the number at the moment they are read: whoever is
        // reading must already have it.
        $held = false;
        $access = Mockery::mock(SiteAgentAccess::class)->makePartial();
        $access->shouldReceive('bindings')->andReturnUsing(function (string $from) use (&$held) {
            $held = ! Cache::lock("site-agent:routing:{$from}", 5)->get();

            return (new SiteAgentAccess)->bindings($from);
        });
        $this->app->instance(SiteAgentAccess::class, $access);

        $this->deliver('972501234567', '447291');

        $this->assertTrue($held, 'הקוד נבדק בלי שהמספר מוחזק — שני עובדים היו בודקים במקביל מול אותו תקציב.');
        // And it is still an ordinary verification, not a lock that swallows it.
        $this->assertNotNull($subscriber->fresh()->verified_at);
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
        // being guessable long before that. Each guess is a DIFFERENT wrong
        // code, because the same message twice is one delivery deduplicated —
        // which is a replay, not a second attempt.
        foreach (range(1, SiteAgentSubscriber::MAX_VERIFICATION_ATTEMPTS) as $attempt) {
            $this->deliver('972501234567', str_pad((string) $attempt, 6, '0', STR_PAD_LEFT));
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

        // Past the gate and into the agent itself: the reply is about the
        // request, not about whether they are allowed to make one.
        $this->assertReplyDoesNotContain('אינו רשום');
        $this->assertReplyDoesNotContain('המנוי');
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

    public function test_a_lapsed_owner_is_given_the_way_back(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $subscriber = $this->subscriber();
        $subscriber->customer->update(['phone' => '050-1234567']);
        $this->subscribe($subscriber->customer, SubscriptionStatus::PastDue);

        $this->deliver('972501234567', 'תעדכן מחיר');

        // "אינו פעיל" and nothing else is how a customer who wants to keep
        // paying concludes the product is broken. The way back is one tap away,
        // and this is the moment they are asking for it.
        $this->assertReplyContains('/portal/login');
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

    public function test_a_number_managing_two_sites_is_asked_which_one(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        [$bakery, $cafe] = $this->twoSites();

        $this->deliver('972501234567', 'תעדכן את שעות הפתיחה');

        // Guessing here means an instruction meant for one business carried out
        // on another, silently. Both names are offered so the answer is a word
        // they already have.
        $this->assertReplyContains('יותר מאתר אחד');
        $this->assertReplyContains($bakery->site->domain);
        $this->assertReplyContains($cafe->site->domain);

        // And nothing was planned or changed while the question is open.
        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_naming_the_site_settles_it_for_the_conversation(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        [, $cafe] = $this->twoSites();

        $this->deliver('972501234567', 'תעדכן את שעות הפתיחה');
        $this->assertReplyContains('יותר מאתר אחד');

        // Naming it answers the question...
        $this->deliver('972501234567', $cafe->site->domain);
        $this->assertStringNotContainsString('יותר מאתר אחד', $this->lastReply());
        $this->assertSame(1, SiteAgentRequest::count());

        // ...and the next instruction does not ask again.
        $this->deliver('972501234567', 'תוסיף משפט בדף הבית');
        $this->assertStringNotContainsString('יותר מאתר אחד', $this->lastReply());

        $this->assertSame(2, SiteAgentRequest::count());
        $this->assertSame($cafe->site_id, SiteAgentRequest::latest('id')->first()?->site_id);
    }

    public function test_the_position_in_the_list_is_an_answer_but_a_price_is_not(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        [$bakery] = $this->twoSites();

        $this->deliver('972501234567', 'תעדכן מחיר');
        $this->deliver('972501234567', '1');

        // Sorted by domain, so "1" is a stable answer between two messages.
        $this->assertSame($bakery->site_id, SiteAgentRequest::latest('id')->first()?->site_id);
    }

    public function test_the_instruction_survives_the_question_about_which_site(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        [$bakery] = $this->twoSites();

        $this->deliver('972501234567', 'תוסיף בדף הבית שאנחנו פתוחים בשישי');
        $this->assertReplyContains('יותר מאתר אחד');

        $this->deliver('972501234567', '1');

        // Their instruction is what gets planned — not the "1". Otherwise the
        // agent asks a question and then forgets what it was about, and the
        // customer has to type the whole thing again.
        $request = SiteAgentRequest::latest('id')->firstOrFail();
        $this->assertSame($bakery->site_id, $request->site_id);
        $this->assertStringContainsString('פתוחים בשישי', (string) $request->message);
        $this->assertSame(11, $request->plan['front_page']['id']);
        $this->assertSame(SiteAgentRequest::AWAITING, $request->state);
    }

    public function test_an_ordinary_word_does_not_move_the_conversation_to_another_site(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        [$bakery] = $this->twoSites('car.test', 'zzz.test');

        $this->deliver('972501234567', 'car.test');
        $this->deliver('972501234567', 'תוסיף לעמוד משהו על cart נטוש');

        // "cart" contains "car". Matching a site name as a bare substring would
        // move the whole conversation to another business mid-sentence, and say
        // nothing about it.
        $this->assertSame($bakery->site_id, SiteAgentRequest::latest('id')->firstOrFail()->site_id);
    }

    public function test_an_ordinary_instruction_does_not_burn_a_second_sites_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $bakery = $this->subscriber();
        $this->subscribe($bakery->customer);

        $second = Site::factory()->create([
            'customer_id' => $bakery->customer_id,
            'domain' => 'cafe.test',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://cafe.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ]);

        $pending = SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $bakery->customer_id,
            'site_id' => $second->id,
            'verification_code' => Hash::make('654321'),
            'verification_sent_at' => now(),
        ]);

        // Five ordinary instructions while the second binding waits for its
        // code. Counting them as wrong guesses would spend the whole allowance
        // before the owner ever typed the code.
        foreach (range(1, SiteAgentSubscriber::MAX_VERIFICATION_ATTEMPTS) as $attempt) {
            $this->deliver('972501234567', "תעדכן משהו מספר {$attempt}");
        }

        $this->assertSame(0, (int) $pending->fresh()->verification_attempts);

        $this->deliver('972501234567', '654321');
        $this->assertNotNull($pending->fresh()->verified_at);
    }

    public function test_guessing_is_bounded_across_every_code_a_number_is_waiting_on(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $customer = Customer::factory()->create();
        $this->subscribe($customer);

        // Two sites, both waiting for their code from the same number.
        $bindings = collect(['bakery.test', 'cafe.test'])->map(function (string $domain) use ($customer): SiteAgentSubscriber {
            $site = Site::factory()->create([
                'customer_id' => $customer->id,
                'domain' => $domain,
                'mcp_enabled' => true,
                'mcp_endpoint' => "https://{$domain}/wp-json/multioto/v1/mcp",
                'mcp_secret' => 'secret',
            ]);

            return SiteAgentSubscriber::create([
                'phone' => '972501234567',
                'customer_id' => $customer->id,
                'site_id' => $site->id,
                'verification_code' => Hash::make($domain === 'bakery.test' ? '111111' : '222222'),
                'verification_sent_at' => now(),
            ]);
        });

        foreach (range(1, SiteAgentSubscriber::MAX_VERIFICATION_ATTEMPTS) as $attempt) {
            $this->deliver('972501234567', str_pad((string) $attempt, 6, '9', STR_PAD_LEFT));
        }

        // Charging only one binding left the other with an untouched counter,
        // and once the charged one was spent its charge became a no-op — so the
        // guessing could go on for ever against a live code.
        $this->deliver('972501234567', '111111');
        $this->deliver('972501234567', '222222');

        foreach ($bindings as $binding) {
            $this->assertNull($binding->fresh()->verified_at);
        }
    }

    public function test_a_second_site_can_still_be_verified_by_its_own_code(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $bakery = $this->subscriber();
        $this->subscribe($bakery->customer);

        // The same owner, a second site, waiting for its code. Looking at only
        // one binding per number meant this code could never be answered.
        $second = Site::factory()->create([
            'customer_id' => $bakery->customer_id,
            'domain' => 'cafe.test',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://cafe.test/wp-json/multioto/v1/mcp',
            'mcp_secret' => 'secret',
        ]);

        $pending = SiteAgentSubscriber::create([
            'phone' => '972501234567',
            'customer_id' => $bakery->customer_id,
            'site_id' => $second->id,
            'verification_code' => Hash::make('654321'),
            'verification_sent_at' => now(),
        ]);

        $this->deliver('972501234567', '654321');

        $this->assertNotNull($pending->fresh()->verified_at);
        $this->assertReplyContains('המספר אומת');
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
        $this->assertReplyContains('אינו פעיל כרגע');
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

    public function test_a_voice_note_is_answered_rather_than_ignored(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        // Not something this agent can act on. Silence would read as the
        // product being broken, which is worse than saying so.
        $this->deliverTyped('972501234567', ['type' => 'audio', 'audio' => ['id' => 'audio-1']]);

        $this->assertReplyContains('הודעות טקסט ותמונות');
    }

    public function test_an_image_reaches_the_agent_with_its_caption(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        // The instruction for an image lives in its caption, not in a text
        // body — reading only `text.body` would drop it silently.
        $this->deliverTyped('972501234567', [
            'type' => 'image',
            'image' => ['id' => 'media-9', 'caption' => 'תשים את זה בדף הבית'],
        ]);

        $this->assertReplyDoesNotContain('הודעות טקסט ותמונות');
    }

    public function test_an_offer_arrives_with_yes_and_no_buttons_and_a_tap_answers_it(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        $heard = [];
        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldReceive('handle')->andReturnUsing(function ($who, string $text) use (&$heard): string {
            $heard[] = $text;

            return $text === 'כן' ? '✅ בוצע.' : "🔌 לעדכן 1 תוספים\n\n".SiteAgentConversation::CONFIRM_PROMPT;
        });
        $this->app->instance(SiteAgentConversation::class, $conversation);

        $this->deliver('972501234567', 'תעדכן את התוספים');

        $interactive = null;
        Http::recorded(function ($request) use (&$interactive) {
            if (data_get($request->data(), 'type') === 'interactive') {
                $interactive = $request->data();
            }

            return true;
        });

        $this->assertNotNull($interactive, 'the offer went out without buttons');
        $this->assertSame('🔌 לעדכן 1 תוספים', data_get($interactive, 'interactive.body.text'));
        $this->assertSame(
            [WhatsAppCloudClient::BUTTON_YES, WhatsAppCloudClient::BUTTON_NO],
            array_column(array_column((array) data_get($interactive, 'interactive.action.buttons'), 'reply'), 'id'),
        );

        // The tap comes back as an interactive reply and is read as a typed "כן".
        $this->deliverTyped('972501234567', ['type' => 'interactive', 'interactive' => [
            'type' => 'button_reply',
            'button_reply' => ['id' => WhatsAppCloudClient::BUTTON_YES, 'title' => '✅ כן, לבצע'],
        ]]);

        $this->assertSame(['תעדכן את התוספים', 'כן'], $heard);
        $this->assertReplyDoesNotContain('הודעות טקסט ותמונות');
    }

    public function test_an_offer_too_long_for_buttons_goes_as_text_with_the_buttons_after_it(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        app(WhatsAppCloudClient::class)->sendConfirmation('972501234567', str_repeat('א', 1100));

        $types = [];
        Http::recorded(function ($request) use (&$types) {
            $types[] = data_get($request->data(), 'type');

            return true;
        });

        $this->assertSame(['text', 'interactive'], $types);
    }

    public function test_at_the_owners_ceiling_the_bot_stops_until_they_raise_it(): void
    {
        // A distinct Meta id per send, as the real API returns: the meter
        // counts each id once.
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        $subscriber = $this->subscriber();
        $subscription = Subscription::factory()->create([
            'customer_id' => $subscriber->customer_id,
            'site_id' => $subscriber->site_id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true, 'message_price_agorot' => 15])->id,
            'status' => SubscriptionStatus::Active,
            'site_agent_message_cap' => 2,
        ]);

        $heard = [];
        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldReceive('handle')->andReturnUsing(function ($who, string $text) use (&$heard): string {
            $heard[] = $text;

            return 'תשובה';
        });
        $this->app->instance(SiteAgentConversation::class, $conversation);

        $this->deliver('972501234567', 'אחת');
        $this->deliver('972501234567', 'שתיים');
        $this->assertSame(2, SiteAgentUsage::count());

        // At the ceiling: not handed to the bot, told why, not billed.
        $this->deliver('972501234567', 'שלוש');
        $this->assertReplyContains('הגעתם לתקרה');
        $this->assertSame(['אחת', 'שתיים'], $heard);
        $this->assertSame(2, SiteAgentUsage::count());

        // Raising it is the one thing that works at the ceiling.
        $this->deliver('972501234567', 'תקרה 800');
        $this->assertSame(800, $subscription->refresh()->site_agent_message_cap);
        $this->assertReplyContains('התקרה נקבעה ל-800');

        $this->deliver('972501234567', 'ארבע');
        $this->assertSame(['אחת', 'שתיים', 'ארבע'], $heard);
    }

    public function test_the_eighty_percent_notice_is_sent_once_and_not_billed(): void
    {
        // A distinct Meta id per send, as the real API returns: the meter
        // counts each id once.
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        $subscriber = $this->subscriber();
        Subscription::factory()->create([
            'customer_id' => $subscriber->customer_id,
            'site_id' => $subscriber->site_id,
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true, 'message_price_agorot' => 15])->id,
            'status' => SubscriptionStatus::Active,
            'site_agent_message_cap' => 5,
        ]);
        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldReceive('handle')->andReturn('תשובה');
        $this->app->instance(SiteAgentConversation::class, $conversation);

        foreach (['1', '2', '3', '4', '5'] as $text) {
            $this->deliver('972501234567', $text);
        }

        $notices = 0;
        Http::recorded(function ($request) use (&$notices) {
            $notices += str_contains($this->bodyOf($request->data()), 'מתוך 5 ההודעות') ? 1 : 0;

            return true;
        });

        $this->assertSame(1, $notices);
        $this->assertSame(5, SiteAgentUsage::count());
    }

    public function test_a_shop_is_welcomed_with_things_a_shop_does(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);
        $subscriber->site->update(['mcp_capabilities' => ['tools' => [['name' => 'wc_order_list'], ['name' => 'wp_lead_list']]]]);

        $this->deliver('972501234567', '447291');

        $this->assertReplyContains('כמה הזמנות היו היום?');
        $this->assertReplyContains('"בטל"');
    }

    public function test_a_site_without_a_shop_is_welcomed_with_leads_and_pages(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $this->subscriber([
            'verified_at' => null,
            'verification_code' => Hash::make('447291'),
            'verification_sent_at' => now(),
        ]);

        $this->deliver('972501234567', '447291');

        $this->assertReplyContains('תודיע לי על כל ליד חדש');
        $this->assertReplyDoesNotContain('כמה הזמנות היו היום?');
    }

    public function test_a_weekly_tip_rides_on_a_reply_once_a_week_in_the_first_month(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]));
        $subscriber = $this->subscriber(['verified_at' => now()->subDays(8)]);
        $this->subscribe($subscriber->customer);
        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldReceive('handle')->andReturn('תשובה');
        $this->app->instance(SiteAgentConversation::class, $conversation);

        $this->deliver('972501234567', 'שלום');
        $this->deliver('972501234567', 'עוד שאלה');

        $tips = 0;
        Http::recorded(function ($request) use (&$tips) {
            $tips += str_starts_with($this->bodyOf($request->data()), '💡 טיפ השבוע') ? 1 : 0;

            return true;
        });

        // One this week, after the reply — not one per message.
        $this->assertSame(1, $tips);
        $this->assertSame(1, $subscriber->refresh()->tips_sent);

        // A number past its first month gets none.
        $this->travel(40)->days();
        $this->assertFalse(app(SiteAgentWelcome::class)->tipDue($subscriber->refresh(), 4));
    }

    public function test_a_button_from_a_replaced_offer_does_not_confirm_the_new_one(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber();
        $this->subscribe($subscriber->customer);

        $current = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id, 'customer_id' => $subscriber->customer_id,
            'message' => 'הצעה ב', 'operation' => SiteAgentRequest::OP_CACHE_FLUSH, 'state' => SiteAgentRequest::AWAITING,
            'plan' => ['operation' => SiteAgentRequest::OP_CACHE_FLUSH, 'summary' => 'ניקוי'], 'preview' => 'ניקוי מטמון',
            'expires_at' => now()->addHour(),
        ]);

        $conversation = Mockery::mock(SiteAgentConversation::class);
        $conversation->shouldNotReceive('handle');
        $this->app->instance(SiteAgentConversation::class, $conversation);

        // "כן" tapped under an older offer (id below the current one).
        $this->deliverTyped('972501234567', ['type' => 'interactive', 'interactive' => [
            'type' => 'button_reply',
            'button_reply' => ['id' => WhatsAppCloudClient::BUTTON_YES.':'.($current->id - 1), 'title' => '✅ כן, לבצע'],
        ]]);

        $this->assertReplyContains('שייך להצעה קודמת');
        $this->assertSame(SiteAgentRequest::AWAITING, $current->fresh()->state);
    }

    public function test_a_lapsed_site_does_not_ride_on_another_paid_site(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);
        $subscriber = $this->subscriber();
        $plan = Plan::factory()->create(['includes_site_agent' => true]);
        $otherSite = Site::factory()->create(['customer_id' => $subscriber->customer_id]);

        // Site A paid, this site's own subscription canceled.
        Subscription::factory()->create(['customer_id' => $subscriber->customer_id, 'plan_id' => $plan->id, 'site_id' => $otherSite->id, 'status' => SubscriptionStatus::Active]);
        Subscription::factory()->create(['customer_id' => $subscriber->customer_id, 'plan_id' => $plan->id, 'site_id' => $subscriber->site_id, 'status' => SubscriptionStatus::Suspended]);

        $this->assertSame(SiteAgentAccess::NO_SUBSCRIPTION, app(SiteAgentAccess::class)->forSubscriber($subscriber->fresh())['status']);
    }

    public function test_a_long_offer_whose_buttons_fail_is_not_sent_twice(): void
    {
        $sequence = Http::fakeSequence()
            ->push(['messages' => [['id' => 'wamid.text']]])
            ->push(['error' => ['message' => 'interactive not allowed']], 400);

        $id = app(WhatsAppCloudClient::class)->sendConfirmation('972501234567', str_repeat('א', 1100));

        $this->assertSame('wamid.text', $id);
        $this->assertTrue($sequence->isEmpty());
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
    private function envelope(string $from, string $text, ?string $toNumberId = null): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        // Which of the account's numbers the message arrived at.
                        // Meta sends this on every delivery, and a WhatsApp
                        // Business Account commonly holds more than one number.
                        'metadata' => ['phone_number_id' => $toNumberId ?? '123456'],
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

        // The queue runs inline here, so the controller's dispatch has usually
        // already handled it. Running it a second time is not a harmless
        // repeat — it would replay the customer's instruction.
        if ($event->processed_at !== null) {
            return;
        }

        (new HandleSiteAgentMessageJob($event->id))->handle(
            app(SiteAgentAccess::class),
            app(WhatsAppCloudClient::class),
            app(SiteAgentConversation::class),
            app(SiteChoice::class),
        );
    }

    /** What a sent message says — a plain text, or the body over its buttons. */
    private function bodyOf(array $data): string
    {
        return (string) (data_get($data, 'text.body') ?? data_get($data, 'interactive.body.text', ''));
    }

    /** The last thing the agent said. */
    private function lastReply(): string
    {
        $last = '';

        Http::recorded(function ($request) use (&$last) {
            $body = $this->bodyOf($request->data());

            if ($body !== '') {
                $last = $body;
            }

            return true;
        });

        return $last;
    }

    /** The agent never sent a reply containing this. */
    private function assertReplyDoesNotContain(string $needle): void
    {
        $found = false;

        Http::recorded(function ($request) use ($needle, &$found) {
            if (str_contains($this->bodyOf($request->data()), $needle)) {
                $found = true;
            }

            return true;
        });

        $this->assertFalse($found, "נשלחה תשובה שמכילה: {$needle}");
    }

    /** Deliver a message of any Meta type, end to end. */
    private function deliverTyped(string $from, array $message): void
    {
        $envelope = $this->envelope($from, '');
        $envelope['entry'][0]['changes'][0]['value']['messages'][0] = array_merge(
            ['from' => $from, 'id' => 'wamid.'.md5(json_encode($message)), 'timestamp' => (string) now()->timestamp],
            $message,
        );

        $this->postSigned(json_encode($envelope))->assertOk();

        $event = WebhookEvent::latest('id')->firstOrFail();

        // The queue runs inline here, so the controller's dispatch has usually
        // already handled it. Running it a second time is not a harmless
        // repeat — it would replay the customer's instruction.
        if ($event->processed_at !== null) {
            return;
        }

        (new HandleSiteAgentMessageJob($event->id))->handle(
            app(SiteAgentAccess::class),
            app(WhatsAppCloudClient::class),
            app(SiteAgentConversation::class),
            app(SiteChoice::class),
        );
    }

    /** The body of the reply the agent actually sent back over the API. */
    private function assertReplyContains(string $needle): void
    {
        $found = false;

        Http::recorded(function ($request) use ($needle, &$found) {
            if (str_contains($this->bodyOf($request->data()), $needle)) {
                $found = true;
            }

            return true;
        });

        $this->assertTrue($found, "לא נשלחה תשובה שמכילה: {$needle}");
    }

    /**
     * One owner, one number, two sites — both live, both paid for.
     *
     * @return array{0: SiteAgentSubscriber, 1: SiteAgentSubscriber} bakery, cafe
     */
    private function twoSites(string $first = 'bakery.test', string $second = 'cafe.test'): array
    {
        Cache::flush();

        $customer = Customer::factory()->create();
        $this->subscribe($customer);

        $bindings = collect([$first, $second])->map(function (string $domain) use ($customer): SiteAgentSubscriber {
            $site = Site::factory()->create([
                'customer_id' => $customer->id,
                'domain' => $domain,
                'mcp_enabled' => true,
                'mcp_endpoint' => "https://{$domain}/wp-json/multioto/v1/mcp",
                'mcp_secret' => 'secret',
            ]);

            return SiteAgentSubscriber::create([
                'phone' => '972501234567',
                'customer_id' => $customer->id,
                'site_id' => $site->id,
                'verified_at' => now(),
            ])->setRelation('site', $site);
        });

        $this->planningWorks();

        return [$bindings[0], $bindings[1]];
    }

    /** A planner that always has an answer, so the site is the only variable. */
    private function planningWorks(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn([
            'can_do' => true, 'operation' => 'append_text', 'page_id' => 11,
            'text' => 'טקסט חדש', 'summary' => 'הוספה',
        ]);
        $this->app->instance(ClaudeClient::class, $ai);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool) => match ($tool) {
            'wp_site_settings_get' => ['values' => ['show_on_front' => 'page', 'page_on_front' => 11, 'page_for_posts' => 0]],
            'wp_content_list' => ['items' => [['id' => 11, 'title' => 'דף הבית']]],
            'wp_content_get' => ['id' => 11, 'type' => 'page', 'title' => 'דף הבית', 'status' => 'publish', 'content' => 'ברוכים הבאים'],
            'wc_product_search' => ['products' => []],
            default => [],
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);
    }

    /**
     * הודעה שנשלחה למספר אחר באותו חשבון — אינה של הסוכן.
     *
     * מטא רושמת webhooks **לכל חשבון העסק, לא למספר**. חשבון שמחזיק גם קו תמיכה
     * וגם את המספר של המוצר הזה מקבל את שתי התיבות לאותה כתובת — ובלי הסינון
     * הזה לקוח שכותב לקו התמיכה היה נענה על ידי סוכן האתר, והוראה שנכתבה שם
     * הייתה מתבצעת על אתר.
     */
    public function test_a_message_to_another_number_in_the_account_is_ignored(): void
    {
        Queue::fake();

        $body = json_encode($this->envelope('972501234567', 'שלום', toNumberId: '999999'));

        // 200, because Meta must not be told to redeliver something we meant to
        // drop — but nothing is recorded and nothing is queued.
        $this->postSigned($body)->assertOk();

        $this->assertSame(0, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    /** ולמספר שלנו — מתקבלת כרגיל. */
    public function test_a_message_to_our_own_number_is_accepted(): void
    {
        Queue::fake();

        $body = json_encode($this->envelope('972501234567', 'שלום', toNumberId: '123456'));

        $this->postSigned($body)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(HandleSiteAgentMessageJob::class, 1);
    }
}
