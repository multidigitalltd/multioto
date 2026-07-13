<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
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

        // Logo image at the top, served from the public logo route (a hosted URL —
        // NOT a data: URI, which mail clients like Gmail block).
        $this->assertStringContainsString('/branding/logo', $html);
        $this->assertStringNotContainsString('data:image/png;base64,', $html);
        // The whole email is laid out right-to-left for Hebrew — and the RTL
        // alignment must be an inline !important style, so it survives the CSS
        // inliner and the mail client stripping <style>/<body> (Gmail).
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('text-align: right !important', $html);
        // The configurable footer, and not the framework's default line.
        $this->assertStringContainsString('multidigital.co.il', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        // The reply body is present.
        $this->assertStringContainsString('זה נראה שהכל עובד תקין.', $html);
    }

    public function test_view_based_notification_email_also_gets_the_branded_layout(): void
    {
        // Lifecycle/welcome/payment-link emails use NotificationMail (a view-based
        // mailable) — it must carry the same logo header and footer.
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        ));
        config([
            'billing.branding.logo_path' => 'branding/logo.png',
            'billing.branding.email_footer' => 'Multi Digital · multidigital.co.il',
        ]);

        $html = (new NotificationMail('ברוכים הבאים', 'תודה שנרשמת אלינו.'))->render();

        $this->assertStringContainsString('/branding/logo', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('multidigital.co.il', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringContainsString('תודה שנרשמת אלינו.', $html);
    }

    public function test_the_public_logo_route_serves_the_image_and_404s_without_one(): void
    {
        Storage::fake('public');
        config(['billing.branding.logo_path' => null]);

        $this->get(route('branding.logo'))->assertNotFound();

        Storage::disk('public')->put('branding/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        ));
        config(['billing.branding.logo_path' => 'branding/logo.png']);

        $response = $this->get(route('branding.logo'));
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
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
