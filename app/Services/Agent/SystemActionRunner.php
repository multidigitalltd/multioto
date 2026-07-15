<?php

namespace App\Services\Agent;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\SendPaymentLinkJob;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Task;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Hosting\HostingClient;

/**
 * Executes an APPROVED internal system action (proposed from the command console
 * and passed by the approval gate). This is the only place a console-proposed
 * system action touches the business, and it runs entirely on top of the
 * existing, idempotent services — it never reimplements billing/hosting logic.
 *
 * Guarded by the system-actions kill-switch: even an approved action does
 * nothing until an admin turns execution on.
 */
class SystemActionRunner
{
    public function __construct(
        private SubscriptionCollectionService $collections,
        private HostingClient $hosting,
    ) {}

    public function run(PendingAction $action): void
    {
        if (! config('agent.system_actions_enabled')) {
            throw new \RuntimeException('פעולות מערכת כבויות. הפעילו "אפשר ביצוע פעולות מערכת" בהגדרות ← סוכן AI.');
        }

        $payload = $action->payload ?? [];
        $operation = (string) ($payload['operation'] ?? '');

        match ($operation) {
            'open_task' => $this->openTask($payload),
            'send_payment_request' => $this->sendPaymentRequest($payload),
            'mark_collected' => $this->markCollected($payload),
            'suspend_site' => $this->suspendSite($payload),
            'restore_site' => $this->restoreSite($payload),
            default => throw new \RuntimeException("פעולת מערכת לא מוכרת: {$operation}"),
        };
    }

    /** @param array<string, mixed> $p */
    private function openTask(array $p): void
    {
        $task = Task::create([
            'title' => (string) ($p['title'] ?? 'משימה'),
            'customer_id' => $p['customer_id'] ?? null,
            'status' => TaskStatus::Open,
            'priority' => TicketPriority::Normal,
        ]);

        // No assignee → the managers are notified a task landed (same as the UI).
        NotifyTaskCreatedJob::dispatch($task->id);
    }

    /** @param array<string, mixed> $p */
    private function sendPaymentRequest(array $p): void
    {
        $customerId = (int) ($p['customer_id'] ?? 0);
        $agorot = (int) ($p['amount_agorot'] ?? 0);

        if ($customerId <= 0 || $agorot <= 0) {
            throw new \RuntimeException('חסר לקוח או סכום לדרישת התשלום.');
        }

        SendPaymentLinkJob::dispatch(
            $customerId,
            $agorot,
            (string) ($p['description'] ?? 'תשלום'),
            (string) ($p['channel'] ?? 'whatsapp'),
        );
    }

    /** @param array<string, mixed> $p */
    private function markCollected(array $p): void
    {
        // Resolve the subscription at run time (fresh state) so the collection
        // stays idempotent and can't act on stale data from proposal time.
        $subscription = Subscription::query()
            ->dueForManualCollection()
            ->where('customer_id', (int) ($p['customer_id'] ?? 0))
            ->first();

        if (! $subscription) {
            throw new \RuntimeException('אין מנוי בגבייה ידנית שממתין לגבייה עבור הלקוח.');
        }

        $this->collections->recordPayment($subscription, 'סומן כשולם דרך מסוף הפקודות.');
    }

    /** @param array<string, mixed> $p */
    private function suspendSite(array $p): void
    {
        $this->hosting->suspendSite($this->site($p));
    }

    /** @param array<string, mixed> $p */
    private function restoreSite(array $p): void
    {
        $this->hosting->restoreSite($this->site($p));
    }

    /** @param array<string, mixed> $p */
    private function site(array $p): Site
    {
        $site = Site::find((int) ($p['site_id'] ?? 0));

        if (! $site) {
            throw new \RuntimeException('האתר לא נמצא.');
        }

        return $site;
    }
}
