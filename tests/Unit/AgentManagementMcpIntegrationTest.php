<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AgentManagementMcpIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Support/wordpress-mcp-management-stubs.php';
        $GLOBALS['mm_options'] = ['blogname' => 'Original site'];
        $GLOBALS['mm_routes'] = [];
    }

    public function test_plugin_bootstrap_registers_all_providers_without_woocommerce(): void
    {
        $response = $this->rpc('tools/list');
        $tools = $response['result']['tools'];
        $names = array_column($tools, 'name');
        foreach ([\Multioto_Agent_Cct::class, \Multioto_Agent_Content_Management::class, \Multioto_Agent_Media_Management::class, \Multioto_Agent_Site_Administration::class] as $provider) {
            foreach ($provider::definitions() as $definition) {
                $this->assertContainsEquals($definition, $tools);
            }
        }
        $this->assertCount(count(array_unique($names)), $names, 'Duplicate tool names are ambiguous to clients.');
        $this->assertNotContains('wc_product_get', $names);
        $this->assertNotContains('wc_order_get', $names);
        $this->assertNotContains('wp_elementor_texts_get', $names);
    }

    public function test_optional_woocommerce_tools_remain_advertised_when_installed(): void
    {
        class_alias(get_class(new class {}), 'WooCommerce');
        $names = array_column($this->rpc('tools/list')['result']['tools'], 'name');
        $this->assertContains('wc_product_get', $names);
        $this->assertContains('wc_order_get', $names);
        $this->assertContains('jet_cct_types', $names);
    }

    public function test_each_provider_dispatches_and_returns_standard_mcp_text(): void
    {
        foreach (['jet_cct_types', 'wp_optimole_get', 'wp_site_settings_get', 'wp_theme_active_get'] as $tool) {
            $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => []]);
            $this->assertFalse($response['result']['isError'], $tool);
            $this->assertSame('text', $response['result']['content'][0]['type']);
            $this->assertIsArray(json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
        }
        $this->assertFalse($this->toolData('jet_cct_types')['available']);
        $this->assertFalse($this->toolData('wp_optimole_get')['active']);
        $this->assertSame('Original site', $this->toolData('wp_site_settings_get')['values']['blogname']);
        $this->assertSame(['stylesheet' => 'original'], $this->toolData('wp_theme_active_get')['values']);
    }

    public function test_provider_writes_preserve_snapshots_and_stale_errors_use_the_rpc_error_envelope(): void
    {
        $changed = $this->toolData('wp_site_settings_update', ['values' => ['blogname' => 'Updated'], 'expected' => ['blogname' => 'Original site']]);
        $this->assertSame(['blogname' => 'Original site'], $changed['before']);
        $this->assertSame(['blogname' => 'Updated'], $changed['after']);

        $stale = $this->rpc('tools/call', ['name' => 'wp_site_settings_update', 'arguments' => ['values' => ['blogname' => 'Overwrite'], 'expected' => ['blogname' => 'Original site']]]);
        $this->assertArrayHasKey('error', $stale);
        $this->assertArrayNotHasKey('result', $stale);
        $this->assertSame('Updated', $GLOBALS['mm_options']['blogname']);
    }

    public function test_missing_vendor_write_and_unknown_tools_are_rejected_without_internal_error(): void
    {
        foreach (['jet_cct_create', 'wp_optimole_update', 'wp_unknown_tool'] as $tool) {
            $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => []]);
            $this->assertSame(-32602, $response['error']['code']);
            $this->assertArrayNotHasKey('result', $response);
        }
    }

    public function test_new_tools_share_the_existing_authenticated_rest_route(): void
    {
        $server = new \Multioto_Agent_Mcp_Server;
        $server->registerRoutes();
        $route = $GLOBALS['mm_routes']['md-agent/v1/mcp'];
        $this->assertSame([$server, 'authorize'], $route['permission_callback']);
        $request = new \WP_REST_Request(['id' => 1, 'method' => 'tools/list']);
        $this->assertFalse($server->authorize($request));
        $GLOBALS['mm_options']['multioto_agent_settings'] = ['mcp_secret' => 'test-secret'];
        $this->assertFalse($server->authorize($request));
        $this->assertTrue($server->authorize(new \WP_REST_Request([], 'Bearer test-secret')));
    }

    private function rpc(string $method, array $params = []): array
    {
        return (new \Multioto_Agent_Mcp_Server)->handle(new \WP_REST_Request(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]))->get_data();
    }

    private function toolData(string $tool, array $args = []): array
    {
        $response = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $args]);
        $this->assertArrayHasKey('result', $response);

        return json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
