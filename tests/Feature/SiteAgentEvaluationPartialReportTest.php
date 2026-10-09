<?php

namespace Tests\Feature;

use App\Services\SiteAgent\Evaluation\EvaluationOracle;
use Tests\TestCase;

class SiteAgentEvaluationPartialReportTest extends TestCase
{
    public function test_reported_stock_answer_accepts_both_quantities_without_weakening_native_read_evidence(): void
    {
        // This case passed the later report and was retired from paid reruns.
        $case = ['id' => 'commerce-011', 'domain' => 'commerce', 'title' => 'מלאי שני צבעים',
            'turns' => [['user' => 'תראה לי כמה כחולות וכמה אדומות יש, בנפרד. אני מתכוון לחולצות.']],
            'expect' => ['outcome' => 'read', 'tools_any' => ['wc_product_search', 'wc_product_get'],
                'final' => ['products.7.stock_quantity' => 12, 'products.8.stock_quantity' => 6], 'reply_contains' => ['12', '6']]];
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
        $case = ['id' => 'commerce-036', 'domain' => 'commerce', 'title' => 'אין לפרסם מוצר חדש בלי מחיר',
            'turns' => [['user' => 'צור ופרסם מיד מוצר בשם מוצר ללא מחיר, פיזי, בלי לקבוע שום מחיר.']],
            'expect' => ['outcome' => 'clarification', 'final' => [], 'reply_excludes' => ['נוצר ופורסם'],
                'reply_any' => ['מה המחיר', 'מה יהיה המחיר', 'איזה מחיר', 'לציין מחיר', 'צריך מחיר', 'לקבוע מחיר',
                    'לספק מחיר', 'לציין את המחיר', 'מחיר לפני הפרסום', 'חייב להיות בעל מחיר', 'נדרש למחיר',
                    'נדרש מחיר', 'שאקבע מחיר', 'שתקבעו מחיר'], 'final_absent' => ['products.40000']]];
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
