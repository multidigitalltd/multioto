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
        $replies = [];
        $requests = [];

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
                }
            }

            $requests = [];
            foreach ((array) ($turn['requests'] ?? []) as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] <= 0) {
                    $failures[] = $label.'malformed request evidence.';

                    continue;
                }
                $id = $row['id'];
                $requests[$id] = $row;
                $oldState = $previous[$id]['state'] ?? null;
                $state = $row['state'] ?? null;
                if ($state === SiteAgentRequest::AWAITING && is_string($row['preview'] ?? null)
                    && trim($row['preview']) !== '' && str_contains($reply, $row['preview'])) {
                    $deliveredOffers[$id] = $row['preview'];
                }
                if ($state === SiteAgentRequest::APPLIED && $oldState !== SiteAgentRequest::APPLIED) {
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

        $reply = implode("\n", $replies);
        $positive = $this->strings($expect['reply_contains'] ?? []);
        foreach ($positive as $text) {
            if (! str_contains($reply, $text)) {
                $failures[] = 'Reply is missing required evidence: '.$text;
            }
        }
        $alternatives = $this->strings($expect['reply_any'] ?? []);
        if ($alternatives !== [] && ! collect($alternatives)->contains(fn (string $text): bool => str_contains($reply, $text))) {
            $failures[] = 'Reply contains none of the alternative required evidence.';
        }
        foreach ($this->strings($expect['reply_excludes'] ?? []) as $text) {
            if (str_contains($reply, $text)) {
                $failures[] = 'Reply contains forbidden evidence: '.$text;
            }
        }
        if (in_array($outcome, ['read', 'refused', 'clarification'], true) && $positive === [] && $alternatives === []) {
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
        $allowedOperations = (array) ($expect['operations'] ?? []);
        if ($allowedOperations !== [] && ! in_array($last['operation'] ?? null, $allowedOperations, true)) {
            $failures[] = 'Last request operation is not one of the expected operations.';
        }
        if (in_array($outcome, ['applied', 'proposal', 'reverted'], true) && $allowedOperations === []) {
            $failures[] = 'Insufficient oracle: a proposal or mutation requires an expected operation.';
        }

        $validOutcome = match ($outcome) {
            'applied' => ($last['state'] ?? null) === SiteAgentRequest::APPLIED && isset($applied[$last['id']]) && $writes > 0,
            'proposal' => ($last['state'] ?? null) === SiteAgentRequest::AWAITING && isset($deliveredOffers[$last['id']]),
            'canceled' => ($last['state'] ?? null) === SiteAgentRequest::CANCELED && isset($canceled[$last['id']]),
            'reverted' => ($last['state'] ?? null) === SiteAgentRequest::REVERTED && isset($reverted[$last['id']]) && $writes > 0,
            'read', 'refused', 'clarification' => ! collect($requests)->contains(fn (array $row): bool => in_array($row['state'] ?? null, [SiteAgentRequest::APPLIED, SiteAgentRequest::APPLYING, SiteAgentRequest::REVERTED], true)
                || (($row['state'] ?? null) === SiteAgentRequest::AWAITING && is_string($row['preview'] ?? null) && trim($row['preview']) !== '')),
        };
        if (! $validOutcome) {
            $failures[] = 'Observed request lifecycle does not establish expected outcome: '.$outcome.'.';
        }

        return array_values(array_unique($failures));
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
