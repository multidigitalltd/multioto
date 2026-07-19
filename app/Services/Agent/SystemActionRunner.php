<?php

namespace App\Services\Agent;

use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cloudflare\CloudflareClient;
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
            'close_ticket' => $this->closeTicket($payload),
            'set_ticket_status' => $this->setTicketStatus($payload),
            'set_ticket_priority' => $this->setTicketPriority($payload),
            'assign_ticket' => $this->assignTicket($payload),
            'update_customer' => $this->updateCustomer($payload),
            'update_subscription' => $this->updateSubscription($payload),
            'create_site' => $this->createSite($payload),
            'complete_task' => $this->completeTask($payload),
            'suspend_site' => $this->suspendSite($payload),
            'restore_site' => $this->restoreSite($payload),
            'purge_cloudflare_cache' => $this->purgeCloudflareCache($payload),
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
    private function closeTicket(array $p): void
    {
        $ticket = Ticket::find((int) ($p['ticket_id'] ?? 0));

        if (! $ticket) {
            throw new \RuntimeException('הפנייה לא נמצאה.');
        }

        // Administrative close (like the WhatsApp "סגור" command) — no customer
        // notification. Skip if it's already terminal.
        if (! in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            $ticket->update(['status' => TicketStatus::Closed]);
        }
    }

    /** @param array<string, mixed> $p */
    private function setTicketStatus(array $p): void
    {
        $ticket = $this->ticket($p);
        $status = TicketStatus::from((string) ($p['status'] ?? ''));

        // Resolved fires the customer "handled" notification via the observer;
        // the other statuses are internal-only — the existing behaviour.
        $ticket->update(['status' => $status]);
    }

    /** @param array<string, mixed> $p */
    private function setTicketPriority(array $p): void
    {
        $this->ticket($p)->update(['priority' => TicketPriority::from((string) ($p['priority'] ?? ''))]);
    }

    /** @param array<string, mixed> $p */
    private function assignTicket(array $p): void
    {
        $assignee = trim((string) ($p['assignee'] ?? ''));

        if ($assignee === '') {
            throw new \RuntimeException('חסר שם נציג לשיוך.');
        }

        $this->ticket($p)->update(['assignee' => $assignee]);
    }

    /** @param array<string, mixed> $p */
    private function updateCustomer(array $p): void
    {
        $customer = Customer::find((int) ($p['customer_id'] ?? 0));

        if (! $customer) {
            throw new \RuntimeException('הלקוח לא נמצא.');
        }

        // Re-whitelist at execution time — never trust the payload to be scoped.
        $allowed = ['name', 'email', 'phone', 'address', 'notes', 'vat_exempt'];
        $changes = array_intersect_key((array) ($p['changes'] ?? []), array_flip($allowed));

        if ($changes === []) {
            throw new \RuntimeException('אין שדות מותרים לעדכון.');
        }

        $customer->update($changes);
    }

    /** @param array<string, mixed> $p */
    private function updateSubscription(array $p): void
    {
        $subscription = Subscription::find((int) ($p['subscription_id'] ?? 0));

        if (! $subscription) {
            throw new \RuntimeException('המנוי לא נמצא.');
        }

        $changes = (array) ($p['changes'] ?? []);
        $update = [];

        if (isset($changes['price_agorot_override']) && (int) $changes['price_agorot_override'] > 0) {
            $update['price_agorot_override'] = (int) $changes['price_agorot_override'];
        }
        if (isset($changes['status'])) {
            $status = SubscriptionStatus::from((string) $changes['status']);
            $update['status'] = $status;
            if ($status === SubscriptionStatus::Canceled) {
                $update['canceled_at'] = now();
            }
        }

        if ($update === []) {
            throw new \RuntimeException('אין מחיר או סטטוס לעדכון.');
        }

        $subscription->update($update);
    }

    /** @param array<string, mixed> $p */
    private function createSite(array $p): void
    {
        $customer = Customer::find((int) ($p['customer_id'] ?? 0));
        $domain = trim((string) ($p['domain'] ?? ''));

        if (! $customer || $domain === '') {
            throw new \RuntimeException('חסר לקוח או דומיין.');
        }

        Site::firstOrCreate(
            ['domain' => $domain],
            ['customer_id' => $customer->id, 'status' => SiteStatus::Active],
        );
    }

    /** @param array<string, mixed> $p */
    private function completeTask(array $p): void
    {
        $task = Task::find((int) ($p['task_id'] ?? 0));

        if (! $task) {
            throw new \RuntimeException('המשימה לא נמצאה.');
        }

        // The TaskObserver stamps completed_at on the status change.
        if ($task->status !== TaskStatus::Done) {
            $task->update(['status' => TaskStatus::Done]);
        }
    }

    /** @param array<string, mixed> $p */
    private function ticket(array $p): Ticket
    {
        $ticket = Ticket::find((int) ($p['ticket_id'] ?? 0));

        if (! $ticket) {
            throw new \RuntimeException('הפנייה לא נמצאה.');
        }

        return $ticket;
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
    private function purgeCloudflareCache(array $p): void
    {
        $site = $this->site($p);
        $token = trim((string) config('billing.cloudflare.api_token'));

        if ($token === '') {
            throw new \RuntimeException('לא הוגדר טוקן API של Cloudflare — הגדירו אותו בהגדרות ← אינטגרציות.');
        }

        $result = app(CloudflareClient::class)->purgeCache($token, $site->domain);

        if (! $result['ok']) {
            throw new \RuntimeException($result['message']);
        }
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
