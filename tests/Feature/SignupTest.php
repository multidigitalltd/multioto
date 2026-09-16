<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\MessageAuthor;
use App\Enums\TicketChannel;
use App\Jobs\GenerateCustomerCardPdfJob;
use App\Jobs\NotifySignupJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\PendingSignup;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Services\Notifications\TeamNotifier;
use App\Services\Notifications\TemplateEngine;
use App\Services\Signup\CompleteSignup;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal valid 1×1 PNG as the canvas would produce it. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Every required field for a valid submission; override per test. */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'עסק חדש',
            'contact_name' => 'ישראל ישראלי',
            'business_type' => BusinessType::LicensedDealer->value,
            'email' => 'New@Example.CO.il',
            'phone' => '0501234567',
            'domain' => 'https://newbiz.co.il',
            'payment_method' => 'credit_card',
            'terms' => '1',
            'signature' => self::SIGNATURE,
        ], $overrides);
    }

    public function test_the_public_signup_page_collects_details_without_a_plan(): void
    {
        // The multi-step form opens a customer + captures a card/consent; the
        // plan is set up by the team afterwards, so no plan picker appears here.
        $this->get(route('signup'))
            ->assertOk()
            ->assertSee('טופס פתיחת כרטיס לקוח')
            ->assertSee('חתימה')
            // The tax-approval notice (file number) shows on the payment step.
            ->assertSee('516171303')
            ->assertDontSee('בחירת מסלול');
    }

    public function test_the_tax_notice_is_hidden_when_cleared(): void
    {
        // An explicitly-stored empty value hides the notice (not a revert to default).
        Setting::put('signup.tax_approval_notice', '');

        $this->get(route('signup'))
            ->assertOk()
            ->assertDontSee('516171303');
    }

    public function test_new_client_alias_reaches_the_signup_form(): void
    {
        $this->get('/new-client')->assertRedirect('/join');
    }

    public function test_submitting_the_form_files_a_signup_but_opens_no_customer(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);
        Storage::fake('local');

        $response = $this->post(route('signup.store'), $this->validPayload());

        $response->assertRedirectContains('/join/card/');

        // The card page used to be a last step AFTER the customer was saved, so
        // closing the tab left a customer nobody could collect from. Now
        // nothing is in `customers`, nothing is monitored and nobody has been
        // welcomed — none of it has been earned yet.
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Site::count());
        $this->assertSame(0, Subscription::count());
        Queue::assertNothingPushed();

        $pending = PendingSignup::sole();
        $this->assertSame('new@example.co.il', $pending->email); // normalized
        $this->assertSame('ישראל ישראלי', $pending->contact_name);
        $this->assertSame('credit_card', $pending->payment_method);
        $this->assertNotNull($pending->terms_accepted_at); // consent record
        $this->assertSame('newbiz.co.il', $pending->domain); // scheme stripped

        // The signature is stored privately as the consent record, with the IP.
        $this->assertNotNull($pending->signature_path);
        Storage::disk('local')->assertExists($pending->signature_path);
        $this->assertNotNull($pending->signed_ip);
    }

    public function test_the_card_is_what_opens_the_customer(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);
        Storage::fake('local');

        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();

        $customer = $this->completeWithCard(PendingSignup::sole());

        $this->assertNotNull($customer);
        $this->assertSame('new@example.co.il', $customer->email);
        $this->assertSame('newbiz.co.il', $customer->sites()->value('domain'));
        // And the card is on file — which is the whole point of the ordering.
        $this->assertTrue($customer->hasActiveCard());

        // No subscription is created here — the plan is custom and set up later.
        $this->assertSame(0, Subscription::count());

        Queue::assertPushed(SendWelcomeMessageJob::class, 1);
        Queue::assertPushed(GenerateCustomerCardPdfJob::class, 1);
        Queue::assertPushed(NotifySignupJob::class, fn (NotifySignupJob $job): bool => $job->customerId === $customer->id);
    }

    public function test_the_signup_notification_reaches_the_team_on_email_and_whatsapp(): void
    {
        config([
            'billing.notifications.team_email' => 'team@multidigital.co.il',
            'billing.waha.owner_number' => '972500000000',
        ]);
        Mail::fake();
        Http::fake(['*' => Http::response(['id' => 'w'])]);

        $customer = Customer::factory()->create([
            'name' => 'עסק חדש בע״מ',
            'payment_method' => 'credit_card',
        ]);

        (new NotifySignupJob($customer->id))->handle(app(TeamNotifier::class));

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('team@multidigital.co.il')
            && str_contains($mail->subjectLine, 'לקוח חדש נרשם')
            && str_contains($mail->subjectLine, 'עסק חדש בע״מ'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'] ?? '', 'לקוח חדש נרשם'));
    }

    public function test_signup_validates_field_formats(): void
    {
        // Bad email, non-9-digit business number, non-Israeli phone.
        $this->post(route('signup.store'), $this->validPayload([
            'email' => 'not-an-email',
            'business_number' => '12345',
            'phone' => '12345',
        ]))->assertSessionHasErrors(['email', 'business_number', 'phone']);

        $this->assertSame(0, PendingSignup::count());
    }

    public function test_signup_accepts_a_nonprofit_and_a_dashed_phone(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $this->post(route('signup.store'), $this->validPayload([
            'business_type' => BusinessType::Nonprofit->value,
            'business_number' => '58-012-3456', // dashes stripped → 9 digits
            'phone' => '050-123-4567',
        ]))->assertRedirect();

        $customer = PendingSignup::sole();
        $this->assertSame(BusinessType::Nonprofit->value, $customer->business_type);
        $this->assertSame('580123456', $customer->business_number);
        $this->assertSame('0501234567', $customer->phone);
    }

    public function test_signup_requires_a_signature(): void
    {
        $this->post(route('signup.store'), $this->validPayload(['signature' => '']))
            ->assertSessionHasErrors('signature');

        $this->assertSame(0, PendingSignup::count());
    }

    public function test_signup_rejects_a_non_png_signature(): void
    {
        // Anything that isn't a PNG data URL (e.g. an SVG/script payload) is refused.
        $this->post(route('signup.store'), $this->validPayload([
            'signature' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
        ]))->assertSessionHasErrors('signature');

        $this->assertSame(0, PendingSignup::count());
    }

    public function test_checks_signup_opens_a_follow_up_ticket(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        // Every method now ends at the security-card page, cheques included —
        // and the follow-up ticket waits for the card, because until then there
        // is no customer to open it against.
        $this->post(route('signup.store'), $this->validPayload(['payment_method' => 'checks']))
            ->assertRedirectContains('/join/card/');

        $this->assertSame(0, Ticket::count());
        $this->completeWithCard(PendingSignup::sole());

        $ticket = Ticket::sole();
        $this->assertStringContainsString('צ׳קים', $ticket->messages()->first()->body);
    }

    public function test_bank_transfer_signup_opens_a_follow_up_ticket_and_still_asks_for_a_card(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $response = $this->post(route('signup.store'), $this->validPayload([
            'payment_method' => 'bank_transfer',
        ]));

        // A card IS now asked for — as security, not to be charged — alongside
        // the internal follow-up ticket for the transfer arrangement itself.
        $response->assertRedirectContains('/join/card/');

        $this->completeWithCard(PendingSignup::sole());

        $ticket = Ticket::sole();
        $this->assertSame(TicketChannel::Manual, $ticket->channel);
        $this->assertStringContainsString('השלמת הסדר תשלום', $ticket->subject);
        // Internal ticket — the customer must NOT get a "we received your inquiry" ack.
        $this->assertSame(0, $ticket->messages()->where('author', MessageAuthor::System)->count());

        Queue::assertPushed(SendWelcomeMessageJob::class, 1);
    }

    /**
     * לחיצה שנייה על "אישור וסיום" אינה לקוח שני.
     *
     * הטופס שולח את החתימה כתמונה, ולכן השליחה אורכת רגע. לקוח שלא ראה שקרה
     * משהו לוחץ שוב — וכל לחיצה פתחה עד עכשיו לקוח נוסף, אתר נוסף בניטור,
     * הודעת ברוכים־הבאים נוספת ופנייה נוספת בתור. זו הסיבה שאותה פנייה
     * ("השלמת הסדר תשלום") הופיעה שש פעמים תוך דקה.
     */
    public function test_sending_the_same_form_again_does_not_open_a_second_customer(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $payload = $this->validPayload(['payment_method' => 'standing_order']);

        foreach (range(1, 6) as $ignored) {
            $this->post(route('signup.store'), $payload)
                ->assertRedirectContains('/join/card/');
        }

        // One filing from six clicks — and once the card lands, one customer,
        // one site, one ticket, one welcome.
        $this->assertSame(1, PendingSignup::count());

        $this->completeWithCard(PendingSignup::sole());

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Customer::sole()->sites()->count());
        Queue::assertPushed(SendWelcomeMessageJob::class, 1);
        Queue::assertPushed(NotifySignupJob::class, 1);
        Queue::assertPushed(GenerateCustomerCardPdfJob::class, 1);
    }

    /** ובכרטיס אשראי — הלחיצה השנייה מחזירה לאותו לקוח, לא פותחת חדש. */
    public function test_a_repeated_card_signup_returns_to_the_same_customers_card_page(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();
        $customer = PendingSignup::sole();

        $again = $this->post(route('signup.store'), $this->validPayload());

        $this->assertSame(1, PendingSignup::count());
        $this->assertStringContainsString('/join/card/', (string) $again->headers->get('Location'));
        $this->assertSame($customer->id, PendingSignup::sole()->id);
    }

    /**
     * טופס שמולא אחרת הוא הרשמה אחרת — גם אם המייל זהה.
     *
     * הכיוון הזה חשוב: איחוד של שתי שליחות שנבדלות בשדה כלשהו היה מוחק בשקט
     * את מה שהלקוח שינה, וזה גרוע מכפילות.
     */
    public function test_a_submission_that_differs_is_a_new_signup(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();
        $this->post(route('signup.store'), $this->validPayload(['phone' => '0521234567']))->assertRedirect();

        $this->assertSame(2, PendingSignup::count());
    }

    /**
     * אותו עסק שממלא את הטופס שוב עבור אתר שני — הוא הרשמה שנייה.
     *
     * כל שאר השדות זהים, ולכן בדיקה שאינה מסתכלת על הדומיין הייתה מאחדת את
     * השתיים ומשמיטה את האתר החדש מהניטור בלי לומר מילה.
     */
    public function test_a_second_domain_is_a_new_signup_and_reaches_monitoring(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();
        $this->post(route('signup.store'), $this->validPayload(['domain' => 'second-site.co.il']))->assertRedirect();

        $this->assertSame(2, PendingSignup::count());

        PendingSignup::orderBy('id')->get()->each(fn (PendingSignup $p) => $this->completeWithCard($p));

        $this->assertSame(
            ['newbiz.co.il', 'second-site.co.il'],
            Site::orderBy('id')->pluck('domain')->all(),
        );
    }

    /** ומעבר לחלון הזמן, הרשמה חוזרת היא שוב הרשמה. */
    public function test_the_same_details_after_the_window_open_a_new_signup(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);
        config(['billing.signup.duplicate_window_minutes' => 30]);

        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();

        $this->travel(31)->minutes();
        $this->post(route('signup.store'), $this->validPayload())->assertRedirect();

        $this->assertSame(2, PendingSignup::count());
    }

    /**
     * שליחה שלא הצליחה לקבל את הנעילה מוחזרת — ולא נכנסת בכל זאת.
     *
     * המשך ריצה בלי הנעילה היה מכניס שתי בקשות בדיוק לקטע שהנעילה קיימת כדי
     * להחזיק בו אחת בכל רגע: שתיהן קוראות טבלה ריקה ושתיהן פותחות לקוח. עדיף
     * לבקש ללחוץ שוב מאשר לפתוח את הכפילות שבאנו למנוע.
     */
    public function test_a_submission_that_cannot_take_the_lock_is_handed_back(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);
        config(['billing.signup.lock_wait_seconds' => 0]);

        // Mirrors SignupController::fingerprint(). If that changes, this lock
        // stops being the one the controller takes and the test fails — which
        // is the point: the guard is only worth having while it holds.
        $lock = Cache::lock('signup:'.hash('sha256', implode('|', [
            'new@example.co.il',
            'עסק חדש',
            'ישראל ישראלי',
            '0501234567',
            BusinessType::LicensedDealer->value,
            'credit_card',
            '',
            'newbiz.co.il',
            '', // no invite
        ])), 30);

        $this->assertTrue($lock->get());

        $this->post(route('signup.store'), $this->validPayload())
            ->assertSessionHasErrors('signup');

        $this->assertSame(0, PendingSignup::count());

        $lock->release();
    }

    public function test_exempt_dealer_signup_is_marked_vat_exempt(): void
    {
        Queue::fake([SendWelcomeMessageJob::class, GenerateCustomerCardPdfJob::class, NotifySignupJob::class]);

        $this->post(route('signup.store'), $this->validPayload([
            'name' => 'עוסק פטור',
            'business_type' => BusinessType::ExemptDealer->value,
            'email' => 'patur@example.co.il',
        ]))->assertRedirect();

        $this->assertTrue(PendingSignup::first()->vat_exempt);
    }

    public function test_signup_validates_required_fields(): void
    {
        $this->post(route('signup.store'), [])
            ->assertSessionHasErrors(['name', 'contact_name', 'business_type', 'email', 'phone', 'payment_method', 'terms', 'signature']);

        $this->assertSame(0, PendingSignup::count());
    }

    public function test_signup_rejects_a_honeypot_submission(): void
    {
        $this->post(route('signup.store'), $this->validPayload([
            'website' => 'http://spam.example',
        ]))->assertSessionHasErrors('website');

        $this->assertSame(0, PendingSignup::count());
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

    /**
     * Cardcom captured a card for this signup — the moment the customer is
     * created. The flow's whole shape is that nothing exists before this point.
     */
    private function completeWithCard(PendingSignup $pending): Customer
    {
        return app(CompleteSignup::class)->withCard($pending, [
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-'.$pending->id, 'CardMonth' => 12, 'CardYear' => (int) now()->addYears(3)->format('Y')],
            'TranzactionInfo' => ['Last4CardDigits' => '4580', 'CardName' => 'Visa'],
        ]);
    }
}
