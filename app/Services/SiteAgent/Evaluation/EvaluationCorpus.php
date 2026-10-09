<?php

namespace App\Services\SiteAgent\Evaluation;

use InvalidArgumentException;

/** Versioned owner messages and independent assertions; never model instructions. */
final class EvaluationCorpus
{
    public const SUITES = ['original' => 'הסבב המקורי', 'round2' => 'הסבב השני', 'all' => 'כל התרחישים הפעילים'];

    /** Counts follow the active corpus after any explicitly retired scenarios. */
    public function suiteCounts(): array
    {
        $cases = $this->cases();
        $round2 = count(array_filter($cases, fn (array $case): bool => str_starts_with($case['id'], 'round2-')));

        return ['original' => count($cases) - $round2, 'round2' => $round2, 'all' => count($cases)];
    }

    public function cases(?string $only = null, string $suite = 'all'): array
    {
        if (! array_key_exists($suite, self::SUITES)) {
            throw new InvalidArgumentException('Unknown evaluation suite.');
        }
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
        if ($cases === []) {
            throw new InvalidArgumentException('The active evaluation corpus must not be empty.');
        }
        ksort($cases);
        if ($suite !== 'all') {
            $cases = array_filter($cases, fn (array $case): bool => str_starts_with($case['id'], 'round2-') === ($suite === 'round2'));
        }
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
            || ! preg_match('/^(?:[a-z]+|round2-(?:shop|content|manage))-[0-9]{3}$/D', $case['id'])
            || ! is_string($case['domain'] ?? null) || trim($case['domain']) === ''
            || ! is_string($case['title'] ?? null) || trim($case['title']) === ''
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
        if (array_key_exists('model_required', $expect) && ! is_bool($expect['model_required'])) {
            throw new InvalidArgumentException('The model-required assertion must be boolean.');
        }
        foreach (['tools_all', 'tools_any', 'operations', 'reply_contains', 'reply_any', 'reply_excludes', 'final_absent'] as $key) {
            if (isset($expect[$key]) && (! is_array($expect[$key]) || ! array_is_list($expect[$key])
                    || count(array_filter($expect[$key], fn ($value) => is_string($value) && $value !== '')) !== count($expect[$key]))) {
                throw new InvalidArgumentException('Invalid evaluation assertion.');
            }
        }
        if (isset($expect['final']) && ! is_array($expect['final'])) {
            throw new InvalidArgumentException('Invalid final-state assertions.');
        }
        if (isset($expect['plan']) && ! is_array($expect['plan'])) {
            throw new InvalidArgumentException('Invalid proposal assertions.');
        }
        if (array_key_exists('plan_any', $expect)) {
            if (! is_array($expect['plan_any'])) {
                throw new InvalidArgumentException('Invalid proposal alternatives.');
            }
            foreach ($expect['plan_any'] as $path => $values) {
                if (! is_string($path) || $path === '' || ! is_array($values) || ! array_is_list($values)
                    || count($values) < 1 || count($values) > 20) {
                    throw new InvalidArgumentException('Invalid proposal alternatives.');
                }
            }
        }
        if (isset($expect['cancel_without_offer']) && (! is_bool($expect['cancel_without_offer'])
            || $expect['outcome'] !== 'canceled' || (empty($expect['reply_contains']) && empty($expect['reply_any'])))) {
            throw new InvalidArgumentException('A withdrawal without a proposal requires positive reply evidence.');
        }
        if (array_key_exists('reply_contract', $expect)) {
            $contract = $expect['reply_contract'];
            if (! in_array($expect['outcome'], ['refused', 'clarification'], true) || ! is_array($contract)
                || array_diff(array_keys($contract), ['topic_groups', 'unsupported_routes']) !== []
                || ! is_array($contract['topic_groups'] ?? null) || ! array_is_list($contract['topic_groups'])
                || count($contract['topic_groups']) < 1 || count($contract['topic_groups']) > 8) {
                throw new InvalidArgumentException('Invalid bounded reply contract.');
            }
            $groups = $contract['topic_groups'];
            if (array_key_exists('unsupported_routes', $contract)) {
                $groups[] = $contract['unsupported_routes'];
            }
            foreach ($groups as $alternatives) {
                if (! is_array($alternatives) || ! array_is_list($alternatives) || count($alternatives) < 1 || count($alternatives) > 20
                    || count(array_filter($alternatives, fn ($value): bool => is_string($value) && mb_strlen(trim($value)) >= 2 && mb_strlen($value) <= 100)) !== count($alternatives)) {
                    throw new InvalidArgumentException('A reply contract needs bounded nonempty topic alternatives.');
                }
            }
        }
        if (in_array($expect['outcome'], ['read', 'clarification', 'refused'], true)
            && empty($expect['reply_contains']) && empty($expect['reply_any']) && ! isset($expect['reply_contract'])) {
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
