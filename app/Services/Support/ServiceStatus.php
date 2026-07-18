<?php

namespace App\Services\Support;

use App\Models\ServiceException;
use Illuminate\Support\Carbon;

/**
 * The team's current working mode — normal, or a marked reduced-capacity /
 * urgent-only day. Read by the agent so a new ticket's acknowledgement sets the
 * right expectation with the customer.
 */
class ServiceStatus
{
    /** The exception active right now (or on $date), or null when operating normally. */
    public function current(?Carbon $date = null): ?ServiceException
    {
        return ServiceException::query()->activeOn($date)->latest('id')->first();
    }

    /**
     * A short instruction for the agent describing today's mode, or null when
     * normal — appended to the prompt when acknowledging a new ticket.
     */
    public function agentGuidance(?Carbon $date = null): ?string
    {
        $exception = $this->current($date);

        if ($exception === null) {
            return null;
        }

        $note = trim((string) $exception->note);

        return trim($exception->mode->agentGuidance().($note !== '' ? " הערה פנימית: {$note}" : ''));
    }
}
