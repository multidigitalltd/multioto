<?php

namespace Tests\Unit;

use App\Models\Customer;
use PHPUnit\Framework\TestCase;

class CustomerRecipientTest extends TestCase
{
    public function test_it_uses_the_whatsapp_jid_when_present(): void
    {
        $c = new Customer(['whatsapp_jid' => '972500000000@c.us', 'phone' => '0500000000']);

        $this->assertSame('972500000000@c.us', $c->whatsappRecipient());
    }

    public function test_it_falls_back_to_the_phone_when_the_jid_is_empty(): void
    {
        $this->assertSame('0500000000', (new Customer(['whatsapp_jid' => '', 'phone' => '0500000000']))->whatsappRecipient());
        $this->assertSame('0500000000', (new Customer(['whatsapp_jid' => null, 'phone' => '0500000000']))->whatsappRecipient());
    }

    public function test_it_is_null_when_neither_is_set(): void
    {
        $this->assertNull((new Customer(['whatsapp_jid' => null, 'phone' => null]))->whatsappRecipient());
    }
}
