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
    /**
     * The exception active right now (or on $date), or null when operating
     * normally. Returns null when the feature is switched off, so callers (the
     * agent, ticket acknowledgements) can read it unconditionally — a marked day
     * then has no effect until the feature is re-enabled in settings.
     */
    public function current(?Carbon $date = null): ?ServiceException
    {
        if (! config('billing.service_days.enabled', true)) {
            return null;
        }

        return ServiceException::query()->activeOn($date)->latest('id')->first();
    }

    /**
     * A short instruction for the agent describing today's mode, or null when
     * normal — appended to the prompt when acknowledging a new ticket. The
     * internal note is passed as confidential context with an explicit
     * non-disclosure rule so the model never repeats it to the customer.
     */
    public function agentGuidance(?Carbon $date = null): ?string
    {
        $exception = $this->current($date);

        if ($exception === null) {
            return null;
        }

        $note = trim((string) $exception->note);

        return trim($exception->mode->agentGuidance()
            .($note !== '' ? "\nהקשר פנימי (סודי — לשיקולך בלבד, אסור לצטט או לחשוף ללקוח): {$note}" : ''));
    }

    /**
     * A fixed, safe customer-facing notice for the current mode (used on the
     * non-AI template path), or null when operating normally. Never contains the
     * internal note.
     */
    public function customerNotice(?Carbon $date = null): ?string
    {
        return $this->current($date)?->mode->customerNotice();
    }
}
