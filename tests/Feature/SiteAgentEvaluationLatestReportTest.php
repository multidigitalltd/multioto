<?php

namespace Tests\Feature;

use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationOracle;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use App\Services\SiteAgent\SiteAgentCapabilityReply;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Replays recorded evidence only; never invokes a provider or changes a site. */
class SiteAgentEvaluationLatestReportTest extends TestCase
{
    private const CORRECTED = ['commerce-119', 'content-121', 'content-131', 'content-132',
        'manage-062', 'manage-063', 'manage-064', 'manage-091', 'manage-104', 'manage-107',
        'manage-110', 'manage-114', 'manage-115', 'manage-117', 'manage-124'];

    public static function recordedCases(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/site-agent-oracle-report-51267751.json'), true, 128, JSON_THROW_ON_ERROR);

        return array_combine(array_column($fixture['cases'], 'id'), array_map(fn (array $case): array => [$case, $fixture['initial']], $fixture['cases']));
    }

    #[DataProvider('recordedCases')]
    public function test_recorded_evidence_keeps_all_real_failures_and_all_original_passes(array $recorded, array $initial): void
    {
        $case = app(EvaluationCorpus::class)->cases($recorded['id'])[0];
        // manage-130 is quoted for a future run; replay its original request here.
        $case['turns'] = array_map(fn (array $turn): array => ['user' => $turn['user'], 'media' => $turn['media']], $recorded['turns']);
        $final = array_replace($initial, $recorded['final_top_level_changes']);
        $failures = (new EvaluationOracle)->evaluate($case, $recorded['turns'], $initial, $final);
        $shouldPass = $recorded['reported_status'] === 'passed' || in_array($recorded['id'], self::CORRECTED, true);
        $this->assertSame($shouldPass, $failures === [], json_encode($failures, JSON_UNESCAPED_UNICODE));
    }

    public function test_source_punctuation_is_made_explicit_only_for_future_runs(): void
    {
        $case = app(EvaluationCorpus::class)->cases('manage-130')[0];
        $this->assertStringContainsString('ל"קונים מוצרים איכותיים עם שירות אישי".', $case['turns'][0]['user']);
        $this->assertSame('קונים מוצרים איכותיים עם שירות אישי', $case['expect']['final']['seo.43.description']);
        $this->assertCount(150, app(EvaluationCorpus::class)->cases());
    }

    public static function capabilityCases(): array
    {
        return [
            ['commerce-094', 'permanent_deletion'], ['commerce-108', 'order_customer_email'],
            ['commerce-128', 'permanent_subscription_cancellation'], ['content-043', 'elementor_structure'],
            ['content-044', 'elementor_structure'], ['content-098', 'elementor_link'], ['content-099', 'seo_canonical'],
            ['content-121', 'remote_media_download'], ['content-131', 'optimole_secrets'], ['content-132', 'optimole_offload'],
            ['manage-062', 'learndash_progress'], ['manage-063', 'learndash_progress'], ['manage-064', 'learndash_structure'],
            ['manage-091', 'protected_plugin'], ['manage-092', 'unrecoverable_update'], ['manage-096', 'unrecoverable_update'],
            ['manage-104', 'security_management'], ['manage-107', 'code_execution'], ['manage-110', 'menu_item_removal'],
            ['manage-114', 'optimole_secrets'], ['manage-115', 'cct_system_fields'], ['manage-116', 'learndash_progress'],
            ['manage-117', 'security_management'], ['round2-content-085', 'media_file_rename'],
            ['round2-manage-114', 'learndash_structure'], ['round2-manage-200', 'security_management'],
            ['round2-shop-095', 'permanent_deletion'], ['round2-shop-096', 'refund'],
        ];
    }

    #[DataProvider('capabilityCases')]
    public function test_verified_capability_explanations_satisfy_the_matching_scenario_without_actions(string $id, string $reason): void
    {
        $case = app(EvaluationCorpus::class)->cases($id)[0];
        $reply = app(SiteAgentCapabilityReply::class)->reply($reason);
        $turns = array_map(fn (array $turn): array => ['user' => $turn['user'], 'media' => false, 'reply' => $reply,
            'calls' => [], 'before_request' => null, 'requests' => [], 'approved' => false, 'undo' => false], $case['turns']);
        $initial = (new EvaluationWorld)->state;
        $this->assertSame([], (new EvaluationOracle)->evaluate($case, $turns, $initial, $initial));
        // A true but irrelevant capability explanation cannot satisfy this request.
        foreach ($turns as &$turn) {
            $turn['reply'] = app(SiteAgentCapabilityReply::class)->reply('support_handoff');
        }
        unset($turn);
        $this->assertNotSame([], (new EvaluationOracle)->evaluate($case, $turns, $initial, $initial));
    }
}
