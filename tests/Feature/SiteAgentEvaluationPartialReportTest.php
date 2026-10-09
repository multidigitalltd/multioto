<?php

namespace Tests\Feature;

use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationOracle;
use Tests\TestCase;

class SiteAgentEvaluationPartialReportTest extends TestCase
{
    public function test_reported_stock_answer_accepts_both_quantities_without_weakening_native_read_evidence(): void
    {
        $case = app(EvaluationCorpus::class)->cases('commerce-011')[0];
        $state = ['products' => [7 => ['stock_quantity' => 12], 8 => ['stock_quantity' => 6]]];
        $turns = [$this->turn($case, 'צהריים טובים נועה. בדקתי במלאי: יש כרגע 12 חולצות כחולות ו-6 חולצות אדומות.')];
        $turns[0]['calls'] = [['tool' => 'wc_product_search', 'arguments' => ['search' => 'חולצה'], 'write' => false,
            'result' => ['products' => [['id' => 7, 'stock_quantity' => 12], ['id' => 8, 'stock_quantity' => 6]]]]];

        $oracle = app(EvaluationOracle::class);
        $this->assertSame([], $oracle->evaluate($case, $turns, $state, $state));
        $turns[0]['calls'] = [];
        $this->assertContains('None of the alternative required native tools succeeded.', $oracle->evaluate($case, $turns, $state, $state));
    }

    public function test_reported_price_clarification_passes_only_without_an_offer_or_product_creation(): void
    {
        $case = app(EvaluationCorpus::class)->cases('commerce-036')[0];
        $turns = [$this->turn($case, "שלום נועה, כדי ליצור מוצר חדש באתר, אני נדרש למחיר עבורו.\n\nהאם תרצי שאקבע מחיר מסוים למוצר, או שתרצי שאגדיר אותו כמוצר בחינם?")];
        $oracle = app(EvaluationOracle::class);
        $this->assertSame([], $oracle->evaluate($case, $turns, [], []));
        $this->assertContains('Final state unexpectedly contains products.40000.',
            $oracle->evaluate($case, $turns, [], ['products' => [40000 => ['name' => 'מוצר ללא מחיר']]]));
        $turns[0]['calls'] = [['tool' => 'wc_product_create', 'arguments' => [], 'write' => true, 'result' => ['id' => 40000]]];
        $this->assertContains('A non-writing outcome attempted a native mutation.', $oracle->evaluate($case, $turns, [], []));
        $turns[0]['calls'] = [];
        $turns[0]['requests'] = [['id' => 1, 'state' => 'awaiting', 'preview' => $turns[0]['reply'], 'plan' => ['name' => 'מוצר ללא מחיר']]];
        $this->assertContains('Observed request lifecycle does not establish expected outcome: clarification.',
            $oracle->evaluate($case, $turns, [], []));
    }

    private function turn(array $case, string $reply): array
    {
        return ['user' => $case['turns'][0]['user'], 'reply' => $reply, 'media' => false, 'calls' => [],
            'before_request' => null, 'requests' => [], 'approved' => false, 'undo' => false];
    }
}
