<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Agent\McpError;
use App\Services\Agent\SiteConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    use RefreshDatabase;

    private function connectedSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example-site.co.il/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret-key',
        ]);
    }

    /** JSON-RPC response body for the request with the matching method. */
    private function fakeRpc(array $handlers): void
    {
        Http::fake([
            'example-site.co.il/*' => function (Request $request) use ($handlers) {
                $body = json_decode($request->body(), true);
                $method = $body['method'] ?? '';

                // Notifications get an empty 202-style ack.
                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                $handler = $handlers[$method] ?? null;

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    ...($handler ? $handler($body) : ['error' => ['code' => -32601, 'message' => 'method not found']]),
                ]);
            },
        ]);
    }

    public function test_initialize_handshakes_and_sends_the_initialized_notification(): void
    {
        $site = $this->connectedSite();
        $this->fakeRpc([
            'initialize' => fn (): array => ['result' => [
                'protocolVersion' => '2025-06-18',
                'serverInfo' => ['name' => 'md-agent', 'version' => '1.0.0'],
                'capabilities' => ['tools' => []],
            ]],
        ]);

        $result = app(McpClient::class)->initialize($site);

        $this->assertSame('md-agent', $result['serverInfo']['name']);

        // The per-site secret authenticates us, and the notification followed.
        Http::assertSent(fn (Request $r): bool => $r->header('Authorization')[0] === 'Bearer site-secret-key'
            && json_decode($r->body(), true)['method'] === 'initialize');
        Http::assertSent(fn (Request $r): bool => json_decode($r->body(), true)['method'] === 'notifications/initialized');
    }

    public function test_list_tools_follows_pagination_cursors(): void
    {
        $site = $this->connectedSite();
        $this->fakeRpc([
            'tools/list' => function (array $body): array {
                $cursor = $body['params']['cursor'] ?? null;

                return $cursor === null
                    ? ['result' => ['tools' => [['name' => 'wp_cache_flush']], 'nextCursor' => 'p2']]
                    : ['result' => ['tools' => [['name' => 'wp_plugin_list']]]];
            },
        ]);

        $tools = app(McpClient::class)->listTools($site);

        $this->assertSame(['wp_cache_flush', 'wp_plugin_list'], array_column($tools, 'name'));
    }

    public function test_call_tool_returns_the_result_and_a_tool_error_throws(): void
    {
        $site = $this->connectedSite();
        $this->fakeRpc([
            'tools/call' => function (array $body): array {
                return ($body['params']['name'] ?? '') === 'wp_cache_flush'
                    ? ['result' => ['content' => [['type' => 'text', 'text' => 'Cache flushed.']], 'isError' => false]]
                    : ['result' => ['content' => [['type' => 'text', 'text' => 'plugin not found']], 'isError' => true]];
            },
        ]);

        $client = app(McpClient::class);

        $ok = $client->callTool($site, 'wp_cache_flush');
        $this->assertSame('Cache flushed.', $client->textContent($ok));

        $this->expectException(McpError::class);
        $client->callTool($site, 'wp_plugin_update', ['plugin' => 'missing']);
    }

    public function test_a_json_rpc_error_becomes_an_mcp_error_with_its_code(): void
    {
        $site = $this->connectedSite();
        $this->fakeRpc([]); // every method → -32601

        try {
            app(McpClient::class)->listTools($site);
            $this->fail('expected McpError');
        } catch (McpError $e) {
            $this->assertSame(-32601, $e->rpcCode);
        }
    }

    public function test_an_sse_response_body_is_decoded(): void
    {
        $site = $this->connectedSite();
        Http::fake([
            'example-site.co.il/*' => function (Request $request) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                $rpc = json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['tools' => [['name' => 'wp_health']]]]);

                return Http::response(
                    "event: message\ndata: {$rpc}\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream'],
                );
            },
        ]);

        $tools = app(McpClient::class)->listTools($site);

        $this->assertSame('wp_health', $tools[0]['name']);
    }

    public function test_missing_endpoint_and_http_failure_both_throw(): void
    {
        $client = app(McpClient::class);

        $bare = Site::factory()->create(['mcp_enabled' => true]);
        try {
            $client->listTools($bare);
            $this->fail('expected McpError for missing endpoint');
        } catch (McpError) {
        }

        $site = $this->connectedSite();
        Http::fake(['example-site.co.il/*' => Http::response('down', 503)]);
        $this->expectException(McpError::class);
        $client->listTools($site);
    }

    public function test_the_connector_caches_capabilities_and_reports_status(): void
    {
        $site = $this->connectedSite();
        $this->fakeRpc([
            'initialize' => fn (): array => ['result' => [
                'protocolVersion' => '2025-06-18',
                'serverInfo' => ['name' => 'md-agent', 'version' => '1.0.0'],
            ]],
            'tools/list' => fn (): array => ['result' => ['tools' => [
                ['name' => 'wp_cache_flush', 'description' => 'Flush all caches'],
                ['name' => 'wp_plugin_list', 'description' => 'List installed plugins'],
            ]]],
        ]);

        $result = app(SiteConnector::class)->testConnection($site);

        $this->assertTrue($result->ok);
        $fresh = $site->fresh();
        $this->assertNotNull($fresh->mcp_last_seen_at);
        $this->assertSame('md-agent', $fresh->mcp_capabilities['server']['name']);
        $this->assertSame(['wp_cache_flush', 'wp_plugin_list'], array_column($fresh->mcp_capabilities['tools'], 'name'));
    }

    public function test_the_connector_reports_unconfigured_and_failure_without_throwing(): void
    {
        $connector = app(SiteConnector::class);

        $off = Site::factory()->create(['mcp_enabled' => false]);
        $this->assertSame('unconfigured', $connector->testConnection($off)->state());

        $site = $this->connectedSite();
        Http::fake(['example-site.co.il/*' => Http::response('boom', 500)]);
        $result = $connector->testConnection($site);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->configured);
    }
}
