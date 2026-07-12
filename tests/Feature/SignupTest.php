<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\MessageAuthor;
use App\Enums\TicketChannel;
use App\Jobs\SendWelcomeMessageJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    /** Every required field for a valid submission; override per test. */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'עסק חדש',
            'contact_name' => 'ישראל ישראלי',
            'business_type' => BusinessType::LicensedDealer->value,
            'email' => 'New@Example.CO.il',
            'phone' => '0501234567',
            'address' => 'הרצל 1, תל אביב',
            'domain' => 'https://newbiz.co.il',
            'payment_method' => 'credit_card',
            'terms' => '1',
        ], $overrides);
    }

    public function test_the_public_signup_page_collects_details_without_a_plan(): void
    {
        // The form opens a customer + captures a card; the plan is set up by the
        // team afterwards, so no plan picker appears here.
        $this->get(route('signup'))
            ->assertOk()
            ->assertSee('פרטי העסק')
            ->assertDontSee('בחירת מסלול');
    }

    public function test_new_client_alias_reaches_the_signup_form(): void
    {
        $this->get('/new-client')->assertRedirect('/join');
    }

    public function test_signup_creates_a_customer_and_redirects_to_card_capture(): void
    {
        Queue::fake([SendWelcomeMessageJob::class]);

        $response = $this->post(route('signup.store'), $this->validPayload());

        // Redirects to the signed Cardcom card-capture link.
        $response->assertRedirect();
        $this->assertStringContainsString('/billing/update-card/', $response->headers->get('Location'));
        $this->assertStringContainsString('signature=', $response->headers->get('Location'));

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('new@example.co.il', $customer->email); // normalized
        $this->assertSame('ישראל ישראלי', $customer->contact_name);
        $this->assertSame('הרצל 1, תל אביב', $customer->address);
        $this->assertSame('credit_card', $customer->payment_method);
        $this->assertNotNull($customer->terms_accepted_at); // consent record
        $this->assertSame('newbiz.co.il', $customer->sites()->value('domain')); // scheme stripped

        // No subscription is created here — the plan is custom and set up later.
        $this->assertSame(0, Subscription::count());

        // The personal welcome goes out exactly once.
        Queue::assertPushed(SendWelcomeMessageJob::class, 1);
    }

    public function test_bank_transfer_signup_opens_a_follow_up_ticket_instead_of_card_capture(): void
    {
        Queue::fake([SendWelcomeMessageJob::class]);

        $response = $this->post(route('signup.store'), $this->validPayload([
            'payment_method' => 'bank_transfer',
        ]));

        // No Cardcom hand-off — a thank-you page plus an internal follow-up ticket.
        $response->assertRedirect(route('signup.thanks'));

        $ticket = Ticket::sole();
        $this->assertSame(TicketChannel::Manual, $ticket->channel);
        $this->assertStringContainsString('השלמת הסדר תשלום', $ticket->subject);
        // Internal ticket — the customer must NOT get a "we received your inquiry" ack.
        $this->assertSame(0, $ticket->messages()->where('author', MessageAuthor::System)->count());

        Queue::assertPushed(SendWelcomeMessageJob::class, 1);
    }

    public function test_exempt_dealer_signup_is_marked_vat_exempt(): void
    {
        Queue::fake([SendWelcomeMessageJob::class]);

        $this->post(route('signup.store'), $this->validPayload([
            'name' => 'עוסק פטור',
            'business_type' => BusinessType::ExemptDealer->value,
            'email' => 'patur@example.co.il',
        ]))->assertRedirect();

        $this->assertTrue(Customer::first()->vat_exempt);
    }

    public function test_signup_validates_required_fields(): void
    {
        $this->post(route('signup.store'), [])
            ->assertSessionHasErrors(['name', 'contact_name', 'business_type', 'email', 'phone', 'address', 'payment_method', 'terms']);

        $this->assertSame(0, Customer::count());
    }

    public function test_signup_rejects_a_honeypot_submission(): void
    {
        $this->post(route('signup.store'), $this->validPayload([
            'website' => 'http://spam.example',
        ]))->assertSessionHasErrors('website');

        $this->assertSame(0, Customer::count());
    }

    public function test_welcome_job_sends_email_and_whatsapp(): void
    {
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default', 'mail.from.name' => 'מולטי דיגיטל']);
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);

        $customer = Customer::factory()->create(['contact_name' => 'דנה', 'phone' => '0501234567']);

        (new SendWelcomeMessageJob($customer->id))->handle(
            app(TemplateEngine::class),
            app(WahaClient::class),
        );

        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, 'דנה'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'ברוכים הבאים'));
    }
}
