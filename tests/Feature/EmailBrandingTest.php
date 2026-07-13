<?php

namespace Tests\Feature;

use App\Mail\TicketReplyMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_email_shows_the_logo_and_the_configured_footer(): void
    {
        // A tiny valid PNG so the logo resolves to a real data: URI.
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        ));
        config([
            'billing.branding.logo_path' => 'branding/logo.png',
            'billing.branding.email_footer' => 'Multi Digital · multidigital.co.il · 03-0000000',
        ]);

        $html = (new TicketReplyMail('נושא הפנייה', 'זה נראה שהכל עובד תקין.'))->render();

        // Logo image at the top instead of the plain app-name text.
        $this->assertStringContainsString('data:image/png;base64,', $html);
        // The configurable footer, and not the framework's default line.
        $this->assertStringContainsString('multidigital.co.il', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        // The reply body is present.
        $this->assertStringContainsString('זה נראה שהכל עובד תקין.', $html);
    }

    public function test_footer_falls_back_to_the_sender_name_when_unset(): void
    {
        config([
            'billing.branding.logo_path' => null,
            'billing.branding.email_footer' => null,
            'mail.from.name' => 'Multi Digital',
        ]);

        $html = (new TicketReplyMail('נושא', 'גוף'))->render();

        $this->assertStringContainsString('Multi Digital', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }
}
