<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\SiteResource;
use App\Filament\Resources\TicketResource;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    /**
     * דרישת תשלום מתוך כרטיס הלקוח — אותה דרישה בדיוק כמו במסך הייעודי.
     *
     * הכפתור יושב ליד "שליחת קישור תשלום" וההבדל אינו ניסוח: דרישה מנפיקה
     * חשבונית עסקה, מקבלת תאריך יעד ונכנסת למעקב הגבייה.
     */
    public function test_a_demand_can_be_opened_from_the_customer_card(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('paymentDemand', data: [
                'description' => 'בניית אתר',
                'items' => [],
                'amount' => 500,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job) use ($customer): bool {
            return $job->customerId === $customer->id
                && $job->totalAgorot === 50000
                // בקשה לשלם, לא חיוב: העברה בנקאית ראשונה וקישור אחריה.
                && $job->methods === ['transfer', 'link'];
        });
    }
}
