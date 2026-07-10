<?php

namespace Tests\Feature;

use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaClientTest extends TestCase
{
    public function test_configure_inbound_webhook_registers_our_endpoint_on_the_session(): void
    {
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        Http::fake(['*/api/sessions/default' => Http::response(['name' => 'default'])]);

        app(WahaClient::class)->configureInboundWebhook('https://app.example/webhooks/waha?secret=s');

        Http::assertSent(function ($request) {
            $webhook = $request->data()['config']['webhooks'][0] ?? [];

            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/api/sessions/default')
                && $webhook['url'] === 'https://app.example/webhooks/waha?secret=s'
                && $webhook['events'] === ['message'];
        });
    }

    public function test_it_normalizes_phone_formats_to_a_whatsapp_chat_id(): void
    {
        config(['billing.waha.default_country_code' => '972']);
        $waha = app(WahaClient::class);

        // Local Israeli number (leading 0) → international, which WAHA requires.
        $this->assertSame('972501234567@c.us', $waha->normalizeChatId('0501234567'));
        // Punctuation is stripped.
        $this->assertSame('972501234567@c.us', $waha->normalizeChatId('+972-50-123-4567'));
        $this->assertSame('972501234567@c.us', $waha->normalizeChatId('972501234567'));
        // An existing JID / chat id is passed through untouched.
        $this->assertSame('123456@g.us', $waha->normalizeChatId('123456@g.us'));
    }
}
