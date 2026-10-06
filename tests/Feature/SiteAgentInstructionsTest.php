<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteAgent;
use App\Filament\Resources\SiteAgentMessageResource;
use App\Filament\Resources\SiteAgentMessageResource\Pages\ListSiteAgentMessages;
use App\Jobs\PruneSiteAgentRequestsJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The team reads what owners asked the bot, and tells it how to behave.
 */
class SiteAgentInstructionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_instructions_and_retention_are_saved_from_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.instructions', "פנה בלשון רבים.\nסכום כולל קודם.")
            ->set('data.siteagent.transcript_days', 30)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame("פנה בלשון רבים.\nסכום כולל קודם.", Setting::map()['siteagent.instructions']);
        $this->assertSame("פנה בלשון רבים.\nסכום כולל קודם.", config('siteagent.assistant.instructions'));
        $this->assertSame(30, (int) config('siteagent.assistant.transcript_days'));
    }

    public function test_retention_is_bounded(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageSiteAgent::class)
            ->set('data.siteagent.transcript_days', 400)
            ->call('save')
            ->assertHasErrors(['data.siteagent.transcript_days']);
    }

    public function test_a_longer_retention_keeps_the_conversation_to_learn_from(): void
    {
        config(['siteagent.assistant.transcript_days' => 30]);
        $subscriber = $this->subscriber();

        $this->travel(-20)->days();
        SiteAgentMessage::create(['site_agent_subscriber_id' => $subscriber->id, 'role' => 'user', 'body' => 'לפני עשרים יום']);
        $this->travelBack();

        (new PruneSiteAgentRequestsJob)->handle();

        $this->assertSame(1, SiteAgentMessage::count());
    }

    public function test_the_conversations_screen_shows_both_sides_to_admins_only(): void
    {
        $subscriber = $this->subscriber();
        SiteAgentMessage::create(['site_agent_subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id, 'role' => 'user', 'body' => 'כמה הזמנות היו היום?']);
        SiteAgentMessage::create(['site_agent_subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id, 'role' => 'assistant', 'body' => 'היו 3 הזמנות.']);

        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(SiteAgentMessageResource::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Livewire::test(ListSiteAgentMessages::class)
            ->assertSeeText('כמה הזמנות היו היום?')
            ->assertSeeText('היו 3 הזמנות.')
            ->assertSeeText('בעל האתר');
    }

    public function test_a_number_on_two_sites_is_two_conversations_told_apart_by_site(): void
    {
        $first = $this->subscriber();
        $site = Site::factory()->create(['customer_id' => $first->customer_id, 'domain' => 'second.example']);
        $second = SiteAgentSubscriber::create([
            'phone' => $first->phone, 'customer_id' => $first->customer_id, 'site_id' => $site->id, 'verified_at' => now(),
        ]);
        foreach ([$first, $second] as $subscriber) {
            SiteAgentMessage::create(['site_agent_subscriber_id' => $subscriber->id, 'role' => 'user', 'body' => 'היי']);
        }

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $options = Livewire::test(ListSiteAgentMessages::class)->instance()
            ->getTable()->getFilter('site_agent_subscriber_id')->getOptions();

        $this->assertStringContainsString('shop.example', $options[$first->id]);
        $this->assertStringContainsString('second.example', $options[$second->id]);
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'shop.example']);

        return SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id, 'site_id' => $site->id, 'verified_at' => now(),
        ]);
    }
}
