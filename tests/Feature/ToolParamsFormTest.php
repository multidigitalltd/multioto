<?php

namespace Tests\Feature;

use App\Filament\Support\SiteActions;
use App\Models\Site;
use App\Services\Agent\SiteConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "פעולת AI" dialog builds a labelled field per tool parameter instead of
 * asking for raw JSON. That relies on the tool's parameter spec being cached on
 * connect, and on the form collecting typed arguments back out.
 */
class ToolParamsFormTest extends TestCase
{
    use RefreshDatabase;

    private function connectedSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.test/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret',
        ]);
    }

    public function test_connect_caches_each_tools_parameter_spec(): void
    {
        $site = $this->connectedSite();

        Http::fake([
            'site.test/*' => function (Request $request) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                $result = match ($body['method']) {
                    'initialize' => ['serverInfo' => ['name' => 'md-agent'], 'protocolVersion' => '2025-06-18'],
                    'tools/list' => ['tools' => [[
                        'name' => 'wp_plugin_update',
                        'description' => 'Update a plugin',
                        'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                        'inputSchema' => [
                            'type' => 'object',
                            'required' => ['plugin'],
                            'properties' => [
                                'plugin' => ['type' => 'string', 'description' => 'שם התוסף'],
                                'version' => ['type' => 'string'],
                                'force' => ['type' => 'boolean'],
                            ],
                        ],
                    ]]],
                    default => [],
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result]);
            },
        ]);

        app(SiteConnector::class)->sync($site);

        $spec = SiteActions::toolParamSpec($site->fresh(), 'wp_plugin_update');

        $this->assertCount(3, $spec);
        $plugin = collect($spec)->firstWhere('name', 'plugin');
        $this->assertSame('string', $plugin['type']);
        $this->assertTrue($plugin['required']);
        $this->assertSame('שם התוסף', $plugin['description']);
        $this->assertFalse(collect($spec)->firstWhere('name', 'version')['required']);
        $this->assertSame('boolean', collect($spec)->firstWhere('name', 'force')['type']);
    }

    public function test_arguments_are_collected_and_typed_from_the_form_fields(): void
    {
        $site = Site::factory()->create([
            'mcp_capabilities' => ['tools' => [[
                'name' => 'wp_plugin_update',
                'params' => [
                    ['name' => 'plugin', 'type' => 'string', 'required' => true],
                    ['name' => 'version', 'type' => 'string', 'required' => false],
                    ['name' => 'retries', 'type' => 'integer', 'required' => false],
                    ['name' => 'force', 'type' => 'boolean', 'required' => false],
                ],
            ]]],
        ]);

        $args = SiteActions::collectToolArguments($site, 'wp_plugin_update', [
            'tool' => 'wp_plugin_update',
            'plugin' => 'elementor',
            'version' => '',      // empty → dropped
            'retries' => '3',     // cast to int
            'force' => true,      // stays bool
        ]);

        $this->assertSame(['plugin' => 'elementor', 'retries' => 3, 'force' => true], $args);
    }

    public function test_a_tool_without_a_known_spec_falls_back_to_key_value(): void
    {
        $site = Site::factory()->create([
            'mcp_capabilities' => ['tools' => [['name' => 'wp_cache_flush']]], // no params spec
        ]);

        $args = SiteActions::collectToolArguments($site, 'wp_cache_flush', [
            'tool' => 'wp_cache_flush',
            'kv_params' => ['scope' => 'all', '' => 'ignored-empty-key'],
        ]);

        $this->assertSame(['scope' => 'all'], $args);
    }
}
