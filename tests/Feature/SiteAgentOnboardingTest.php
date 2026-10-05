<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\RefreshSiteCapabilitiesJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Where a customer gets the plugin, the codes and the guide — after the page
 * that followed their payment is long closed, or when the team set them up.
 *
 * The codes are the keys to the customer's website, so most of these tests are
 * about who may NOT see them.
 */
class SiteAgentOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Site $site;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('siteagent.enabled', '1');
        SettingsServiceProvider::refreshFromDatabase();

        $plan = Plan::create([
            'name' => 'בוט ניהול האתר', 'price_agorot' => 14900, 'vat_applies' => true,
            'billing_interval' => 'monthly', 'active' => true, 'is_public' => true, 'includes_site_agent' => true,
        ]);

        $this->customer = Customer::factory()->create();
        $this->site = Site::factory()->create(['customer_id' => $this->customer->id, 'domain' => 'dana-shop.co.il']);
        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id, 'plan_id' => $plan->id, 'site_id' => $this->site->id,
            'status' => SubscriptionStatus::Active,
        ]);

        SiteAgentSubscriber::create([
            'phone' => '972501111111', 'customer_id' => $this->customer->id,
            'site_id' => $this->site->id, 'verified_at' => now(),
        ]);
    }

    public function test_the_portal_lists_each_site_with_where_it_stands(): void
    {
        $this->asCustomer()->get(route('portal.site-agent'))
            ->assertOk()
            ->assertSee('חיבור האתרים')
            ->assertSee('dana-shop.co.il')
            ->assertSee('לא מחובר')
            ->assertSee(route('portal.site-agent.connect', ['site' => $this->site]), false);
    }

    public function test_the_connect_page_shows_the_codes_and_the_guide(): void
    {
        $page = $this->asCustomer()->get(route('portal.site-agent.connect', ['site' => $this->site]))->assertOk();

        $this->site->refresh();
        $page->assertSee($this->site->mcp_secret)
            ->assertSee('Multi Digital Agent')
            ->assertSee(route('portal.site-agent.plugin'), false)
            // The menu the plugin really has — the old guide named one it does not.
            ->assertDontSee('הגדרות ← Multioto');
    }

    public function test_another_customers_site_is_not_found(): void
    {
        $stranger = Site::factory()->create(['customer_id' => Customer::factory()->create()->id, 'mcp_secret' => 'not-yours-123']);

        $this->asCustomer()->get(route('portal.site-agent.connect', ['site' => $stranger]))->assertNotFound();
        $this->asCustomer()->post(route('portal.site-agent.check', ['site' => $stranger]))->assertNotFound();
    }

    public function test_an_unpaid_service_does_not_hand_out_the_keys(): void
    {
        $this->subscription->update(['status' => SubscriptionStatus::Suspended]);

        $this->asCustomer()->get(route('portal.site-agent.connect', ['site' => $this->site]))
            ->assertOk()
            ->assertSee('המנוי אינו פעיל');

        // And nothing was generated for them to find later, either.
        $this->assertNull($this->site->refresh()->mcp_secret);

        $this->asCustomer()->get(route('portal.site-agent.plugin'))->assertForbidden();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $this->get(route('portal.site-agent.connect', ['site' => $this->site]))->assertRedirect();
        $this->get(route('portal.site-agent.plugin'))->assertRedirect();
    }

    public function test_the_plugin_downloads_for_a_paying_customer(): void
    {
        $this->asCustomer()->get(route('portal.site-agent.plugin'))
            ->assertOk()
            ->assertDownload('multioto-agent-'.config('agent.plugin.current_version').'.zip');
    }

    public function test_checking_again_asks_the_site_in_the_background(): void
    {
        Queue::fake();
        $this->site->forceFill(['mcp_enabled' => true, 'mcp_endpoint' => 'https://dana-shop.co.il/wp-json/md-agent/v1/mcp'])->save();

        $this->asCustomer()->post(route('portal.site-agent.check', ['site' => $this->site]))->assertRedirect();

        Queue::assertPushed(RefreshSiteCapabilitiesJob::class, fn ($job): bool => $job->siteId === $this->site->id);
    }

    public function test_a_site_not_yet_switched_on_is_not_called(): void
    {
        Queue::fake();

        $this->asCustomer()->post(route('portal.site-agent.check', ['site' => $this->site]))
            ->assertSessionHas('status');

        Queue::assertNothingPushed();
    }

    private function asCustomer(): self
    {
        $this->withSession(['portal.customer_id' => $this->customer->id]);

        return $this;
    }
}
