<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentRequest;
use Carbon\CarbonImmutable;

/**
 * What one turn of the assistant has done so far.
 *
 * Lives for a single message: the ids its reads returned (the only ones a
 * proposal may name), the offer it made if it made one, and the reply the page
 * editor produced if the turn was handed to it.
 */
final class SiteAgentTurn
{
    /** @var list<int|string> WordPress ids or type-scoped CCT references. */
    public array $seen = [];

    public ?SiteAgentRequest $request = null;

    public ?string $reply = null;

    public function __construct(private CarbonImmutable $startedAt) {}

    /** @param list<int|string> $ids */
    public function see(array $ids): void
    {
        $this->seen = array_values(array_unique([...$this->seen, ...$ids]));
    }

    /** An offer was made, or the page editor answered: nothing more may happen in this turn. */
    public function settled(): bool
    {
        return $this->request !== null || $this->reply !== null;
    }

    public function elapsed(): float
    {
        return $this->startedAt->diffInSeconds(now(), absolute: true);
    }
}
