<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Models\Site;
use App\Services\Agent\McpClient;
use LogicException;

/** A hard network boundary: every MCP request is answered by the isolated world. */
class EvaluationMcpClient extends McpClient
{
    public function __construct(public readonly EvaluationWorld $world, private ?int $siteId = null) {}

    public function initialize(Site $site): array
    {
        $this->assertSite($site);

        return ['protocolVersion' => self::PROTOCOL_VERSION, 'serverInfo' => ['name' => 'isolated-evaluation-world', 'version' => '1.12.1'], 'capabilities' => ['tools' => (object) []]];
    }

    public function listTools(Site $site): array
    {
        $this->assertSite($site);

        return array_map(fn (string $name): array => ['name' => $name, 'description' => 'Isolated evaluation tool', 'inputSchema' => ['type' => 'object', 'properties' => (object) []], 'annotations' => ['readOnlyHint' => ! $this->world->isWrite($name)]], $this->world->supportedTools());
    }

    public function callTool(Site $site, string $name, array $arguments = [], int $timeout = 0): array
    {
        $this->assertSite($site);
        $data = $this->world->handle($name, $arguments);

        return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]], 'isError' => false];
    }

    protected function request(Site $site, string $method, array|object $params = [], int $timeout = 0): array
    {
        throw new LogicException('Network MCP requests are disabled in evaluation.');
    }

    private function assertSite(Site $site): void
    {
        if ($site->domain !== 'evaluation.example'
            || $site->mcp_endpoint !== 'https://evaluation.example/wp-json/multioto/v1/mcp') {
            throw new LogicException('Evaluation only accepts its isolated site endpoint.');
        }
        if ($this->siteId !== null && $site->id !== $this->siteId) {
            throw new LogicException('Evaluation cannot access another site.');
        }
    }
}
