<?php

namespace Tests\Feature;

use App\Support\Branding;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_url_is_absolute_and_not_double_prefixed(): void
    {
        config(['app.url' => 'https://app.example.com']);
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'x');
        config(['billing.branding.logo_path' => 'branding/logo.png']);

        $url = Branding::logoUrl();

        // Must be exactly the disk URL — not the app URL concatenated in front of
        // it again (the old bug produced "…example.comhttps://…example.com/…").
        $this->assertSame(Storage::disk('public')->url('branding/logo.png'), $url);
        $this->assertStringNotContainsString('comhttps', (string) $url);
    }

    public function test_logo_helpers_are_null_when_no_logo_is_set(): void
    {
        config(['billing.branding.logo_path' => null]);

        $this->assertNull(Branding::logoUrl());
        $this->assertNull(Branding::logoDataUri());
    }

    public function test_email_footer_uses_the_setting_or_a_sensible_default(): void
    {
        config(['billing.branding.email_footer' => null, 'mail.from.name' => 'Multi Digital']);
        $footer = Branding::emailFooter();
        $this->assertStringContainsString('Multi Digital', $footer);
        $this->assertStringContainsString((string) date('Y'), $footer);

        config(['billing.branding.email_footer' => 'רחוב הדוגמה 1 · 03-0000000']);
        $this->assertSame('רחוב הדוגמה 1 · 03-0000000', Branding::emailFooter());
    }

    public function test_the_panel_favicon_follows_the_business_logo(): void
    {
        $panel = Filament::getPanel('admin');

        // No logo → no custom favicon (Filament falls back to the default).
        config(['billing.branding.logo_path' => null]);
        $this->assertNull($panel->getFavicon());

        // Logo set → the favicon is that logo's URL.
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'x');
        config(['billing.branding.logo_path' => 'branding/logo.png']);

        $this->assertSame(Branding::logoUrl(), $panel->getFavicon());
    }
}
