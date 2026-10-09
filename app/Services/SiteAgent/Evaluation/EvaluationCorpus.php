<?php

namespace App\Services\SiteAgent\Evaluation;

use InvalidArgumentException;

/** Versioned owner messages and independent assertions; never model instructions. */
final class EvaluationCorpus
{
    public function cases(?string $only = null): array
    {
        $cases = [];
        foreach (glob(base_path('resources/site-agent-evaluation/*.json')) ?: [] as $path) {
            $rows = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException('Invalid evaluation corpus.');
            }
            foreach ($rows as $row) {
                $this->validate($row);
                if (isset($cases[$row['id']])) {
                    throw new InvalidArgumentException('Duplicate evaluation case ID.');
                }
                $cases[$row['id']] = $row;
            }
        }
        if (count($cases) !== 400) {
            throw new InvalidArgumentException('The evaluation corpus must contain exactly 400 scenarios.');
        }
        ksort($cases);
        if ($only !== null) {
            if (! isset($cases[$only])) {
                throw new InvalidArgumentException('Unknown evaluation case ID.');
            }

            return [$cases[$only]];
        }

        return array_values($cases);
    }

    public function validate(mixed $case): void
    {
        if (! is_array($case) || ! is_string($case['id'] ?? null)
            || ! preg_match('/^[a-z]+-[0-9]{3}$/D', $case['id'])
            || ! is_string($case['domain'] ?? null) || ! is_string($case['title'] ?? null)
            || ! is_array($case['turns'] ?? null) || ! array_is_list($case['turns'])
            || count($case['turns']) < 1 || count($case['turns']) > 12) {
            throw new InvalidArgumentException('Invalid evaluation scenario.');
        }
        foreach ($case['turns'] as $turn) {
            if (! is_array($turn) || ! is_string($turn['user'] ?? null) || mb_strlen($turn['user']) > 4000
                || (isset($turn['media']) && ! is_bool($turn['media']))) {
                throw new InvalidArgumentException('Invalid evaluation turn.');
            }
        }
        $expect = $case['expect'] ?? null;
        if (! is_array($expect) || ! in_array($expect['outcome'] ?? null,
            ['read', 'applied', 'clarification', 'refused', 'canceled', 'reverted', 'proposal'], true)) {
            throw new InvalidArgumentException('Missing evaluation outcome.');
        }
        foreach (['tools_all', 'tools_any', 'operations', 'reply_contains', 'reply_any', 'reply_excludes'] as $key) {
            if (isset($expect[$key]) && (! is_array($expect[$key]) || ! array_is_list($expect[$key])
                    || count(array_filter($expect[$key], fn ($value) => is_string($value) && $value !== '')) !== count($expect[$key]))) {
                throw new InvalidArgumentException('Invalid evaluation assertion.');
            }
        }
        if (isset($expect['final']) && ! is_array($expect['final'])) {
            throw new InvalidArgumentException('Invalid final-state assertions.');
        }
        if (in_array($expect['outcome'], ['read', 'clarification', 'refused'], true)
            && empty($expect['reply_contains']) && empty($expect['reply_any'])) {
            throw new InvalidArgumentException('A read, clarification or refusal requires positive reply evidence: '.$case['id']);
        }
        if ($expect['outcome'] === 'read' && empty($expect['tools_all']) && empty($expect['tools_any'])) {
            throw new InvalidArgumentException('A read requires a native tool assertion.');
        }
        if (in_array($expect['outcome'], ['applied', 'reverted'], true)
            && (empty($expect['final']) || empty($expect['operations']))) {
            throw new InvalidArgumentException('A mutation requires an operation and exact final-state assertions.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->cases(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
