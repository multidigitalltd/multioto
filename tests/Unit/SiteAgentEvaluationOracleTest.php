<?php

namespace Tests\Unit;

use App\Services\SiteAgent\Evaluation\EvaluationOracle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteAgentEvaluationOracleTest extends TestCase
{
    private function request(string $state = 'awaiting', ?string $preview = 'Price: 100 → 90'): array
    {
        return ['id' => 1, 'state' => $state, 'operation' => 'update_price', 'preview' => $preview,
            'plan' => ['product_id' => 7, 'fields' => ['regular_price' => '90.00']]];
    }

    private function turn(array $overrides = []): array
    {
        return array_replace(['user' => 'change price', 'reply' => 'Price: 100 → 90', 'media' => false,
            'calls' => [], 'before_request' => null, 'requests' => [], 'approved' => false, 'undo' => false], $overrides);
    }

    private function write(): array
    {
        return ['tool' => 'wc_product_update', 'arguments' => ['product_id' => 7, 'regular_price' => '90.00'], 'write' => true, 'result' => ['changed' => true]];
    }

    private function application(): array
    {
        return [
            $this->turn(['requests' => [$this->request()]]),
            $this->turn(['user' => 'כן', 'reply' => 'Done', 'approved' => true, 'before_request' => $this->request(),
                'calls' => [$this->write()], 'requests' => [$this->request('applied')]]),
        ];
    }

    private function evaluate(array $expect, array $turns, array $initial = [], array $final = []): array
    {
        return (new EvaluationOracle)->evaluate(['turns' => array_map(fn (array $turn): array => ['user' => $turn['user'], 'media' => $turn['media']], $turns), 'expect' => $expect], $turns, $initial, $final);
    }

    public function test_a_real_approved_application_requires_tools_lifecycle_and_exact_result(): void
    {
        $failures = $this->evaluate(['outcome' => 'applied', 'operations' => ['update_price'], 'tools_all' => ['wc_product_update'],
            'final' => ['products.7.regular_price' => '90.00']], $this->application(),
            ['products' => [7 => ['regular_price' => '100.00']]], ['products' => [7 => ['regular_price' => '90.00']]]);

        self::assertSame([], $failures);
    }

    public static function invalidApprovals(): array
    {
        return array_map(fn (string $mode): array => [$mode], ['no_yes', 'media_yes', 'no_prior_offer', 'undelivered', 'changed_plan', 'changed_preview', 'question_only']);
    }

    #[DataProvider('invalidApprovals')]
    public function test_a_matching_final_value_cannot_hide_an_unapproved_write(string $mode): void
    {
        $turns = $this->application();
        match ($mode) {
            'no_yes' => $turns[1]['approved'] = false,
            'media_yes' => $turns[1]['media'] = true,
            'no_prior_offer' => $turns[0]['requests'] = [],
            'undelivered' => $turns[0]['reply'] = 'I could not prepare an offer.',
            'changed_plan' => $turns[1]['before_request']['plan']['fields']['regular_price'] = '80.00',
            'changed_preview' => $turns[1]['before_request']['preview'] = 'Different offer',
            'question_only' => $turns[0]['requests'][0]['preview'] = null,
        };

        $failures = $this->evaluate(['outcome' => 'applied', 'operations' => ['update_price'], 'final' => ['price' => '90.00']], $turns, ['price' => '100.00'], ['price' => '90.00']);

        self::assertStringContainsString('native write without', implode(' ', $failures));
        self::assertStringContainsString('without its separately approved saved offer', implode(' ', $failures));
    }

    public function test_write_then_restore_cannot_pass_a_nonwriting_case(): void
    {
        $turns = $this->application();
        $failures = $this->evaluate(['outcome' => 'read', 'tools_all' => ['wc_product_update'], 'reply_contains' => ['Done']], $turns, ['price' => '100.00'], ['price' => '100.00']);
        self::assertContains('A non-writing outcome attempted a native mutation.', $failures);
    }

    public function test_reads_need_successful_named_tools_and_factual_reply_evidence(): void
    {
        $call = ['tool' => 'wc_product_counts', 'arguments' => [], 'write' => false, 'result' => ['products' => ['total' => 40]]];
        $expect = ['outcome' => 'read', 'tools_all' => ['wc_product_counts'], 'reply_contains' => ['40 products'], 'reply_excludes' => ['only one']];
        self::assertSame([], $this->evaluate($expect, [$this->turn(['reply' => 'There are 40 products.', 'calls' => [$call]])]));
        unset($call['result']);
        $call['error'] = 'Unavailable';
        self::assertNotSame([], $this->evaluate($expect, [$this->turn(['reply' => 'There are 40 products.', 'calls' => [$call]])]));
        self::assertNotSame([], $this->evaluate($expect, [$this->turn(['reply' => 'There is only one product.', 'calls' => []])]));
    }

    public static function nonwritingOutcomes(): array
    {
        return [['read'], ['refused'], ['clarification']];
    }

    #[DataProvider('nonwritingOutcomes')]
    public function test_nonempty_replies_and_negative_checks_are_not_positive_evidence(string $outcome): void
    {
        $failures = $this->evaluate(['outcome' => $outcome, 'reply_excludes' => ['password']], [$this->turn(['reply' => 'Sure!'])]);
        self::assertContains('Insufficient oracle: this outcome requires positive reply evidence.', $failures);
    }

    public function test_reply_alternatives_allow_real_refusal_and_clarification_variations(): void
    {
        self::assertSame([], $this->evaluate(['outcome' => 'refused', 'reply_any' => ['cannot delete permanently', 'permanent deletion is unavailable']],
            [$this->turn(['reply' => 'I cannot delete permanently.'])]));
        self::assertSame([], $this->evaluate(['outcome' => 'clarification', 'reply_contains' => ['Which product?']],
            [$this->turn(['reply' => 'Which product?', 'requests' => [$this->request('awaiting', null)]])]));
    }

    public function test_a_refusal_cannot_leave_a_confirmable_offer_alive(): void
    {
        self::assertNotSame([], $this->evaluate(['outcome' => 'refused', 'reply_contains' => ['Cannot']],
            [$this->turn(['reply' => 'Cannot. Price: 100 → 90', 'requests' => [$this->request()]])]));
    }

    public function test_proposal_requires_a_delivered_saved_preview_and_no_writes(): void
    {
        $expect = ['outcome' => 'proposal', 'operations' => ['update_price']];
        self::assertSame([], $this->evaluate($expect, [$this->turn(['requests' => [$this->request()]])]));
        self::assertNotSame([], $this->evaluate($expect, [$this->turn()]));
        self::assertNotSame([], $this->evaluate($expect, [$this->turn(['reply' => 'Some other text', 'requests' => [$this->request()]])]));
    }

    public function test_cancellation_needs_an_observed_pending_request_not_just_cancelled_prose(): void
    {
        $turns = [$this->turn(['requests' => [$this->request()]]), $this->turn(['user' => 'לא', 'reply' => 'Canceled', 'requests' => [$this->request('canceled')]])];
        self::assertSame([], $this->evaluate(['outcome' => 'canceled'], $turns));
        self::assertNotSame([], $this->evaluate(['outcome' => 'canceled'], [$this->turn(['reply' => 'Canceled'])]));
    }

    public function test_undo_requires_prior_approved_application_and_an_explicit_undo_turn(): void
    {
        $turns = $this->application();
        $turns[] = $this->turn(['user' => 'בטל', 'reply' => 'Reverted', 'undo' => true, 'calls' => [$this->write()], 'requests' => [$this->request('reverted')]]);
        $expect = ['outcome' => 'reverted', 'operations' => ['update_price'], 'final' => ['price' => '100.00']];
        self::assertSame([], $this->evaluate($expect, $turns, ['price' => '100.00'], ['price' => '100.00']));
        $turns[2]['undo'] = false;
        self::assertNotSame([], $this->evaluate($expect, $turns, ['price' => '100.00'], ['price' => '100.00']));
    }

    public function test_strict_final_values_distinguish_missing_null_false_and_numeric_strings(): void
    {
        foreach ([[], ['virtual' => null], ['virtual' => 0], ['virtual' => 'false']] as $final) {
            self::assertContains('Final state differs at virtual.', $this->evaluate(['outcome' => 'proposal', 'operations' => ['update_price'], 'final' => ['virtual' => false]],
                [$this->turn(['requests' => [$this->request()]])], $final, $final));
        }
        $failures = $this->evaluate(['outcome' => 'applied', 'operations' => ['update_price'], 'final' => ['price' => '90.00']], $this->application(), [], ['price' => 90]);
        self::assertContains('Final state differs at price.', $failures);
    }

    public function test_any_unexpected_fixture_change_fails_a_read_even_outside_checked_paths(): void
    {
        $call = ['tool' => 'wc_product_get', 'arguments' => [], 'write' => false, 'result' => []];
        $failures = $this->evaluate(['outcome' => 'read', 'tools_all' => ['wc_product_get'], 'reply_contains' => ['90'], 'final' => ['price' => 90]],
            [$this->turn(['reply' => '90', 'calls' => [$call]])], ['price' => 90, 'stock' => 2], ['price' => 90, 'stock' => 0]);
        self::assertContains('A non-writing outcome changed fixture state.', $failures);
    }

    public function test_object_order_is_immaterial_but_list_order_is_not(): void
    {
        $expect = ['outcome' => 'proposal', 'operations' => ['update_price'], 'final' => ['object' => ['a' => 1, 'b' => false], 'list' => [1, 2]]];
        $state = ['object' => ['b' => false, 'a' => 1], 'list' => [1, 2]];
        self::assertSame([], $this->evaluate($expect, [$this->turn(['requests' => [$this->request()]])], $state, $state));
        $state['list'] = [2, 1];
        self::assertContains('Final state differs at list.', $this->evaluate($expect, [$this->turn(['requests' => [$this->request()]])], $state, $state));
    }

    public function test_partial_application_and_incomplete_execution_are_not_passes(): void
    {
        $turns = $this->application();
        $turns[1]['requests'][0]['plan']['execution_outcome']['status'] = 'partial';
        self::assertContains('Turn 2: request was only partially applied.', $this->evaluate(['outcome' => 'applied', 'operations' => ['update_price'], 'final' => ['price' => 90]], $turns, [], ['price' => 90]));
        $failures = (new EvaluationOracle)->evaluate(['turns' => [['user' => 'one'], ['user' => 'two']], 'expect' => ['outcome' => 'proposal']], [$this->turn()], [], []);
        self::assertContains('Not every scenario turn was executed.', $failures);
    }

    public function test_substituted_owner_messages_cannot_satisfy_the_corpus(): void
    {
        $failures = (new EvaluationOracle)->evaluate(['turns' => [['user' => 'Do not change anything']],
            'expect' => ['outcome' => 'proposal', 'operations' => ['update_price']]],
            [$this->turn(['requests' => [$this->request()]])], [], []);
        self::assertContains('Turn 1: executed owner message differs from the corpus.', $failures);
    }

    public function test_fixture_paths_support_literal_dots_using_the_longest_existing_key(): void
    {
        $state = ['plugins' => ['hello-dolly/hello.php' => ['active' => false]],
            'options' => ['a.b' => ['value' => null], 'a' => ['b' => ['value' => 1]]]];
        self::assertSame([], $this->evaluate(['outcome' => 'proposal', 'operations' => ['update_price'],
            'final' => ['plugins.hello-dolly/hello.php.active' => false, 'options.a.b.value' => null]],
            [$this->turn(['requests' => [$this->request()]])], $state, $state));
    }
}
