<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteAgent;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\SiteAgentConversation;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\FakesSiteAgentProposalFidelity;
use Tests\TestCase;

/**
 * The team decides what the bot may change, and deleting a product is one of
 * the things it can do — to the trash, with an undo.
 */
class SiteAgentPermissionsTest extends TestCase
{
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    private array $calls = [];

    private array $site = [];

    private array $seenByModel = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();

        config(['siteagent.enabled' => true, 'siteagent.assistant.enabled' => true, 'siteagent.undo_minutes' => 1440]);
        Cache::flush();
        $this->fakeSite();
        $this->site['wc_product_search'] = ['total' => 1, 'returned' => 1, 'products' => [['id' => 70, 'name' => 'כד קרמיקה', 'regular_price' => '120']]];
        $this->site['wc_product_get'] = ['id' => 70, 'name' => 'כד קרמיקה', 'regular_price' => '120', 'status' => 'publish'];
    }

    public function test_a_product_is_deleted_to_the_trash_and_undone(): void
    {
        $subscriber = $this->subscriber();
        $this->model(function (Closure $tool): string {
            $tool('find_products', ['search' => 'כד']);
            $tool('propose_product_trash', ['product_id' => 70]);

            return '';
        });

        $preview = $this->talk($subscriber, 'תמחק את הכד קרמיקה');
        $this->assertStringContainsString('למחוק את המוצר: כד קרמיקה', $preview);
        $this->assertStringContainsString('לא נמחק סופית', $preview);

        $this->site['wc_product_trash'] = ['trashed_id' => 70, 'previous_status' => 'publish'];
        $this->assertStringContainsString('בוצע', $this->talk($subscriber, 'כן'));
        $this->assertContains(['wc_product_trash', ['product_id' => 70]], $this->calls);

        $this->site['wc_product_restore'] = ['restored_id' => 70, 'status' => 'publish'];
        $this->talk($subscriber, 'בטל');
        $this->assertContains(['wc_product_restore', ['product_id' => 70]], $this->calls);
        $this->assertSame(SiteAgentRequest::REVERTED, SiteAgentRequest::sole()->state);
    }

    public function test_a_permission_switched_off_is_not_offered_to_the_model_and_is_explained(): void
    {
        config(['siteagent.assistant.disabled_permissions' => 'products_delete,orders']);
        $subscriber = $this->subscriber();
        $this->model(fn (): string => 'כבוי');

        $this->talk($subscriber, 'תמחק את הכד');

        [$system, , $tools] = $this->seenByModel;
        $names = array_column($tools, 'name');
        $this->assertNotContains('propose_product_trash', $names);
        $this->assertNotContains('propose_order_status', $names);
        $this->assertContains('propose_product_update', $names);
        $this->assertStringContainsString('הצוות כיבה בחשבון הזה: מחיקת מוצרים', $system);
    }

    public function test_a_proposal_for_a_switched_off_permission_is_refused(): void
    {
        config(['siteagent.assistant.disabled_permissions' => 'products_delete']);
        $subscriber = $this->subscriber();
        $this->model(function (Closure $tool): string {
            $tool('find_products', ['search' => 'כד']);
            $result = $tool('propose_product_trash', ['product_id' => 70]);
            $this->assertTrue($result['is_error']);

            return 'כבוי';
        });

        $this->talk($subscriber, 'תמחק את הכד');

        $this->assertSame(0, SiteAgentRequest::count());
    }

    public function test_an_offer_waiting_when_the_permission_is_switched_off_is_refused_at_yes(): void
    {
        $subscriber = $this->subscriber();
        $this->model(function (Closure $tool): string {
            $tool('find_products', ['search' => 'כד']);
            $tool('propose_product_trash', ['product_id' => 70]);

            return '';
        });
        $this->talk($subscriber, 'תמחק את הכד');

        config(['siteagent.assistant.disabled_permissions' => 'products_delete']);

        $this->assertStringContainsString('כבויה בחשבון', $this->talk($subscriber, 'כן'));
        $this->assertNotContains('wc_product_trash', array_column($this->calls, 0));
        $this->assertSame(SiteAgentRequest::FAILED, SiteAgentRequest::sole()->state);
    }

    public function test_permissions_are_set_from_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $page = Livewire::test(ManageSiteAgent::class);
        $allowed = $page->get('data.siteagent.allowed');
        $this->assertContains('products_delete', $allowed);

        $page->set('data.siteagent.allowed', array_values(array_diff($allowed, ['products_delete', 'users'])))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('products_delete,users', Setting::map()['siteagent.disabled_permissions']);

        // Everything back on: nothing stored.
        Livewire::test(ManageSiteAgent::class)->set('data.siteagent.allowed', $allowed)->call('save');
        $this->assertArrayNotHasKey('siteagent.disabled_permissions', array_filter(Setting::map()));
    }

    private function subscriber(): SiteAgentSubscriber
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id, 'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/multioto/v1/mcp', 'mcp_secret' => 'secret',
            'mcp_capabilities' => ['server' => ['version' => '1.8.5']],
        ]);

        return SiteAgentSubscriber::create([
            'phone' => '972501234567', 'customer_id' => $customer->id, 'site_id' => $site->id, 'verified_at' => now(),
        ]);
    }

    private function talk(SiteAgentSubscriber $subscriber, string $text): string
    {
        return app(SiteAgentConversation::class)->handle($subscriber, $text, 'wamid.'.md5($text.microtime()));
    }

    private function model(Closure $script): void
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('supportsAgent')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn(null);
        $ai->shouldReceive('converse')->andReturnUsing(function (string $system, string $prompt, array $tools, callable $handler) use ($script): ?string {
            $this->seenByModel = [$system, $prompt, $tools];

            return $script(function (string $name, array $input) use ($handler): array {
                $out = $handler($name, $input);
                $out['is_error'] ??= false;

                return $out;
            });
        });

        $this->app->instance(ClaudeClient::class, $ai);
    }

    private function fakeSite(): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $arguments = []) {
            $this->calls[] = [$tool, $arguments];

            return $this->site[$tool] ?? [];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($result): string => json_encode($result, JSON_UNESCAPED_UNICODE));

        $this->app->instance(McpClient::class, $mcp);
    }
}
