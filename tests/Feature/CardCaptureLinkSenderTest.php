<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CardCaptureLinkSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.base_url' => 'http://waha:3000', 'billing.waha.session' => 'default']);
    }

    public function test_it_reports_each_channel_that_delivered(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'msg-1'])]);

        $subscription = Subscription::factory()->create();
        $subscription->customer->update(['phone' => '+972501234567', 'email' => 'c@example.co']);

        $result = app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        $this->assertContains('וואטסאפ', $result['sent']);
        $this->assertContains('אימייל', $result['sent']);
        $this->assertSame([], $result['failed']);
        $this->assertStringContainsString('/billing/update-card/', $result['link']);
    }

    public function test_it_reports_a_whatsapp_failure_instead_of_claiming_success(): void
    {
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['error' => 'session not working'], 500)]);

        $subscription = Subscription::factory()->create();
        $subscription->customer->update(['phone' => '+972501234567', 'email' => null, 'whatsapp_jid' => null]);

        $result = app(CardCaptureLinkSender::class)->send($subscription->load('customer', 'plan'));

        $this->assertSame([], $result['sent']);
        $this->assertNotEmpty($result['failed']);
        $this->assertStringContainsString('וואטסאפ', $result['failed'][0]);
    }
}
