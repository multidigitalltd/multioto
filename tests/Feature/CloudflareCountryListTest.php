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
            'cf-token', ['mx', 'HK', 'ir', 'CN'], 'block',
        );

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('2 אתרים', $result['message']);

        // One POST per zone — not one per country, which is the whole point.
        Http::assertSentCount(5); // 1 zones list + (1 entrypoint + 1 create) × 2
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
            'action' => 'block',
        ]]);

        $result = $this->client()->applyCountryListEverywhere('cf-token', ['MX', 'HK'], 'managed_challenge');

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

    public function test_removing_deletes_the_combined_rule(): void
    {
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
            'expression' => '(ip.src.country in {"MX"})',
            'action' => 'block',
        ]]);

        $this->assertTrue($this->client()->applyCountryListEverywhere('cf-token', [], 'remove')['ok']);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rulesets/rs1/rules/existing'));
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
        $this->assertSame(['HK', 'IR', 'MX'], $overview['countries']);
        $this->assertTrue($overview['consistent']);
        $this->assertSame('managed_challenge', $overview['mode']);
        $this->assertSame(2, $overview['zones']);
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
        $this->assertFalse($overview['consistent']);
        $this->assertSame([], $overview['countries']);
        $this->assertNull($overview['mode']);
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
                'countries' => ['MX', 'HK', 'IR'],
                'mode' => 'block',
                'remove_legacy' => false,
            ])
            ->assertHasNoActionErrors();

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rulesets/rs1/rules')
            && data_get($request->data(), 'expression') === '(ip.src.country in {"HK" "IR" "MX"})');
    }

    public function test_the_modal_opens_with_the_list_that_is_in_force(): void
    {
        config(['billing.cloudflare.api_token' => 'saved-token']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->fakeZones(['z1'], [[
            'id' => 'existing',
            'description' => CloudflareClient::COUNTRY_RULE_DESCRIPTION,
            'expression' => '(ip.src.country in {"MX" "HK"})',
            'action' => 'block',
        ]]);

        // Adding a country must be adding, not retyping two dozen codes from
        // memory and losing one.
        Livewire::test(ListSites::class)
            ->mountAction('countryRule')
            ->assertActionDataSet(['countries' => ['HK', 'MX'], 'mode' => 'block']);
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
