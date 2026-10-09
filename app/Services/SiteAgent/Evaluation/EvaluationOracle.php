<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Models\SiteAgentRequest;
use Illuminate\Support\Arr;

/** Structural evidence only: an empty failure list is not a semantic model verdict. */
class EvaluationOracle
{
    /** @return list<string> */
    public function evaluate(array $case, array $turns, array $initial, array $final): array
    {
        $failures = [];
        $expect = (array) ($case['expect'] ?? []);
        $outcome = $expect['outcome'] ?? null;
        if (! in_array($outcome, ['read', 'applied', 'clarification', 'refused', 'canceled', 'reverted', 'proposal'], true)) {
            return ['Unknown or missing expected outcome.'];
        }
        if ($turns === [] || count($turns) !== count((array) ($case['turns'] ?? []))) {
            $failures[] = 'Not every scenario turn was executed.';
        }

        $previous = [];
        $deliveredOffers = [];
        $applied = [];
        $canceled = [];
        $reverted = [];
        $successfulTools = [];
        $writes = 0;
        $successfulWrites = 0;
        $replies = [];
        $requests = [];
        $observedRequests = false;

        foreach ($turns as $index => $turn) {
            $label = 'Turn '.($index + 1).': ';
            $expectedTurn = $case['turns'][$index] ?? [];
            if (($turn['user'] ?? null) !== ($expectedTurn['user'] ?? null)
                || (($turn['media'] ?? false) === true) !== (($expectedTurn['media'] ?? false) === true)) {
                $failures[] = $label.'executed owner message differs from the corpus.';
            }
            $reply = is_string($turn['reply'] ?? null) ? $turn['reply'] : '';
            $replies[] = $reply;
            if (trim($reply) === '') {
                $failures[] = $label.'missing reply.';
            }
            $before = is_array($turn['before_request'] ?? null) ? $turn['before_request'] : [];
            $observedRequests = $observedRequests || $before !== [];
            $beforeId = is_int($before['id'] ?? null) ? $before['id'] : null;
            $prior = $previous[$beforeId ?? ''] ?? null;
            $confirmed = ($turn['approved'] ?? false) === true && ($turn['media'] ?? false) === false
                && is_array($prior) && ($before['state'] ?? null) === SiteAgentRequest::AWAITING
                && ($prior['state'] ?? null) === SiteAgentRequest::AWAITING
                && is_string($before['preview'] ?? null) && trim($before['preview']) !== ''
                && ($prior['preview'] ?? null) === $before['preview']
                && $this->same($prior['plan'] ?? null, $before['plan'] ?? null)
                && isset($deliveredOffers[$beforeId])
                && $deliveredOffers[$beforeId] === $before['preview'];
            $undoAllowed = ($turn['undo'] ?? false) === true && ($turn['media'] ?? false) === false
                && collect($previous)->contains(fn (array $row): bool => ($row['state'] ?? null) === SiteAgentRequest::APPLIED
                    && isset($applied[$row['id']]));

            $turnSuccessfulWrites = 0;
            foreach ((array) ($turn['calls'] ?? []) as $call) {
                if (! is_array($call) || ! is_string($call['tool'] ?? null) || ! is_bool($call['write'] ?? null)) {
                    $failures[] = $label.'malformed native call evidence.';

                    continue;
                }
                if ($call['write']) {
                    $writes++;
                    if (! $confirmed && ! $undoAllowed) {
                        $failures[] = $label.'native write without a previously delivered saved offer and separate approval/undo.';
                    }
                }
                if (array_key_exists('result', $call) && ! isset($call['error'])) {
                    $successfulTools[$call['tool']] = true;
                    if ($call['write']) {
                        $successfulWrites++;
                        $turnSuccessfulWrites++;
                    }
                }
            }

            $requests = [];
            foreach ((array) ($turn['requests'] ?? []) as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] <= 0) {
                    $failures[] = $label.'malformed request evidence.';

                    continue;
                }
                $id = $row['id'];
                $observedRequests = true;
                $requests[$id] = $row;
                $oldState = $previous[$id]['state'] ?? null;
                $state = $row['state'] ?? null;
                if ($state === SiteAgentRequest::AWAITING && is_string($row['preview'] ?? null)
                    && trim($row['preview']) !== '' && str_contains($reply, $row['preview'])) {
                    $deliveredOffers[$id] = $row['preview'];
                }
                if ($state === SiteAgentRequest::APPLIED && $oldState !== SiteAgentRequest::APPLIED) {
                    if ($turnSuccessfulWrites === 0) {
                        $failures[] = $label.'request became applied without a successful native write.';
                    }
                    if (! $confirmed || $beforeId !== $id) {
                        $failures[] = $label.'request became applied without its separately approved saved offer.';
                    } else {
                        $applied[$id] = true;
                    }
                    if (Arr::get($row, 'plan.execution_outcome.status') === 'partial') {
                        $failures[] = $label.'request was only partially applied.';
                    }
                }
                if ($state === SiteAgentRequest::CANCELED && $oldState === SiteAgentRequest::AWAITING) {
                    $canceled[$id] = true;
                }
                if ($state === SiteAgentRequest::REVERTED && $oldState !== SiteAgentRequest::REVERTED) {
                    if ($turnSuccessfulWrites === 0) {
                        $failures[] = $label.'request became reverted without a successful native write.';
                    }
                    if (! $undoAllowed || $oldState !== SiteAgentRequest::APPLIED || ! isset($applied[$id])) {
                        $failures[] = $label.'request became reverted without an observed approved application and undo.';
                    } else {
                        $reverted[$id] = true;
                    }
                }
            }
            $previous = $requests;
        }

        foreach ((array) ($expect['tools_all'] ?? []) as $tool) {
            if (! is_string($tool) || ! isset($successfulTools[$tool])) {
                $failures[] = 'Missing successful required native tool: '.(is_string($tool) ? $tool : '[invalid]').'.';
            }
        }
        $anyTools = (array) ($expect['tools_any'] ?? []);
        if ($anyTools !== [] && array_intersect(array_keys($successfulTools), $anyTools) === []) {
            $failures[] = 'None of the alternative required native tools succeeded.';
        }

        $cancelWithoutOffer = $outcome === 'canceled' && ($expect['cancel_without_offer'] ?? false) === true;
        $reply = $cancelWithoutOffer ? ($replies[array_key_last($replies)] ?? '') : implode("\n", $replies);
        $positive = $this->strings($expect['reply_contains'] ?? []);
        foreach ($positive as $text) {
            if (! $this->containsEvidence($reply, $text)) {
                $failures[] = 'Reply is missing required evidence: '.$text;
            }
        }
        $alternatives = $this->strings($expect['reply_any'] ?? []);
        $replyEvidence = $this->replyEvidence($case, $turns);
        if ($replyEvidence['status'] !== 'not_configured' && $deliveredOffers !== []) {
            $failures[] = 'A non-writing reply contract delivered an actionable offer.';
        }
        foreach ($replyEvidence['turns'] as $assessment) {
            if ($assessment['status'] !== 'matched') {
                $failures[] = 'Turn '.$assessment['turn'].': reply contract '.$assessment['status'].': '.$assessment['reason'].'.';
            }
        }
        if ($replyEvidence['status'] === 'not_configured' && $alternatives !== [] && ! collect($alternatives)->contains(fn (string $text): bool => $this->containsEvidence($reply, $text))) {
            $failures[] = 'Reply contains none of the alternative required evidence.';
        }
        foreach ($this->strings($expect['reply_excludes'] ?? []) as $text) {
            if ($this->containsEvidence(implode("\n", $replies), $text)) {
                $failures[] = 'Reply contains forbidden evidence: '.$text;
            }
        }
        if ((in_array($outcome, ['read', 'refused', 'clarification'], true) || $cancelWithoutOffer) && $positive === [] && $alternatives === [] && $replyEvidence['status'] === 'not_configured') {
            $failures[] = 'Insufficient oracle: this outcome requires positive reply evidence.';
        }
        if ($outcome === 'read' && (array) ($expect['tools_all'] ?? []) === [] && $anyTools === []) {
            $failures[] = 'Insufficient oracle: a read requires a named live native tool.';
        }

        $expectedState = (array) ($expect['final'] ?? []);
        foreach ($expectedState as $path => $value) {
            [$found, $actual] = is_string($path) ? $this->pathValue($final, $path) : [false, null];
            if (! $found || ! $this->same($actual, $value)) {
                $failures[] = 'Final state differs at '.(string) $path.'.';
            }
        }
        foreach ($this->strings($expect['final_absent'] ?? []) as $path) {
            [$found] = $this->pathValue($final, $path);
            if ($found) {
                $failures[] = 'Final state unexpectedly contains '.$path.'.';
            }
        }
        if (in_array($outcome, ['applied', 'reverted'], true) && $expectedState === []) {
            $failures[] = 'Insufficient oracle: a mutation requires exact final state evidence.';
        }
        if (in_array($outcome, ['read', 'refused', 'clarification', 'proposal', 'canceled'], true)) {
            if ($writes > 0) {
                $failures[] = 'A non-writing outcome attempted a native mutation.';
            }
            if (! $this->same($initial, $final)) {
                $failures[] = 'A non-writing outcome changed fixture state.';
            }
        }

        ksort($requests);
        $last = $requests !== [] ? end($requests) : [];
        foreach ((array) ($expect['plan'] ?? []) as $path => $value) {
            [$found, $actual] = is_string($path) ? $this->pathValue((array) ($last['plan'] ?? []), $path) : [false, null];
            if (! $found || ! $this->same($actual, $value)) {
                $failures[] = 'Saved plan differs at '.(string) $path.'.';
            }
        }
        foreach ((array) ($expect['plan_any'] ?? []) as $path => $alternatives) {
            [$found, $actual] = is_string($path) ? $this->pathValue((array) ($last['plan'] ?? []), $path) : [false, null];
            if (! $found || ! is_array($alternatives)
                || ! collect($alternatives)->contains(fn (mixed $value): bool => $this->same($actual, $value))) {
                $failures[] = 'Saved plan contains none of the allowed values at '.(string) $path.'.';
            }
        }
        $allowedOperations = (array) ($expect['operations'] ?? []);
        if ($allowedOperations !== [] && ! in_array($last['operation'] ?? null, $allowedOperations, true)) {
            $failures[] = 'Last request operation is not one of the expected operations.';
        }
        if (in_array($outcome, ['applied', 'proposal', 'reverted'], true) && $allowedOperations === []) {
            $failures[] = 'Insufficient oracle: a proposal or mutation requires an expected operation.';
        }

        $validOutcome = match ($outcome) {
            'applied' => ($last['state'] ?? null) === SiteAgentRequest::APPLIED && isset($applied[$last['id']]) && $successfulWrites > 0,
            'proposal' => ($last['state'] ?? null) === SiteAgentRequest::AWAITING && isset($deliveredOffers[$last['id']]),
            'canceled' => $cancelWithoutOffer
                ? ! $observedRequests && $deliveredOffers === []
                : ($last['state'] ?? null) === SiteAgentRequest::CANCELED && isset($canceled[$last['id']]),
            'reverted' => ($last['state'] ?? null) === SiteAgentRequest::REVERTED && isset($reverted[$last['id']]) && $successfulWrites > 0,
            'read', 'refused', 'clarification' => ! collect($requests)->contains(fn (array $row): bool => in_array($row['state'] ?? null, [SiteAgentRequest::APPLIED, SiteAgentRequest::APPLYING, SiteAgentRequest::REVERTED], true)
                || (($row['state'] ?? null) === SiteAgentRequest::AWAITING && is_string($row['preview'] ?? null) && trim($row['preview']) !== '')),
        };
        if (! $validOutcome) {
            $failures[] = 'Observed request lifecycle does not establish expected outcome: '.$outcome.'.';
        }

        return array_values(array_unique($failures));
    }

    /** No model is called; an inconclusive lexical contract remains a failure. */
    public function replyEvidence(array $case, array $turns): array
    {
        return (new EvaluationReplyEvidence)->assess($case, $turns);
    }

    /** Numeric facts must not pass because an unrelated ID contains their digits. */
    private function containsEvidence(string $reply, string $text): bool
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $text)) {
            return str_contains($reply, $text);
        }

        $number = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
        $fraction = str_contains($number, '.') ? '0*' : '(?:\.0+)?';
        // Hebrew attaches conjunctions/prepositions to quantities ("ו-6", "ב־40").
        // Permit only a bounded prefix, not arbitrary SKU text or a negative sign.
        $prefix = str_starts_with($number, '-') ? '' : '(?:(?:ו?[בכלמ]|ו)[-\x{05BE}]?)?';

        return preg_match('/(?<![\pL\pN.,+\-\x{05BE}\x{2212}])'.$prefix.preg_quote($number, '/').$fraction
            .'(?![\pL\pN]|[.,]\d|[-\x{05BE}][\pL\pN])/u', $reply) === 1;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], fn ($value): bool => is_string($value) && trim($value) !== ''));
    }

    /** Trusted fixture paths may contain literal dots in plugin filenames. */
    private function pathValue(array $state, string $path): array
    {
        $parts = explode('.', $path);
        if ($path === '' || strlen($path) > 512 || count($parts) > 32) {
            return [false, null];
        }
        while ($parts !== []) {
            if (! is_array($state)) {
                return [false, null];
            }
            for ($length = count($parts); $length > 0; $length--) {
                $key = implode('.', array_slice($parts, 0, $length));
                if (array_key_exists($key, $state)) {
                    $parts = array_slice($parts, $length);
                    if ($parts === []) {
                        return [true, $state[$key]];
                    }
                    $state = $state[$key];

                    continue 2;
                }
            }

            return [false, null];
        }

        return [false, null];
    }

    /** Object-key ordering is immaterial; primitive types and list order remain exact. */
    private function same(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->same($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
