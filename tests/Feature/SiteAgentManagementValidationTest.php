<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteAgentExtendedActions;
use App\Services\SiteAgent\SiteAgentExtendedCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentManagementValidationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.assistant.disabled_permissions' => []]);
        $this->site = Site::factory()->create(['mcp_capabilities' => ['tools' => array_map(fn ($tool) => ['name' => $tool], [
            'wp_site_settings_get', 'wp_site_settings_update', 'wp_user_profile_get', 'wp_user_profile_update',
        ])]]);
    }

    public static function invalidValues(): array
    {
        return [
            'zero posts' => ['propose_site_settings', [], ['posts_per_page' => 0]],
            'too many posts' => ['propose_site_settings', [], ['posts_per_page' => 101]],
            'negative weekday' => ['propose_site_settings', [], ['start_of_week' => -1]],
            'invalid weekday' => ['propose_site_settings', [], ['start_of_week' => 7]],
            'negative page id' => ['propose_site_settings', [], ['page_on_front' => -1]],
            'offset too low' => ['propose_site_settings', [], ['gmt_offset' => -14.5]],
            'offset too high' => ['propose_site_settings', [], ['gmt_offset' => 14.5]],
            'empty required display name' => ['propose_user_profile', ['id' => 7], ['display_name' => '']],
            'long display name' => ['propose_user_profile', ['id' => 7], ['display_name' => str_repeat('א', 251)]],
            'long first name' => ['propose_user_profile', ['id' => 7], ['first_name' => str_repeat('ב', 251)]],
            'native byte limit on Hebrew name' => ['propose_user_profile', ['id' => 7], ['display_name' => str_repeat('א', 126)]],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_cannot_be_offered_or_written_from_a_forged_plan(string $name, array $arguments, array $values): void
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldNotReceive('callTool');
        $this->app->instance(McpClient::class, $mcp);
        $actions = app(SiteAgentExtendedActions::class);
        $offer = $actions->propose($this->site, $name, $arguments + ['values' => $values], [7]);
        $this->assertArrayHasKey('error', $offer);
        $operation = SiteAgentExtendedCatalogue::actions()[$name]['operation'];
        $request = new SiteAgentRequest(['operation' => $operation, 'plan' => [
            'operation' => $operation, 'action' => $name, 'arguments' => $arguments, 'values' => $values, 'expected' => [],
        ]]);
        $this->assertFalse($actions->apply($this->site, $request)['ok']);
    }

    public static function validBoundaries(): array
    {
        return [
            ['propose_site_settings', [], ['posts_per_page' => 1], ['posts_per_page' => 10]],
            ['propose_site_settings', [], ['posts_per_page' => 100], ['posts_per_page' => 10]],
            ['propose_site_settings', [], ['start_of_week' => 0, 'gmt_offset' => -14], ['start_of_week' => 1, 'gmt_offset' => 0]],
            ['propose_site_settings', [], ['start_of_week' => 6, 'gmt_offset' => 14], ['start_of_week' => 1, 'gmt_offset' => 0]],
            ['propose_user_profile', ['id' => 7], ['display_name' => str_repeat('א', 125), 'first_name' => ''], ['display_name' => 'Old', 'first_name' => 'Old']],
            ['propose_user_profile', ['id' => 7], ['display_name' => str_repeat('A', 250), 'first_name' => ''], ['display_name' => 'Old', 'first_name' => 'Old']],
        ];
    }

    #[DataProvider('validBoundaries')]
    public function test_declared_boundaries_and_optional_empty_names_survive_proposal_apply_and_restore(string $name, array $arguments, array $values, array $current): void
    {
        $spec = SiteAgentExtendedCatalogue::actions()[$name];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->once()->withArgs(fn ($site, $tool, $args) => $site->is($this->site) && $tool === $spec['read'] && $args === $arguments)
            ->andReturn($arguments + ['label' => 'Settings', 'values' => $current]);
        $mcp->shouldReceive('callTool')->once()->withArgs(fn ($site, $tool, $args) => $tool === $spec['write'] && $args === $arguments + ['values' => $values, 'expected' => $current])
            ->andReturn(['changed' => true, 'before' => $current, 'after' => $values]);
        $mcp->shouldReceive('callTool')->once()->withArgs(fn ($site, $tool, $args) => $tool === $spec['write'] && $args === $arguments + ['values' => $current, 'expected' => $values])
            ->andReturn(['changed' => true, 'before' => $values, 'after' => $current]);
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($response) => json_encode($response, JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $actions = app(SiteAgentExtendedActions::class);
        $offer = $actions->propose($this->site, $name, $arguments + ['values' => $values], [7]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame($values, $offer['plan']['values']);
        $request = new SiteAgentRequest(['operation' => $spec['operation'], 'plan' => $offer['plan']]);
        $applied = $actions->apply($this->site, $request);
        $this->assertTrue($applied['ok'], json_encode($applied));
        $this->assertTrue($actions->revert($this->site, $applied['restore'])['ok']);
    }
}
