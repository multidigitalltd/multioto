<?php

namespace Tests\Feature;

use App\Filament\Pages\OnboardCustomer;
use App\Jobs\SendJoinInviteJob;
use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class JoinInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_onboard_page_dispatches_a_join_invite(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create()); // factory default = admin

        Livewire::test(OnboardCustomer::class)
            ->callAction('sendJoinInvite', data: [
                'name' => 'עסק חדש',
                'phone' => '0501234567',
                'email' => 'prospect@example.co.il',
            ]);

        Queue::assertPushed(SendJoinInviteJob::class, fn (SendJoinInviteJob $job): bool => $job->name === 'עסק חדש'
            && $job->email === 'prospect@example.co.il'
            && $job->phone === '0501234567');
    }

    public function test_the_invite_requires_a_phone_or_email(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(OnboardCustomer::class)
            ->callAction('sendJoinInvite', data: ['name' => 'ללא דרך קשר']);

        Queue::assertNotPushed(SendJoinInviteJob::class);
    }

    public function test_the_invite_job_sends_a_prefilled_link_on_email_and_whatsapp(): void
    {
        config(['billing.waha.owner_number' => '972500000000']);
        Mail::fake();
        Http::fake(['*' => Http::response(['id' => 'w'])]);

        (new SendJoinInviteJob('דנה כהן', 'dana@example.co.il', '0501112222'))->handle(app(WahaClient::class));

        // Email carries the /join link.
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => $mail->hasTo('dana@example.co.il')
            && str_contains($mail->bodyText, '/join')
            && str_contains($mail->bodyText, 'דנה כהן'));
        // WhatsApp carries the same link, prefilled with the email.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'] ?? '', '/join')
            && str_contains($request->data()['text'] ?? '', 'dana%40example.co.il'));
    }

    public function test_the_signup_form_prefills_from_the_invite_query(): void
    {
        $this->get(route('signup', ['name' => 'עסק ממולא', 'email' => 'pre@example.co.il', 'phone' => '0509998888']))
            ->assertOk()
            ->assertSee('עסק ממולא', escape: false)
            ->assertSee('pre@example.co.il')
            ->assertSee('0509998888');
    }

    public function test_the_signup_form_ignores_array_prefill_params_without_erroring(): void
    {
        // A crafted public request (?name[]=x) must not 500 the escaping.
        $this->get('/join?name[]=x&email[]=y&phone[]=z')->assertOk();
    }

    public function test_the_invite_job_fails_when_every_channel_fails(): void
    {
        // A mail transport failure with no WhatsApp channel → nothing delivered,
        // so the job must throw (queue retries) rather than silently succeed.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->expectException(\RuntimeException::class);

        (new SendJoinInviteJob('דנה', 'dana@example.co.il', null))->handle(app(WahaClient::class));
    }
}
