<?php

namespace Tests\Feature;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use App\Enums\NotificationType;
use App\Filament\Resources\NotificationLogResource;
use App\Filament\Resources\NotificationLogResource\Pages\ListNotificationLogs;
use App\Jobs\SendDunningNotificationJob;
use App\Jobs\SendPaymentLinkJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Customer;
use App\Models\DunningEvent;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\ManualChargeService;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['*' => Http::response(['id' => 'stub'])]);
    }

    public function test_a_dunning_email_is_recorded(): void
    {
        $customer = Customer::factory()->create(['email' => 'debtor@example.com']);
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $event = DunningEvent::create([
            'subscription_id' => $subscription->id,
            'stage' => 1,
            'channel' => DunningChannel::Email,
            'template_key' => 'stage_1',
            'status' => DunningStatus::Queued,
        ]);

        (new SendDunningNotificationJob($event->id))->handle(app(WahaClient::class));

        $log = NotificationLog::sole();
        $this->assertSame('email', $log->channel);
        $this->assertSame(NotificationType::Dunning, $log->type);
        $this->assertSame($customer->id, $log->customer_id);
        $this->assertSame('debtor@example.com', $log->recipient);
        $this->assertSame('sent', $log->status);
    }

    public function test_a_welcome_message_is_recorded_for_each_channel(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'new@example.com',
            'phone' => '0501234567',
        ]);

        (new SendWelcomeMessageJob($customer->id))->handle(
            app(TemplateEngine::class),
            app(WahaClient::class),
        );

        $this->assertSame(2, NotificationLog::where('type', NotificationType::Welcome)->count());
        $this->assertTrue(NotificationLog::where('channel', 'email')->exists());
        $this->assertTrue(NotificationLog::where('channel', 'whatsapp')->exists());
    }

    public function test_a_payment_link_email_is_recorded(): void
    {
        $customer = Customer::factory()->create(['email' => 'payer@example.com']);

        // Stub the hosted-page creation so no real Cardcom call happens.
        $service = \Mockery::mock(ManualChargeService::class);
        $service->shouldReceive('createHostedPage')->andReturn(['url' => 'https://pay.example/abc']);

        (new SendPaymentLinkJob($customer->id, 11800, 'תשלום', 'email'))->handle(
            $service,
            app(TemplateEngine::class),
            app(WahaClient::class),
        );

        $log = NotificationLog::where('type', NotificationType::PaymentLink)->sole();
        $this->assertSame('email', $log->channel);
        $this->assertSame('payer@example.com', $log->recipient);
        $this->assertStringContainsString('pay.example/abc', (string) $log->body);
    }

    public function test_the_log_page_renders_and_records_are_read_only(): void
    {
        $this->actingAs(User::factory()->create());
        NotificationLog::factory()->count(3)->create();

        Livewire::test(ListNotificationLogs::class)
            ->assertOk()
            ->assertCountTableRecords(3);

        $this->assertFalse(NotificationLogResource::canCreate());
    }
}
