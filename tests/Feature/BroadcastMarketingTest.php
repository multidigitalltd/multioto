<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketChannel;
use App\Enums\UserRole;
use App\Filament\Resources\BroadcastResource\Actions\BroadcastSendActions;
use App\Filament\Resources\BroadcastResource\Pages\ListBroadcasts;
use App\Jobs\SendBroadcastJob;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Support\BroadcastAudience;
use App\Services\Support\BroadcastComposer;
use App\Services\Support\BroadcastRenderer;
use App\Services\Support\MarketingPreferences;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Marketing broadcasts: personalization, the legal footer, and opting out.
 *
 * חוק התקשורת ס' 30א is the reason most of this exists — an advertising
 * message must say it is advertising, identify the sender, offer a way out in
 * the medium it arrived on, and that way out must actually work.
 */
class BroadcastMarketingTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): BroadcastRenderer
    {
        return app(BroadcastRenderer::class);
    }

    private function broadcast(array $attributes = []): Broadcast
    {
        return Broadcast::create(array_merge([
            'subject' => 'עדכון',
            'body' => 'תוכן',
            'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Draft,
            'is_marketing' => true,
        ], $attributes));
    }

    private function send(Broadcast $broadcast): void
    {
        (new SendBroadcastJob($broadcast->id))->handle(
            app(WahaClient::class), app(BroadcastAudience::class), $this->renderer(),
        );
    }

    /*
    | ----------------------------------------------------------------
    | Personalization
    | ----------------------------------------------------------------
    */

    public function test_placeholders_resolve_to_this_customers_own_details(): void
    {
        $customer = Customer::factory()->create(['name' => 'ברסקי נכסים', 'contact_name' => 'דני']);
        $plan = Plan::factory()->create(['name' => 'תחזוקה פלוס']);
        Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'barski.co.il']);
        Subscription::factory()->create(['customer_id' => $customer->id, 'plan_id' => $plan->id]);

        $broadcast = $this->broadcast([
            'body' => 'שלום {{איש_קשר}} מ{{שם}}, האתר {{אתר}} בחבילת {{חבילה}}.',
            'is_marketing' => false,
        ]);

        $body = $this->renderer()->body($broadcast, $customer);

        $this->assertSame('שלום דני מברסקי נכסים, האתר barski.co.il בחבילת תחזוקה פלוס.', $body);
    }

    public function test_a_placeholder_with_no_value_leaves_no_braces_for_the_customer_to_see(): void
    {
        // A customer with no site and a typo'd token: better an empty gap than
        // a message that reads "האתר {{אתרr}}".
        $customer = Customer::factory()->create(['name' => 'לקוח', 'contact_name' => null]);

        $broadcast = $this->broadcast(['body' => 'האתר {{אתר}} {{אתרr}} שלך.', 'is_marketing' => false]);

        $body = $this->renderer()->body($broadcast, $customer);

        $this->assertStringNotContainsString('{{', $body);
        $this->assertStringNotContainsString('}}', $body);
    }

    public function test_the_contact_placeholder_falls_back_to_the_business_name(): void
    {
        $customer = Customer::factory()->create(['name' => 'ברסקי נכסים', 'contact_name' => null]);

        $broadcast = $this->broadcast(['body' => 'שלום {{איש_קשר}}', 'is_marketing' => false]);

        $this->assertSame('שלום ברסקי נכסים', $this->renderer()->body($broadcast, $customer));
    }

    /*
    | ----------------------------------------------------------------
    | The legal footer
    | ----------------------------------------------------------------
    */

    public function test_a_marketing_whatsapp_message_says_it_is_advertising_and_how_to_stop(): void
    {
        config(['mail.from.name' => 'מולטי דיגיטל']);

        $customer = Customer::factory()->create(['name' => 'לקוח']);
        $broadcast = $this->broadcast(['channel' => BroadcastChannel::Whatsapp, 'body' => 'מבצע']);

        $body = $this->renderer()->body($broadcast, $customer);

        $this->assertStringContainsString('פרסומת', $body);
        $this->assertStringContainsString('מולטי דיגיטל', $body);
        $this->assertStringContainsString('הסר', $body);
    }

    public function test_a_service_announcement_carries_no_advertising_footer(): void
    {
        $customer = Customer::factory()->create();
        $broadcast = $this->broadcast([
            'channel' => BroadcastChannel::Whatsapp,
            'body' => 'תחזוקה בשבת',
            'is_marketing' => false,
        ]);

        $body = $this->renderer()->body($broadcast, $customer);

        $this->assertSame('תחזוקה בשבת', $body);
        $this->assertStringNotContainsString('פרסומת', $body);
    }

    public function test_the_email_footer_carries_a_working_unsubscribe_link(): void
    {
        $customer = Customer::factory()->create();
        $footer = $this->renderer()->emailFooter($this->broadcast(), $customer);

        $this->assertTrue($footer['is_marketing']);
        $this->assertStringContainsString('/marketing/unsubscribe/'.$customer->id, $footer['unsubscribe_url']);

        // Signed, so the id cannot be swapped to unsubscribe somebody else.
        $this->assertStringContainsString('signature=', $footer['unsubscribe_url']);
    }

    public function test_a_service_email_carries_a_fixed_service_footer(): void
    {
        config([
            'mail.from.name' => 'מולטי דיגיטל',
            'billing.branding.email_footer' => 'מולטי דיגיטל · רחוב הרצל 1, תל אביב · 03-0000000',
        ]);

        $customer = Customer::factory()->create(['email' => 'x@b.co.il']);
        $broadcast = $this->broadcast(['body' => 'תחזוקה בשבת', 'is_marketing' => false]);

        $html = (new BroadcastMail(
            $this->renderer()->subject($broadcast, $customer),
            $this->renderer()->body($broadcast, $customer),
            $this->renderer()->emailFooter($broadcast, $customer),
        ))->render();

        $this->assertStringContainsString('הודעת שירות מאת מולטי דיגיטל', $html);
        // The address comes from the shared mail footer, printed once by the layout.
        $this->assertStringContainsString('רחוב הרצל 1, תל אביב', $html);
        $this->assertSame(1, substr_count($html, 'רחוב הרצל 1, תל אביב'));
        // Not advertising: no "פרסומת" heading and no unsubscribe link.
        $this->assertStringNotContainsString('להסרה מרשימת התפוצה', $html);
    }

    public function test_a_marketing_email_carries_the_advertising_footer_and_the_opt_out_link(): void
    {
        config([
            'mail.from.name' => 'מולטי דיגיטל',
            'billing.branding.email_footer' => 'מולטי דיגיטל · רחוב הרצל 1, תל אביב · 03-0000000',
        ]);

        $customer = Customer::factory()->create(['email' => 'x@b.co.il']);
        $broadcast = $this->broadcast(['body' => 'מבצע', 'is_marketing' => true]);

        $html = (new BroadcastMail(
            $this->renderer()->subject($broadcast, $customer),
            $this->renderer()->body($broadcast, $customer),
            $this->renderer()->emailFooter($broadcast, $customer),
        ))->render();

        $this->assertStringContainsString('פרסומת', $html);
        $this->assertStringContainsString('מולטי דיגיטל', $html);
        $this->assertStringContainsString('להסרה מרשימת התפוצה', $html);
        $this->assertStringContainsString('marketing/unsubscribe/'.$customer->id, $html);
    }

    /*
    | ----------------------------------------------------------------
    | Opting out
    | ----------------------------------------------------------------
    */

    public function test_the_unsubscribe_link_removes_the_customer_from_marketing(): void
    {
        $customer = Customer::factory()->create();

        $this->get(app(MarketingPreferences::class)->unsubscribeUrl($customer))
            ->assertOk()
            ->assertSee('הוסרת מרשימת הדיוור');

        $this->assertTrue($customer->fresh()->hasOptedOutOfMarketing());
    }

    public function test_an_unsigned_unsubscribe_link_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        // Without this, anyone could walk the customer table by id.
        $this->get('/marketing/unsubscribe/'.$customer->id)->assertForbidden();

        $this->assertFalse($customer->fresh()->hasOptedOutOfMarketing());
    }

    public function test_an_opted_out_customer_is_skipped_by_a_marketing_broadcast(): void
    {
        Mail::fake();

        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'in@b.co.il']);
        Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'out@b.co.il',
            'marketing_opt_out_at' => now(),
        ]);

        $this->send($this->broadcast(['is_marketing' => true]));

        Mail::assertQueued(BroadcastMail::class, 1);
        Mail::assertNotQueued(BroadcastMail::class, fn (BroadcastMail $m) => $m->hasTo('out@b.co.il'));
    }

    public function test_an_opted_out_customer_still_receives_a_service_announcement(): void
    {
        Mail::fake();

        Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'out@b.co.il',
            'marketing_opt_out_at' => now(),
        ]);

        // Opting out of advertising must not mean being left uninformed that
        // your own site is going down for maintenance.
        $this->send($this->broadcast(['is_marketing' => false]));

        Mail::assertQueued(BroadcastMail::class, 1);
    }

    public function test_the_count_separates_opted_out_customers_from_missing_addresses(): void
    {
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'ok@b.co.il']);
        Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => null]);
        Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'out@b.co.il', 'marketing_opt_out_at' => now(),
        ]);

        $counts = app(BroadcastAudience::class)->summary(BroadcastChannel::Email, null, marketing: true);

        $this->assertSame(1, $counts['reachable']);
        $this->assertSame(1, $counts['unreachable']);
        $this->assertSame(1, $counts['opted_out']);
    }

    public function test_a_test_send_never_carries_a_link_that_could_opt_out_a_real_customer(): void
    {
        Mail::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'email' => 'me@multi.co.il']));
        $sample = Customer::factory()->create(['status' => CustomerStatus::Active, 'email' => 'real@b.co.il']);

        $notification = BroadcastSendActions::sendTest($this->broadcast(['is_marketing' => true]));

        $this->assertSame('הבדיקה נשלחה לתור', $notification->getTitle());

        // BroadcastMail is ShouldQueue, so even a direct send() is queued.
        Mail::assertQueued(BroadcastMail::class, function (BroadcastMail $mail) use ($sample) {
            // The test lands in OUR inbox but the link would act on the sample
            // CUSTOMER — one curious click must not unsubscribe them.
            $this->assertNull($mail->footer['unsubscribe_url']);
            $this->assertStringNotContainsString(
                'marketing/unsubscribe/'.$sample->id, $mail->render(),
            );

            return true;
        });

        $this->assertFalse($sample->fresh()->hasOptedOutOfMarketing());
    }

    public function test_the_plan_placeholder_uses_the_current_subscription_not_a_canceled_one(): void
    {
        $customer = Customer::factory()->create();
        $old = Plan::factory()->create(['name' => 'חבילה ישנה']);
        $current = Plan::factory()->create(['name' => 'חבילה נוכחית']);

        Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $old->id,
            'status' => SubscriptionStatus::Canceled,
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $current->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $broadcast = $this->broadcast(['body' => 'החבילה שלך: {{חבילה}}', 'is_marketing' => false]);

        $this->assertSame('החבילה שלך: חבילה נוכחית', $this->renderer()->body($broadcast, $customer));
    }

    public function test_the_plan_placeholder_agrees_whether_or_not_the_relation_was_eager_loaded(): void
    {
        $customer = Customer::factory()->create();
        $old = Plan::factory()->create(['name' => 'חבילה ישנה']);
        $current = Plan::factory()->create(['name' => 'חבילה נוכחית']);

        Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $old->id, 'status' => SubscriptionStatus::Canceled,
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id, 'plan_id' => $current->id, 'status' => SubscriptionStatus::Active,
        ]);

        $broadcast = $this->broadcast(['body' => '{{חבילה}}', 'is_marketing' => false]);

        // The send job eager-loads; the panel preview may not. Both must agree.
        $lazy = $this->renderer()->body($broadcast, $customer);
        $eager = $this->renderer()->body($broadcast, $customer->fresh()->load('subscriptions.plan'));

        $this->assertSame('חבילה נוכחית', $lazy);
        $this->assertSame($lazy, $eager);
    }

    public function test_a_broadcast_being_sent_survives_a_bulk_delete(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $sending = $this->broadcast(['subject' => 'בשליחה', 'status' => BroadcastStatus::Sending]);
        $draft = $this->broadcast(['subject' => 'טיוטה', 'status' => BroadcastStatus::Draft]);

        Livewire::test(ListBroadcasts::class)
            ->callTableBulkAction('delete', [$sending, $draft]);

        // Deleting mid-send leaves the running job writing to a missing row
        // while the messages keep going out.
        $this->assertDatabaseHas('broadcasts', ['id' => $sending->id]);
        $this->assertDatabaseMissing('broadcasts', ['id' => $draft->id]);
    }

    /*
    | ----------------------------------------------------------------
    | "הסר" over WhatsApp
    | ----------------------------------------------------------------
    */

    public function test_replying_hasser_on_whatsapp_opts_the_customer_out(): void
    {
        $preferences = app(MarketingPreferences::class);
        $customer = Customer::factory()->create();

        $this->assertTrue($preferences->looksLikeOptOut('הסר'));
        $this->assertTrue($preferences->looksLikeOptOut('  הסירו.  '));
        $this->assertTrue($preferences->looksLikeOptOut('STOP'));

        $preferences->optOut($customer, TicketChannel::Whatsapp->value);

        $this->assertTrue($customer->fresh()->hasOptedOutOfMarketing());
    }

    public function test_a_sentence_that_merely_mentions_the_word_is_not_an_opt_out(): void
    {
        // "אל תסירו אותי" is the opposite request; treating it as an opt-out
        // would be worse than missing a real one.
        $preferences = app(MarketingPreferences::class);

        $this->assertFalse($preferences->looksLikeOptOut('אל תסירו אותי מהרשימה'));
        $this->assertFalse($preferences->looksLikeOptOut('אפשר להסיר את התוסף מהאתר?'));
    }

    /*
    | ----------------------------------------------------------------
    | The agent drafting a broadcast
    | ----------------------------------------------------------------
    */

    public function test_the_agent_drafts_a_subject_and_body_from_a_one_line_brief(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()->andReturn([
            'subject' => 'תחזוקה מתוכננת בשבת',
            'body' => "שלום {{שם}},\n\nבשבת בין 02:00 ל-05:00 נבצע תחזוקה.",
        ]);

        $draft = (new BroadcastComposer($ai))->draft('תחזוקה בשבת בלילה', BroadcastChannel::Email, false);

        $this->assertSame('תחזוקה מתוכננת בשבת', $draft['subject']);
        $this->assertStringContainsString('{{שם}}', $draft['body']);
    }

    public function test_the_agent_returns_nothing_rather_than_an_empty_draft(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn(['subject' => 'נושא', 'body' => '   ']);

        // An empty body must not silently become a broadcast the operator then
        // sends to every customer.
        $this->assertNull((new BroadcastComposer($ai))->draft('משהו', BroadcastChannel::Email, true));
    }

    public function test_the_agent_button_is_hidden_when_the_ai_layer_is_off(): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(false);

        $composer = new BroadcastComposer($ai);

        $this->assertFalse($composer->isAvailable());
        $this->assertNull($composer->draft('משהו', BroadcastChannel::Email, true));
    }
}
