<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use App\Services\SiteAgent\SiteActionApplier;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Native provider JSON crosses the real Laravel bridge; only MCP transport is replaced. */
class SiteAgentLearnDashNativeContractTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $native = null;

    private Site $site;

    private array $responses = [];

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        if (self::$native === null) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/wordpress-learndash.php'), 'export']);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            self::$native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->site = Site::factory()->create([
            'domain' => 'learndash-native.example', 'mcp_enabled' => true, 'mcp_secret' => 'site-secret',
            'mcp_endpoint' => 'https://learndash-native.example/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['server' => ['version' => '1.11.0'], 'tools' => array_map(fn (string $name): array => ['name' => $name], [
                'ld_capabilities', 'ld_courses_list', 'ld_course_get', 'ld_student_course_get', 'ld_groups_list', 'ld_group_get',
                'ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert',
            ])],
        ]);
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function (Site $site, string $tool, array $args = []): array {
            $this->assertSame($this->site->id, $site->id);
            $this->calls[] = [$tool, $args];
            $this->assertArrayHasKey($tool, $this->responses, 'Unexpected native tool: '.$tool);

            return $this->responses[$tool];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn (array $answer): string => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
    }

    public function test_native_catalogue_hierarchy_group_progress_and_membership_responses_fit_the_public_projection(): void
    {
        $cases = [
            ['get_ld_capabilities', 'ld_capabilities', 'capabilities', []],
            ['find_ld_courses', 'ld_courses_list', 'courses', []],
            ['find_ld_groups', 'ld_groups_list', 'groups', []],
            ['get_ld_course', 'ld_course_get', 'course', ['course_id' => 10]],
            ['get_ld_group', 'ld_group_get', 'group', ['group_id' => 20]],
            ['get_ld_student_course', 'ld_student_course_get', 'student', ['user_id' => 7, 'course_id' => 10]],
            ['get_ld_membership', 'ld_membership_get', 'membership', self::$native['membership']['selector']],
        ];
        foreach ($cases as [$modelTool, $nativeTool, $fixture, $arguments]) {
            $this->responses[$nativeTool] = self::$native[$fixture];
            $read = app(SiteAgentToolbox::class)->read($this->site, $modelTool, $arguments);
            $this->assertFalse($read['is_error'], $modelTool.': '.$read['content']);
            $public = json_decode($read['content'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($public);
            $this->assertSame([$nativeTool, $arguments], end($this->calls));
            $this->assertArrayNotHasKey('snapshot', $public);
            $this->assertArrayNotHasKey('primary', $public);
            $this->assertArrayNotHasKey('receipt', $public);
            if ($fixture === 'student') {
                $this->assertSame(self::$native[$fixture]['progress'], $public['progress']);
                $this->assertSame(self::$native[$fixture]['access'], $public['access']);
                $this->assertSame([], $read['ids'], 'A progress read never authorizes membership writes.');
            }
            if ($fixture === 'membership') {
                $this->assertSame(['ld-membership:course:10:7'], $read['ids']);
                $this->assertSame(self::$native[$fixture]['state'], $public['state']);
                $this->assertStringNotContainsString(self::$native[$fixture]['snapshot']['token'], $read['content']);
            }
        }
    }

    public function test_native_course_enrollment_seals_pass_live_read_preparation_apply_and_undo_contracts(): void
    {
        $this->roundTrip('', 'add');
    }

    public function test_native_group_removal_impacts_and_seals_pass_the_same_laravel_contracts(): void
    {
        $this->roundTrip('group_', 'remove');
    }

    private function roundTrip(string $prefix, string $action): void
    {
        $read = self::$native[$prefix.'membership'];
        $prepared = self::$native[$prefix.'prepared'];
        $applied = self::$native[$prefix.'applied'];
        $reverted = self::$native[$prefix.'reverted'];
        $selector = $read['selector'];
        $this->responses = ['ld_membership_get' => $read, 'ld_membership_prepare' => $prepared,
            'ld_membership_apply' => $applied, 'ld_membership_revert' => $reverted];

        $modelRead = app(SiteAgentToolbox::class)->read($this->site, 'get_ld_membership', $selector);
        $this->assertFalse($modelRead['is_error'], $modelRead['content']);
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_ld_membership', $selector + ['action' => $action], $modelRead['ids']);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame($selector, $offer['plan']['arguments']);
        $this->assertSame($read['snapshot'], $offer['plan']['expected']);
        $this->assertSame($prepared['prepared'], $offer['plan']['prepared']);
        $this->assertSame(['ld_membership_get', 'ld_membership_get', 'ld_membership_prepare'], array_column($this->calls, 0));
        $this->assertSame($selector + ['action' => $action, 'expected' => $read['snapshot']], end($this->calls)[1]);
        $this->assertStringContainsString($read['user']['display_name'], $offer['preview']);
        $this->assertStringContainsString($read['target']['title'], $offer['preview']);
        foreach ($prepared['impacts'] as $impact) {
            $this->assertStringContainsString($impact['title'], $offer['preview']);
        }
        $this->assertStringNotContainsString($prepared['prepared']['token'], $offer['preview']);

        $request = new SiteAgentRequest(['operation' => $offer['plan']['operation'], 'plan' => $offer['plan'], 'preview' => $offer['preview']]);
        $applier = app(SiteActionApplier::class);
        $result = $applier->apply($this->site, $request);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['ld_membership_apply', $selector + ['expected' => $prepared['expected'], 'prepared' => $prepared['prepared']]], end($this->calls));
        $this->assertSame($applied['before'], $result['restore']['before']);
        $this->assertSame($applied['after'], $result['restore']['after']);
        $undo = $applier->revert($this->site, $result['restore']);
        $this->assertTrue($undo['ok'], json_encode($undo));
        $this->assertSame(['ld_membership_revert', $selector + ['expected' => $applied['after'], 'restore' => $applied['before']]], end($this->calls));
    }
}
