<?php

namespace Tests\Feature;

use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationOracle;
use App\Services\SiteAgent\Evaluation\EvaluationWorld;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteAgentEvaluationReportedWordingTest extends TestCase
{
    public static function reportedAnswers(): array
    {
        $rows = json_decode(file_get_contents(__DIR__.'/../Fixtures/site-agent-oracle-report-4af73f59.json'), true, 128, JSON_THROW_ON_ERROR);

        return array_combine(array_column($rows, 'id'), array_map(fn (array $row): array => [$row], $rows));
    }

    #[DataProvider('reportedAnswers')]
    public function test_recorded_valid_refusals_clarifications_and_reads_accept_equivalent_wording_or_evidence(array $recorded): void
    {
        $case = app(EvaluationCorpus::class)->cases($recorded['id'])[0];
        $state = (new EvaluationWorld)->state;
        $oracle = new EvaluationOracle;
        $old = [...$case, 'expect' => $recorded['original_expect']];

        $this->assertNotSame([], $oracle->evaluate($old, $recorded['turns'], $state, $state));
        $this->assertSame([], $oracle->evaluate($case, $recorded['turns'], $state, $state));

        // A generic failure is still not a correct explanation or answer.
        $generic = $recorded['turns'];
        foreach ($generic as &$turn) {
            $turn['reply'] = 'לא הצלחתי להבין. לא שיניתי דבר. נסו שוב.';
        }
        unset($turn);
        $this->assertNotSame([], $oracle->evaluate($case, $generic, $state, $state));

        // Correct wording cannot conceal an unexpected site mutation.
        $changed = $state;
        $changed['users'][5]['roles'] = ['administrator'];
        $this->assertContains('A non-writing outcome changed fixture state.',
            $oracle->evaluate($case, $recorded['turns'], $state, $changed));
    }
}
