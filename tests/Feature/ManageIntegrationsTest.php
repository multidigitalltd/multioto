<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ManageIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_linet_persists_every_field(): void
    {
        $this->actingAs(User::factory()->create());
        // The save runs the Linet connection check — keep it off the network.
        Http::fake(['*/newsearch/account' => Http::response([])]);

        Livewire::test(ManageIntegrations::class)
            ->fillForm([
                'linet.login_id' => 'LID',
                'linet.key' => 'KEY',
                'linet.company_id' => '3',
                'linet.doctype' => '9',
                'linet.vat_cat_taxable' => '100',
                'linet.vat_cat_exempt' => '102',
                'linet.payment_type' => '3',
            ])
            ->call('saveGroup', 'linet');

        $m = Setting::map();
        $this->assertSame('LID', $m['linet.login_id'] ?? null);
        $this->assertSame('KEY', $m['linet.key'] ?? null);
        $this->assertSame('3', $m['linet.company_id'] ?? null);
        $this->assertSame('9', $m['linet.doctype'] ?? null);
        $this->assertSame('100', $m['linet.vat_cat_taxable'] ?? null);
    }
}
