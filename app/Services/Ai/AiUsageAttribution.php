<?php

namespace App\Services\Ai;

/**
 * Who the AI calls running right now are for.
 *
 * Set around work done on one customer's behalf — a site-agent conversation —
 * so that ClaudeClient can book the tokens to that customer as well as to the
 * daily total. Nested calls restore the outer customer when they end.
 */
class AiUsageAttribution
{
    private ?int $customerId = null;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function for(?int $customerId, callable $work): mixed
    {
        $previous = $this->customerId;
        $this->customerId = $customerId;

        try {
            return $work();
        } finally {
            $this->customerId = $previous;
        }
    }

    public function current(): ?int
    {
        return $this->customerId;
    }
}
