<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteAgentExtendedActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentOptimoleValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('qualityValues')]
    public function test_optimole_quality_is_validated_before_an_owner_can_approve_it(int $quality, bool $valid): void
    {
        Http::preventStrayRequests();
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_capabilities' => ['tools' => [['name' => 'wp_optimole_get'], ['name' => 'wp_optimole_update']]],
        ]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->withArgs(fn (Site $current, string $tool): bool => $current->is($site) && $tool === 'wp_optimole_get')
            ->times($valid ? 1 : 0)->andReturn([
                'id' => 1, 'label' => 'Optimole', 'active' => true, 'connected' => true,
                'editable_fields' => ['quality'], 'values' => ['quality' => 80],
            ]);
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $result): string => json_encode($result));
        $this->app->instance(McpClient::class, $mcp);

        $offer = app(SiteAgentExtendedActions::class)->propose($site, 'propose_optimole_update', ['values' => ['quality' => $quality]], []);

        if ($valid) {
            $this->assertSame($quality, $offer['plan']['values']['quality']);
            $this->assertSame(80, $offer['plan']['expected']['quality']);
            $this->assertArrayHasKey('preview', $offer);
        } else {
            $this->assertStringContainsString('מחוץ לטווח', $offer['error']);
            $this->assertStringContainsString('50–100', $offer['error']);
            $this->assertArrayNotHasKey('plan', $offer);
            $this->assertArrayNotHasKey('preview', $offer);
        }
    }

    public static function qualityValues(): array
    {
        return [
            'reported quality 10' => [10, false],
            'below lower bound' => [49, false],
            'above upper bound' => [101, false],
            'lower bound' => [50, true],
            'upper bound' => [100, true],
        ];
    }
}
