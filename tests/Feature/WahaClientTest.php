<?php

namespace Tests\Feature;

use App\Services\Waha\WahaClient;
use Tests\TestCase;

class WahaClientTest extends TestCase
{
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
