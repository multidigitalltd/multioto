<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LogoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_uploading_a_logo_stores_it_and_saves_the_path(): void
    {
        Storage::fake('public');

        Livewire::test(ManageMail::class)
            ->set('data.branding.logo_path', [UploadedFile::fake()->image('logo.png', 120, 40)])
            ->call('save')
            ->assertHasNoErrors();

        $path = Setting::map()['branding.logo_path'] ?? null;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('branding/', (string) $path);
        Storage::disk('public')->assertExists($path);

        // Branding now resolves the uploaded logo.
        config(['billing.branding.logo_path' => $path]);
        $this->assertNotNull(Branding::logoUrl());
    }

    public function test_saving_with_an_existing_logo_and_no_change_keeps_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/existing.png', 'x');
        config(['billing.branding.logo_path' => 'branding/existing.png']);

        // Mount (fills the field from config) and save without touching the logo.
        Livewire::test(ManageMail::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('branding/existing.png', Setting::map()['branding.logo_path'] ?? null);
    }
}
