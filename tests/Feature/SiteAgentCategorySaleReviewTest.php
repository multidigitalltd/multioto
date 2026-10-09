<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteAgentCategorySaleReviewTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(): SiteAgentRequest
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id]);
        $subscriber = SiteAgentSubscriber::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'phone' => '972501234567', 'verified_at' => now(),
        ]);

        return SiteAgentRequest::create([
            'customer_id' => $customer->id, 'site_id' => $site->id,
            'site_agent_subscriber_id' => $subscriber->id, 'message' => 'מבצע לקטגוריה',
            'operation' => SiteAgentRequest::OP_CATEGORY_SALE, 'state' => SiteAgentRequest::AWAITING,
            'expires_at' => now()->addMinutes(20),
            'plan' => ['prepared' => ['token' => 'PRIVATE-PROPOSAL-TOKEN'], 'category_sale_review' => [
                'category' => ['id' => 9, 'name' => 'נעליים', 'include_children' => true],
                'schedule' => ['starts_at' => null, 'ends_at' => '2026-11-01 20:30', 'timezone' => 'Asia/Jerusalem'],
                'discount_type' => 'percent', 'discount_value' => '20', 'currency' => 'ILS',
                'products' => array_map(fn (int $id): array => [
                    'id' => $id, 'name' => $id === 1 ? '<script>alert(1)</script>' : 'מוצר '.$id,
                    'before' => ['regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => $id === 2 ? (new \DateTimeImmutable('2026-10-10T20:30:00+03:00'))->getTimestamp() : null],
                    'after' => ['regular_price' => '100.00', 'sale_price' => '80.00'],
                ], range(1, 200)),
                'excluded' => [['name' => 'קבוצת מוצרים', 'reason' => 'סוג מוצר ללא מחיר עצמאי']],
                'notes' => ['תזמון לפי שעון האתר'],
            ]],
        ]);
    }

    public function test_full_price_review_requires_the_owning_customer_and_never_applies_the_sale(): void
    {
        $proposal = $this->proposal();
        $url = route('portal.site-agent.change', ['change' => $proposal->id]);
        $this->get($url)->assertRedirect(route('portal.login'));
        $this->withSession(['portal.customer_id' => Customer::factory()->create()->id])->get($url)->assertNotFound();

        $response = $this->withSession(['portal.customer_id' => $proposal->customer_id])->get($url);
        $response->assertOk()->assertSee('מוצר 200')->assertSee('80.00')->assertSee('2026-11-01 20:30')
            ->assertSee('Asia/Jerusalem')->assertSee('10/10/2026 20:30 +03:00')->assertSee('פתיחת העמוד אינה מפעילה את המבצע')
            ->assertDontSee('PRIVATE-PROPOSAL-TOKEN')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(SiteAgentRequest::AWAITING, $proposal->fresh()->state);
    }

    public function test_transferred_site_and_non_category_requests_cannot_leak_through_the_review(): void
    {
        $proposal = $this->proposal();
        $url = route('portal.site-agent.change', ['change' => $proposal->id]);
        $this->withSession(['portal.customer_id' => $proposal->customer_id]);
        $proposal->site->update(['customer_id' => Customer::factory()->create()->id]);
        $this->get($url)->assertNotFound();

        $proposal->site->update(['customer_id' => $proposal->customer_id]);
        $proposal->update(['operation' => SiteAgentRequest::OP_ACF]);
        $this->get($url)->assertNotFound();
    }

    public function test_expired_proposal_is_labeled_as_history_without_requesting_approval(): void
    {
        $proposal = $this->proposal();
        $proposal->update(['expires_at' => now()->subMinute()]);
        $this->withSession(['portal.customer_id' => $proposal->customer_id])
            ->get(route('portal.site-agent.change', ['change' => $proposal->id]))
            ->assertOk()->assertSee('פג תוקף ההצעה')->assertSee('תיעוד ההצעה')
            ->assertDontSee('חזרו להצעה בוואטסאפ');
        $this->assertSame(SiteAgentRequest::AWAITING, $proposal->fresh()->state);
    }
}
