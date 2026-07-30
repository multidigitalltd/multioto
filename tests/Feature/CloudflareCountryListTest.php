<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\User;
use App\Services\Cloudflare\CloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One WAF custom rule per zone, holding the whole country list.
 *
 * A rule per country runs into Cloudflare's per-zone rule budget and has to be
 * maintained one country at a time. The facts worth protecting here: the list on
 * screen is the list in force (applying replaces, never appends), nothing but a
 * two-letter code can reach the expression, and a partial run is never reported
 * as a success.
 */
class CloudflareCountryListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Fake the Rulesets API for $zones, whose custom-rules entrypoint already
     * holds $rules (empty = the ruleset exists but is empty; null = no ruleset).
     */
    private function fakeZones(array $zones, ?array $rules = []): void
    {
        Http::fake([
            '*/rulesets/phases/*' => $rules === null
                ? Http::response(['success' => false], 404)
                : Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => $rules]]),
            '*/rulesets/*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/rulesets' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => collect($zones)->map(fn (string $id): array => ['id' => $id, 'name' => $id.'.com'])->all(),
                'result_info' => ['total_pages' => 1],
            ]),
        ]);
    }

    private function client(): CloudflareClient
    {
        return app(CloudflareClient::class);
    }

    public function test_a_whole_list_of_countries_becomes_one_rule_per_zone(): void
    {
        $this->fakeZones(['z1', 'z2']);

        $result = $this->client()->applyCountryListEverywhere(
            'cf-token', ['mx', 'HK', 'ir', 'CN'], 'block', 'replace',
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('2 אתרים', $result['message']);

        // One rule written per zone — not one per country, which is the point.
        $this->assertSame(2, Http::recorded(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rules'))->count());

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rulesets/rs1/rules')
            && data_get($request->data(), 'expression') === '(ip.src.country in {"CN" "HK" "IR" "MX"})'
            && data_get($request->data(), 'action') === 'block');
    }

    public function test_applying_again_replaces_the_list_instead_of_adding_a_second_rule(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
            'expression' => '(ip.src.country in {"MX"})',
            'action' => 'managed_challenge',
        ]]);

        $result = $this->client()->applyCountryListEverywhere('cf-token', ['MX', 'HK'], 'managed_challenge', 'replace');

        $this->assertTrue($result['ok']);

        // The rule is found by its description and patched. A second POST would
        // leave two overlapping country rules on the zone.
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rulesets/rs1/rules/existing')
            && data_get($request->data(), 'expression') === '(ip.src.country in {"HK" "MX"})');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rules'));
    }

    public function test_a_zone_with_no_custom_rules_yet_gets_the_ruleset_created(): void
    {
        $this->fakeZones(['z1'], null);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block')['ok']);

        // A 404 on the entrypoint is a starting state, not a failure.
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/zones/z1/rulesets')
            && data_get($request->data(), 'phase') === 'http_request_firewall_custom'
            && data_get($request->data(), 'rules.0.expression') === '(ip.src.country in {"MX"})');
    }

    public function test_removing_wipes_every_country_rule_of_ours(): void
    {
        Http::fake([
            '*/access_rules/rules*' => Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            '*/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => [
                ['id' => 'blocked', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
                    'expression' => '(ip.src.country in {"MX"})', 'action' => 'block'],
                ['id' => 'challenged', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (managed_challenge)',
                    'expression' => '(ip.src.country in {"HK"})', 'action' => 'managed_challenge'],
                ['id' => 'theirs', 'description' => 'Block bad bots',
                    'expression' => '(http.user_agent contains "curl")', 'action' => 'block'],
            ]]]),
            '*/rulesets*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', [], 'remove')['ok']);

        // A clean slate takes every action's list, and nothing that is not ours.
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/rs1/rules/blocked'));
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/rs1/rules/challenged'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/rs1/rules/theirs'));
    }

    public function test_a_rule_we_do_not_own_is_never_touched(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'theirs',
            'description' => 'Block bad bots',
            'expression' => '(http.user_agent contains "curl")',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block')['ok']);

        // Someone else's custom rule keeps its expression; ours is added beside it.
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rulesets/rs1/rules'));
    }

    public function test_a_bad_country_code_is_refused_rather_than_dropped(): void
    {
        Http::fake();

        // Silently skipping "USA" would leave the operator believing the country
        // is covered when the rule never mentions it.
        $result = $this->client()->applyCountryListEverywhere('cf-token', ['MX', 'USA'], 'block');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('USA', $result['message']);
        Http::assertNothingSent();
    }

    public function test_an_empty_list_is_refused_unless_the_rule_is_being_removed(): void
    {
        Http::fake();

        $this->assertFalse($this->client()->applyCountryListEverywhere('cf-token', [], 'block')['ok']);
        Http::assertNothingSent();
    }

    public function test_a_partial_failure_is_not_reported_as_success(): void
    {
        Http::fake([
            '*/zones/z2/*' => Http::response('', 500),
            '*/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => []]]),
            '*/rulesets*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com'], ['id' => 'z2', 'name' => 'b.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $result = $this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block');

        // Half the sites protected is not the operation the operator asked for.
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('נכשלה ב-1', $result['message']);
    }

    public function test_the_overview_reports_the_list_that_is_actually_in_force(): void
    {
        $this->fakeZones(['z1', 'z2'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
            'expression' => '(ip.src.country in {"MX" "HK" "IR"})',
            'action' => 'managed_challenge',
        ]]);

        $overview = $this->client()->countryListOverview('cf-token');

        $this->assertTrue($overview['ok']);
        // Sorted, so that two zones holding the same set compare as the same set.
        $this->assertSame(['HK', 'IR', 'MX'], $overview['actions']['managed_challenge']['countries']);
        $this->assertTrue($overview['actions']['managed_challenge']['consistent']);
        $this->assertSame(2, $overview['actions']['managed_challenge']['zones']);
        $this->assertSame(2, $overview['total_zones']);
    }

    /** Fake a zone whose legacy country access rules are $rules. */
    private function fakeLegacyRules(array $rules): void
    {
        $deleted = [];

        Http::fake([
            '*/access_rules/rules/*' => function ($request) use (&$deleted) {
                $deleted[] = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['success' => true]);
            },
            '*/access_rules/rules*' => function () use ($rules, &$deleted) {
                // Deleted rules really disappear, so a re-read after a sweep
                // cannot hand the same rule back forever.
                return Http::response([
                    'success' => true,
                    'result' => array_values(array_filter($rules, fn (array $r): bool => ! in_array($r['id'], $deleted, true))),
                    'result_info' => ['total_pages' => 1],
                ]);
            },
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);
    }

    public function test_cleaning_up_removes_the_old_per_country_rules_but_keeps_allow_rules(): void
    {
        $this->fakeLegacyRules([
            ['id' => 'r1', 'mode' => 'block', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'configuration' => ['target' => 'country', 'value' => 'MX']],
            ['id' => 'r2', 'mode' => 'managed_challenge', 'notes' => 'Multi Digital agent — country rule',
                'configuration' => ['target' => 'country', 'value' => 'HK']],
            ['id' => 'r3', 'mode' => 'whitelist', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'configuration' => ['target' => 'country', 'value' => 'IL']],
        ]);

        $result = $this->client()->removeLegacyCountryRulesEverywhere('cf-token');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['removed']);

        // An allow rule exists to let someone THROUGH; deleting it would tighten
        // access rather than free up budget.
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/r3'));
    }

    public function test_cleaning_up_never_deletes_a_rule_somebody_else_made(): void
    {
        $this->fakeLegacyRules([
            ['id' => 'ours', 'mode' => 'block', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'configuration' => ['target' => 'country', 'value' => 'MX']],
            ['id' => 'theirs', 'mode' => 'block', 'notes' => 'Ops team — do not remove',
                'configuration' => ['target' => 'country', 'value' => 'BR']],
        ]);

        $result = $this->client()->removeLegacyCountryRulesEverywhere('cf-token');

        // A rule made by hand or by another integration did not ask us to tidy
        // up after it.
        $this->assertSame(1, $result['removed']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/theirs'));
    }

    public function test_cleaning_up_also_takes_a_foreign_rule_the_new_list_now_covers(): void
    {
        $this->fakeLegacyRules([
            ['id' => 'theirs', 'mode' => 'block', 'notes' => 'Added in the dashboard',
                'configuration' => ['target' => 'country', 'value' => 'BR']],
            ['id' => 'unrelated', 'mode' => 'block', 'notes' => 'Added in the dashboard',
                'configuration' => ['target' => 'country', 'value' => 'RU']],
        ]);

        // BR is now handled by the combined rule, so the old rule for it is
        // redundant whoever wrote it. RU is not covered and stays.
        $result = $this->client()->removeLegacyCountryRulesEverywhere('cf-token', ['BR']);

        $this->assertSame(1, $result['removed']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/unrelated'));
    }

    public function test_the_overview_refuses_to_offer_a_list_when_the_zones_disagree(): void
    {
        $rule = fn (string $expression): array => [[
            'id' => 'r', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
            'expression' => $expression, 'action' => 'block',
        ]];

        Http::fake([
            '*/zones/z1/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => $rule('(ip.src.country in {"MX"})')]]),
            '*/zones/z2/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs2', 'rules' => $rule('(ip.src.country in {"MX" "HK"})')]]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com'], ['id' => 'z2', 'name' => 'b.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $overview = $this->client()->countryListOverview('cf-token');

        // A run that failed halfway leaves this behind. Preloading either list
        // would let a re-save quietly push the wrong countries back out.
        $this->assertFalse($overview['actions']['block']['consistent']);
        $this->assertSame([], $overview['actions']['block']['countries']);
    }

    public function test_the_panel_applies_a_pasted_country_list_in_one_action(): void
    {
        config(['billing.cloudflare.api_token' => 'saved-token']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->fakeZones(['z1']);

        // Tags on screen, one comma-joined string by the time the action sees
        // them — the shape the client has to survive.
        Livewire::test(ListSites::class)
            ->callAction('countryRule', data: [
                'mode' => 'block',
                'operation' => 'replace',
                'countries' => ['MX', 'HK', 'IR'],
                'remove_legacy' => false,
            ])
            ->assertHasNoActionErrors();

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rulesets/rs1/rules')
            && data_get($request->data(), 'expression') === '(ip.src.country in {"HK" "IR" "MX"})');
    }

    public function test_a_zone_missing_the_rule_counts_as_a_disagreement(): void
    {
        Http::fake([
            '*/zones/z1/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => [[
                'id' => 'r', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'expression' => '(ip.src.country in {"MX"})', 'action' => 'block',
            ]]]]),
            '*/zones/z2/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs2', 'rules' => []]]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com'], ['id' => 'z2', 'name' => 'b.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $overview = $this->client()->countryListOverview('cf-token');

        // What a removal that failed halfway looks like. Offering the surviving
        // list would let a re-save put the rule back where it was just deleted.
        $this->assertFalse($overview['actions']['block']['consistent']);
        $this->assertSame([], $overview['actions']['block']['countries']);
    }

    public function test_the_allow_list_drops_a_country_left_out_of_it(): void
    {
        $this->fakeLegacyRules([
            ['id' => 'keep', 'mode' => 'whitelist', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'configuration' => ['target' => 'country', 'value' => 'US']],
            ['id' => 'drop', 'mode' => 'whitelist', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                'configuration' => ['target' => 'country', 'value' => 'IL']],
            ['id' => 'theirs', 'mode' => 'whitelist', 'notes' => 'Added by the ops team',
                'configuration' => ['target' => 'country', 'value' => 'DE']],
        ]);

        $result = $this->client()->applyCountryListEverywhere('cf-token', ['US'], 'whitelist', 'replace');

        $this->assertTrue($result['ok']);

        // The saved list IS the list: a country taken out of it stops bypassing
        // Cloudflare. Somebody else's allow rule is theirs to remove.
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/drop'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/theirs'));
    }

    public function test_legacy_cleanup_reaches_rules_past_the_first_page(): void
    {
        $filler = [];

        for ($i = 0; $i < 100; $i++) {
            $filler[] = ['id' => "w{$i}", 'mode' => 'whitelist', 'notes' => '',
                'configuration' => ['target' => 'country', 'value' => 'IL']];
        }

        Http::fake([
            '*/access_rules/rules/*' => Http::response(['success' => true]),
            '*/access_rules/rules*' => function ($request) use ($filler) {
                $page = (int) (data_get($request->data(), 'page') ?: 1);

                // A first page of nothing but allow rules must not end the scan.
                return Http::response([
                    'success' => true,
                    'result' => $page === 1 ? $filler : [[
                        'id' => 'ours', 'mode' => 'block', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                        'configuration' => ['target' => 'country', 'value' => 'MX'],
                    ]],
                    'result_info' => ['total_pages' => 2],
                ]);
            },
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $result = $this->client()->removeLegacyCountryRulesEverywhere('cf-token');

        $this->assertSame(1, $result['removed']);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/ours'));
    }

    public function test_an_allow_list_leaves_the_block_list_alone(): void
    {
        Http::fake([
            '*/access_rules/rules/*' => Http::response(['success' => true]),
            '*/access_rules/rules*' => Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            '*/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => [[
                'id' => 'blocked', 'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
                'expression' => '(ip.src.country in {"CN" "RU"})', 'action' => 'block',
            ]]]]),
            '*/rulesets*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $result = $this->client()->applyCountryListEverywhere('cf-token', ['US'], 'whitelist', 'replace');

        $this->assertTrue($result['ok']);

        // Allowing US says nothing about RU and CN. Wiping their rule because a
        // different question was answered is how a policy disappears unnoticed.
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/'));
    }

    public function test_a_block_list_leaves_the_allow_list_alone(): void
    {
        Http::fake([
            '*/access_rules/rules/*' => Http::response(['success' => true]),
            '*/access_rules/rules*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'allow', 'mode' => 'whitelist', 'notes' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
                        'configuration' => ['target' => 'country', 'value' => 'US']],
                ],
                'result_info' => ['total_pages' => 1],
            ]),
            '*/rulesets/phases/*' => Http::response(['success' => true, 'result' => ['id' => 'rs1', 'rules' => []]]),
            '*/rulesets*' => Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block', 'replace')['ok']);

        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'access_rules/rules/allow'));
    }

    public function test_a_country_can_be_added_without_restating_the_list(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
            'expression' => '(ip.src.country in {"CN" "RU"})',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block', 'add')['ok']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && data_get($request->data(), 'expression') === '(ip.src.country in {"CN" "MX" "RU"})');
    }

    public function test_a_country_can_be_dropped_without_restating_the_list(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
            'expression' => '(ip.src.country in {"CN" "MX" "RU"})',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block', 'subtract')['ok']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && data_get($request->data(), 'expression') === '(ip.src.country in {"CN" "RU"})');
    }

    public function test_dropping_the_last_country_deletes_the_rule(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
            'expression' => '(ip.src.country in {"MX"})',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'block', 'subtract')['ok']);

        // An empty rule matches nobody but reads on the dashboard as if
        // something is still set.
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/rs1/rules/existing'));
    }

    public function test_each_action_keeps_its_own_list(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'blocked',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (block)',
            'expression' => '(ip.src.country in {"RU"})',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', ['MX'], 'managed_challenge', 'add')['ok']);

        // A challenge list is a second rule beside the block list, not a rewrite
        // of it.
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && data_get($request->data(), 'expression') === '(ip.src.country in {"MX"})'
            && data_get($request->data(), 'action') === 'managed_challenge');
    }

    public function test_the_modal_opens_empty_and_loads_the_list_only_to_replace_it(): void
    {
        config(['billing.cloudflare.api_token' => 'saved-token']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION.' (managed_challenge)',
            'expression' => '(ip.src.country in {"MX" "HK"})',
            'action' => 'managed_challenge',
        ]]);

        $component = Livewire::test(ListSites::class)->mountAction('countryRule');

        // The default is "add", where the box means "which countries to add".
        // Preloading it there would make one careless submit re-add everything —
        // and in "subtract" it would remove the entire list.
        $component->assertActionDataSet(['operation' => 'add', 'countries' => []]);

        // Only replacing means "this is the whole list from now on", so only
        // there is the list in force worth loading for editing.
        $component->setActionData(['mode' => 'managed_challenge', 'operation' => 'replace'])
            ->assertActionDataSet(['countries' => ['HK', 'MX']]);
    }

    public function test_nothing_is_sent_without_a_token(): void
    {
        Http::fake();

        $this->assertFalse($this->client()->applyCountryListEverywhere('', ['MX'], 'block')['ok']);
        $this->assertFalse($this->client()->removeLegacyCountryRulesEverywhere('')['ok']);
        $this->assertFalse($this->client()->countryListOverview('')['ok']);
        Http::assertNothingSent();
    }
}
