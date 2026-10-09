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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SiteAgentUserReadContractTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $native = null;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['siteagent.enabled' => true, 'siteagent.assistant.disabled_permissions' => []]);
        if (self::$native === null) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/wordpress-user-read.php')]);
            $process->setTimeout(30);
            $process->mustRun();
            self::$native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->site = Site::factory()->create(['mcp_capabilities' => ['tools' => array_map(fn ($tool) => ['name' => $tool], [
            'wp_user_get', 'wp_user_list', 'wp_user_role_set',
        ])]]);
    }

    public function test_native_exact_read_preserves_protected_roles_without_exposing_secrets_or_other_site_accounts(): void
    {
        $this->assertFalse(self::$native['users'][1]['editable']);
        $this->assertTrue(self::$native['users'][7]['editable']);
        $this->assertFalse(self::$native['users'][8]['editable']);
        $this->assertSame(['approval_status'], self::$native['users'][1]['status_meta_keys']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode(self::$native));
        $this->assertSame([1, 7, 8, 99, 100001], self::$native['lookups']);
        foreach (self::$native['errors'] as $key => $error) {
            $this->assertSame(-32602, $error['code'], $key);
        }
    }

    public function test_native_user_id_is_read_directly_and_protected_role_cannot_be_proposed_or_applied(): void
    {
        $mcp = $this->mcp(self::$native['users'][1], 3);
        $mcp->shouldNotReceive('callTool')->withArgs(fn ($site, $tool) => $tool !== 'wp_user_get');
        $read = app(SiteAgentToolbox::class)->read($this->site, 'get_user', ['user_id' => 1]);
        $this->assertFalse($read['is_error']);
        $this->assertSame([1], $read['ids']);
        $this->assertFalse(json_decode($read['content'], true)['editable']);
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_user_role', [
            'user_id' => 1, 'email' => 'riki@example.test', 'role' => 'subscriber',
        ], $read['ids']);
        $this->assertArrayHasKey('error', $offer);
        $this->assertStringContainsString('מנהל האתר', $offer['error']);
        $request = new SiteAgentRequest(['operation' => SiteAgentRequest::OP_USER_ROLE, 'plan' => [
            'user_id' => 1, 'email' => 'riki@example.test', 'from' => 'administrator', 'to' => 'subscriber',
        ]]);
        $this->assertFalse(app(SiteActionApplier::class)->apply($this->site, $request)['ok']);
    }

    public function test_exact_read_only_authorizes_the_requested_id_and_validates_numeric_input(): void
    {
        $this->mcp(self::$native['users'][7], 1);
        $wrong = app(SiteAgentToolbox::class)->read($this->site, 'get_user', ['user_id' => 1]);
        $this->assertTrue($wrong['is_error']);
        $this->assertSame([], $wrong['ids']);
        foreach ([[], ['user_id' => 0], ['user_id' => '1'], ['user_id' => 1.1]] as $input) {
            $invalid = app(SiteAgentToolbox::class)->read($this->site, 'get_user', $input);
            $this->assertTrue($invalid['is_error']);
            $this->assertSame([], $invalid['ids']);
        }
    }

    public function test_new_read_is_only_advertised_with_native_capability_and_existing_editor_role_change_remains_available(): void
    {
        $this->assertContains('get_user', array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name'));
        $this->mcp(self::$native['users'][7], 1, 7);
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_user_role', [
            'user_id' => 7, 'email' => 'riki@example.test', 'role' => 'author',
        ], [7]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame('editor', $offer['plan']['from']);
        $this->assertSame('author', $offer['plan']['to']);
        $this->site->mcp_capabilities = ['tools' => [['name' => 'wp_user_list']]];
        $definitions = array_column(app(SiteAgentToolbox::class)->definitions($this->site), 'name');
        $this->assertNotContains('get_user', $definitions);
        $this->assertContains('find_users', $definitions);
    }

    public static function readVersions(): array
    {
        return ['exact user capability' => [true], 'older plugin email search' => [false]];
    }

    #[DataProvider('readVersions')]
    public function test_editor_role_changes_apply_and_restore_with_exact_and_legacy_reads(bool $exact): void
    {
        if (! $exact) {
            $this->site->mcp_capabilities = ['tools' => [['name' => 'wp_user_list'], ['name' => 'wp_user_role_set']]];
        }
        $user = self::$native['users'][7];
        $reads = 0;
        $writes = [];
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturnUsing(function ($site, $tool, $args) use ($exact, &$user, &$reads, &$writes) {
            $this->assertTrue($site->is($this->site));
            if ($tool === 'wp_user_role_set') {
                $this->assertSame(7, $args['user_id']);
                $before = $user['roles'][0];
                $user['roles'] = [$args['role']];
                $writes[] = $args['role'];

                return ['changed' => true, 'previous' => ['role' => $before]];
            }
            $reads++;
            $this->assertSame($exact ? 'wp_user_get' : 'wp_user_list', $tool);
            $this->assertSame($exact ? ['user_id' => 7] : ['search' => $user['email'], 'limit' => 20], $args);

            return $exact ? $user : ['users' => [$user]];
        });
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);
        $offer = app(SiteActionProposer::class)->propose($this->site, 'propose_user_role', [
            'user_id' => 7, 'email' => $user['email'], 'role' => 'author',
        ], [7]);
        $this->assertArrayHasKey('plan', $offer, json_encode($offer));
        $this->assertSame([], $writes);
        $request = new SiteAgentRequest(['operation' => SiteAgentRequest::OP_USER_ROLE, 'plan' => $offer['plan']]);
        $applied = app(SiteActionApplier::class)->apply($this->site, $request);
        $this->assertTrue($applied['ok']);
        $this->assertSame(['author'], $user['roles']);
        $this->assertTrue(app(SiteActionApplier::class)->revert($this->site, $applied['restore'])['ok']);
        $this->assertSame(['editor'], $user['roles']);
        $this->assertSame(['author', 'editor'], $writes);
        $this->assertSame(3, $reads);
    }

    private function mcp(array $response, int $times, int $id = 1): mixed
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->times($times)->withArgs(fn ($site, $tool, $args) => $site->is($this->site) && $tool === 'wp_user_get' && $args === ['user_id' => $id])->andReturn($response);
        $mcp->shouldReceive('textContent')->andReturnUsing(fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        $this->app->instance(McpClient::class, $mcp);

        return $mcp;
    }
}
