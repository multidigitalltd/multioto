<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\ProductChangePlanner;
use App\Services\SiteAgent\SiteChangePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'openai',
            'billing.ai.model' => 'gpt-4o',
            'billing.ai.base_url' => 'https://api.openai.test/v1',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(fn (Site $site, string $tool): array => match ($tool) {
            'wp_site_settings_get' => ['values' => ['show_on_front' => 'page', 'page_on_front' => 11, 'page_for_posts' => 0]],
            'wp_content_list' => [['id' => 11, 'title' => 'דף הבית']],
            'wp_content_get' => ['id' => 11, 'type' => 'page', 'title' => 'דף הבית', 'content' => 'ברוכים הבאים לאתר', 'status' => 'publish'],
            default => throw new \RuntimeException('Unexpected site tool: '.$tool),
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $data): string => json_encode($data));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_real_planners_preserve_their_distinct_required_fields_in_non_strict_mode(): void
    {
        $question = 'איזה טקסט בדף הבית תרצו להחליף, ומה לכתוב במקומו?';
        Http::fake(['api.openai.test/*' => function ($request) use ($question) {
            $format = data_get($request->data(), 'response_format.json_schema');
            $this->assertFalse($format['strict']);
            $this->assertArrayNotHasKey('additionalProperties', $format['schema']);
            $page = isset($format['schema']['properties']['page_id']);
            if ($page) {
                $this->assertSame(['can_do', 'operation', 'page_id', 'find', 'text', 'summary', 'question', 'refusal'], $format['schema']['required']);
                $result = ['can_do' => false, 'operation' => 'none', 'page_id' => 0, 'find' => '', 'text' => '',
                    'summary' => '', 'question' => $question, 'refusal' => ''];
            } else {
                $this->assertSame(['can_do'], $format['schema']['required']);
                $this->assertArrayHasKey('operation', $format['schema']['properties']);
                $this->assertNotContains('operation', $format['schema']['required']);
                $result = ['can_do' => false];
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode($result)]]]]);
        }]);
        $site = new Site(['domain' => 'example.test']);

        $this->assertNull(app(ProductChangePlanner::class)->plan($site, 'להחליף טקסט בדף הבית'));
        $this->assertSame(['question' => $question], app(SiteChangePlanner::class)->plan($site, 'להחליף טקסט בדף הבית'));
        Http::assertSentCount(2);
    }

    public function test_non_strict_planner_results_cannot_bypass_local_plan_validation(): void
    {
        Http::fake(['api.openai.test/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode([
                'can_do' => true, 'operation' => 'delete_everything', 'page_id' => 11, 'text' => 'שלום',
            ])]]]])
            ->push(['choices' => [['message' => ['content' => json_encode([
                'can_do' => true, 'operation' => 'replace_text', 'page_id' => 999, 'find' => 'ברוכים הבאים', 'text' => 'שלום',
            ])]]]])
            ->push(['choices' => [['message' => ['content' => 'not valid JSON']]]]),
        ]);
        $planner = app(SiteChangePlanner::class);
        $site = new Site(['domain' => 'example.test']);

        $this->assertNull($planner->plan($site, 'להחליף טקסט בדף הבית'));
        $this->assertArrayHasKey('refusal', $planner->plan($site, 'להחליף טקסט בדף הבית'));
        $this->assertArrayHasKey('refusal', $planner->plan($site, 'להחליף טקסט בדף הבית'));
        Http::assertSentCount(3);
    }

    #[DataProvider('schemas')]
    public function test_strict_mode_is_retained_only_for_closed_required_objects(array $schema, bool $strict): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);

        $this->assertSame(['ok' => true], app(ClaudeClient::class)->structured('s', 'p', $schema));

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'response_format.json_schema.strict') === $strict
            && data_get($request->data(), 'response_format.json_schema.schema') === $schema);
    }

    public static function schemas(): array
    {
        $closed = ['type' => 'object', 'additionalProperties' => false,
            'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']];
        $optional = ['type' => 'object', 'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']], 'required' => ['name']];
        $withItems = fn (array $item): array => ['type' => 'object', 'additionalProperties' => false,
            'properties' => ['items' => ['type' => 'array', 'items' => $item]], 'required' => ['items']];

        return [
            'existing strict schemas stay strict' => [$closed, true],
            'optional property is not made required' => [$optional, false],
            'nested optional object is not changed' => [$withItems($optional), false],
            'nested strict objects stay strict' => [$withItems($closed), true],
            'union with open object stays open' => [$withItems(['anyOf' => [['type' => 'string'], ['type' => 'object']]]), false],
            'untyped dynamic value stays unconstrained' => [$withItems(['description' => 'The value from the live ACF schema.']), false],
        ];
    }
}
