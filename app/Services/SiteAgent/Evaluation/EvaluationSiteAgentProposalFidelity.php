<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Models\SiteAgentSubscriber;
use App\Services\SiteAgent\SiteAgentProposalFidelity;
use Throwable;

/** A per-scenario observer; review decisions and private inputs stay with the real service. */
final class EvaluationSiteAgentProposalFidelity extends SiteAgentProposalFidelity
{
    private const LIMIT = 50;

    private const REASONS = [
        'allow' => ['matched'],
        'revise' => ['wrong_action', 'wrong_target', 'wrong_value', 'missing_constraint', 'incomplete_change', 'extra_change'],
        'clarify' => ['missing_target', 'ambiguous_target', 'missing_value'],
        'refuse' => ['unsupported_action', 'excluded_alternative'],
        'unavailable' => ['provider_unavailable', 'invalid_input', 'invalid_scope', 'invalid_review'],
    ];

    private array $observed = [];

    public function __construct(private readonly SiteAgentProposalFidelity $delegate) {}

    public function review(SiteAgentSubscriber $subscriber, string $ownerText, string $operation, string $preview, array $ownerMessages = []): array
    {
        try {
            $result = $this->delegate->review($subscriber, $ownerText, $operation, $preview, $ownerMessages);
        } catch (Throwable $error) {
            $this->observe($operation, ['verdict' => 'unavailable', 'reason' => 'provider_unavailable']);

            throw $error;
        }
        $this->observe($operation, $result);

        return $result;
    }

    public function diagnostics(): array
    {
        return $this->observed;
    }

    private function observe(string $operation, array $result): void
    {
        if (count($this->observed) >= self::LIMIT) {
            return;
        }
        $verdict = $result['verdict'] ?? null;
        $reason = $result['reason'] ?? null;
        if (! is_string($verdict) || ! isset(self::REASONS[$verdict]) || ! in_array($reason, self::REASONS[$verdict], true)) {
            $verdict = 'unavailable';
            $reason = 'invalid_review';
        }
        $key = (string) config('billing.ai.api_key');
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $operation) !== 1
            || ($key !== '' && str_contains($operation, $key))) {
            $operation = 'unknown_operation';
        }

        $this->observed[] = ['ordinal' => count($this->observed) + 1, 'operation' => $operation,
            'verdict' => $verdict, 'reason' => $reason];
    }
}
