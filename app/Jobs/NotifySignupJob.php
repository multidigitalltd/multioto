<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Alert the business team that a customer completed the public /join signup —
 * WhatsApp + email + the in-panel bell. Queued so the network calls never block
 * the signup response, and fired for EVERY signup regardless of payment method
 * (a credit-card signup opens no ticket, so this is the only team signal there).
 */
class NotifySignupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $customerId) {}

    public function handle(TeamNotifier $notifier): void
    {
        $customer = Customer::find($this->customerId);

        if (! $customer) {
            return;
        }

        $notifier->newCustomer($customer);
    }
}
