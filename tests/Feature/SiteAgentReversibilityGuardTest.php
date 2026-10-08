<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionApplier;
use App\Services\SiteAgent\SiteActionProposer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentReversibilityGuardTest extends TestCase
{
    #[DataProvider('forbiddenChanges')]
    public function test_the_proposal_guard_refuses_without_contacting_the_site(string $tool, array $input, string $operation, array $plan): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldNotReceive('callTool');
        $this->app->instance(McpClient::class, $mcp);

        $result = app(SiteActionProposer::class)->propose(new Site, $tool, $input, [1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('plan', $result);
    }

    #[DataProvider('forbiddenChanges')]
    public function test_old_saved_plans_cannot_execute_an_irreversible_change(string $tool, array $input, string $operation, array $plan): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldNotReceive('callTool');
        $this->app->instance(McpClient::class, $mcp);

        $request = new SiteAgentRequest(['operation' => $operation, 'plan' => $plan]);
        $result = app(SiteActionApplier::class)->apply(new Site, $request);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['reason']);
        $this->assertNull($result['restore']);
    }

    public static function forbiddenChanges(): array
    {
        return [
            'permanent media deletion' => ['propose_media_delete', ['attachment_id' => 1], SiteAgentRequest::OP_MEDIA_DELETE, ['attachment_id' => 1]],
            'menu removal without restoration' => ['propose_menu_item_remove', ['item_id' => 1], SiteAgentRequest::OP_MENU_REMOVE, ['item_id' => 1]],
            'plugin update without backup' => ['propose_plugin_update', ['plugins' => ['all']], SiteAgentRequest::OP_PLUGIN_UPDATE, ['plugins' => []]],
            'theme update without backup' => ['propose_theme_update', ['stylesheet' => 'theme'], SiteAgentRequest::OP_THEME_UPDATE, ['stylesheet' => 'theme']],
            'customer email' => ['propose_order_note', ['order_id' => 1, 'to_customer' => true, 'note' => 'test'], SiteAgentRequest::OP_ORDER_NOTE, ['order_id' => 1, 'to_customer' => true, 'note' => 'test']],
            'final subscription cancellation' => ['propose_subscription_status', ['subscription_id' => 1, 'status' => 'cancelled'], SiteAgentRequest::OP_SUBSCRIPTION_STATUS, ['subscription_id' => 1, 'from' => 'active', 'to' => 'cancelled']],
        ];
    }

    public function test_model_tools_do_not_offer_unconditionally_blocked_changes(): void
    {
        $site = new Site(['mcp_capabilities' => ['server' => ['version' => '1.8.5']]]);
        $definitions = app(SiteActionProposer::class)->definitions($site);
        $names = array_column($definitions, 'name');

        foreach (['propose_media_delete', 'propose_menu_item_remove', 'propose_plugin_update', 'propose_theme_update'] as $tool) {
            $this->assertNotContains($tool, $names);
        }

        foreach (['propose_menu_item_add', 'propose_menu_item_update', 'propose_user_create', 'propose_plugin_toggle'] as $tool) {
            $this->assertContains($tool, $names);
        }

        $notes = collect($definitions)->firstWhere('name', 'propose_order_note');
        $subscriptions = collect($definitions)->firstWhere('name', 'propose_subscription_status');
        $this->assertSame([false], data_get($notes, 'input_schema.properties.to_customer.enum'));
        $this->assertNotContains('cancelled', data_get($subscriptions, 'input_schema.properties.status.enum'));
    }
}
