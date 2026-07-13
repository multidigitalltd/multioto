<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\SiteResource;
use App\Filament\Resources\TicketResource;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCardViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_customer_card_links_each_ticket_and_offers_add_card(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה על החשבונית',
            'status' => TicketStatus::Open,
        ]);
        PaymentToken::factory()->create(['customer_id' => $customer->id]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee('שאלה על החשבונית')
            // The ticket subject links to its own view page.
            ->assertSee(TicketResource::getUrl('view', ['record' => $ticket]))
            // The saved-cards section offers adding a card (opens the Cardcom iframe).
            ->assertSee('הוספת כרטיס');
    }

    public function test_the_customer_card_links_each_site_to_its_monitoring_page(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'example.co.il']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee('example.co.il')
            // The site links to its monitoring page (uptime, response, SSL, probes).
            ->assertSee(SiteResource::getUrl('view', ['record' => $site]));
    }
}
