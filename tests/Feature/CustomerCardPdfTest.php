<?php

namespace Tests\Feature;

use App\Jobs\GenerateCustomerCardPdfJob;
use App\Mail\CustomerCardMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerCardPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_it_generates_stores_and_emails_the_signed_card(): void
    {
        Mail::fake();
        Storage::fake('local');

        $customer = Customer::factory()->create([
            'email' => 'client@example.co.il',
            'signature_path' => 'signatures/sig.png',
        ]);
        Storage::disk('local')->put($customer->signature_path, base64_decode(self::PNG));

        (new GenerateCustomerCardPdfJob($customer->id))->handle();

        $customer->refresh();
        $this->assertNotNull($customer->signed_pdf_path);
        Storage::disk('local')->assertExists($customer->signed_pdf_path);
        // A real PDF was written.
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($customer->signed_pdf_path));

        Mail::assertSent(CustomerCardMail::class, fn ($mail) => $mail->hasTo('client@example.co.il'));
    }

    public function test_it_skips_a_customer_without_a_signature(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['signature_path' => null]);

        (new GenerateCustomerCardPdfJob($customer->id))->handle();

        $this->assertNull($customer->fresh()->signed_pdf_path);
        Mail::assertNothingSent();
    }

    public function test_it_fails_rather_than_produce_an_unsigned_card(): void
    {
        Mail::fake();
        Storage::fake('local');
        // Path set, but the file is not on this worker's disk.
        $customer = Customer::factory()->create(['signature_path' => 'signatures/missing.png']);

        try {
            (new GenerateCustomerCardPdfJob($customer->id))->handle();
            $this->fail('Expected the job to throw when the signature file is missing.');
        } catch (\RuntimeException) {
            // Expected — the queue retries instead of emailing a blank consent doc.
        }

        $this->assertNull($customer->fresh()->signed_pdf_path);
        Mail::assertNothingSent();
    }

    public function test_the_pdf_route_requires_auth_and_serves_the_file(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->create(['signed_pdf_path' => 'customer-cards/card.pdf']);
        Storage::disk('local')->put($customer->signed_pdf_path, '%PDF-1.4 test');

        // Unauthenticated → redirected to login.
        $this->get(route('customer.card-pdf', $customer))->assertRedirect();

        $this->actingAs(User::factory()->create());
        $this->get(route('customer.card-pdf', $customer))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
