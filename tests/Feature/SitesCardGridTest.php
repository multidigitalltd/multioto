<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Sites screen renders as a card grid: each site is a clickable card
 * (linking to its page) with its key status on it, not a dense table row.
 */
class SitesCardGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sites_list_renders_each_site_as_a_card_linking_to_its_page(): void
    {
        $this->actingAs(User::factory()->create()); // factory default = admin

        $customer = Customer::factory()->create(['name' => 'מאפיית השחר']);
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain' => 'shachar-bakery.co.il',
            'mcp_enabled' => true,
        ]);

        Livewire::test(ListSites::class)
            ->assertOk()
            ->assertSee('shachar-bakery.co.il')
            ->assertSee('מאפיית השחר')
            // The whole card is a link to the site's page (full info + options).
            ->assertSee(SiteResource::getUrl('view', ['record' => $site]));
    }
}
