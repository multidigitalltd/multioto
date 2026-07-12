<?php

namespace Tests\Feature;

use App\Support\Branding;
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
}
