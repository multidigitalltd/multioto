<?php

namespace App\Jobs;

use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Services\Calendar\ShabbatClock;
use App\Services\SiteAgent\SiteAgentAccess;
use App\Services\SiteAgent\SiteAgentBilling;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell a site owner when their agent stops, and when it comes back.
 *
 * Access itself is decided live on every message and needs nothing from here.
 * This job exists because a service that goes silent without saying why is
 * indistinguishable from a service that is broken — the customer's next move is
 * a support ticket, or nothing at all, and either way they were never told that
 * the fix is in their hands.
 *
 * It is written as a reconciliation rather than as a reaction to an event, and
 * that is the whole reliability argument: it compares what is true against what
 * the customer was last told, so EVERY path that can end a subscription is
 * covered — a failed charge, a cancellation, a plan whose agent flag was turned
 * off, a subscription deleted by hand — without any of them having to remember
 * to call anything. Running it a second time sends nothing.
 */
class SyncSiteAgentServiceStateJob implements ShouldQueue
{
    use Queueable;

    /**
     * Single attempt. A retry re-sends messages this run already delivered; the
     * hourly run is the retry, and it will only send what is still out of sync.
     */
    public int $tries = 1;

    public int $timeout = 300;

    /** Narrow the run to one customer — used when their subscription just moved. */
    public function __construct(public ?int $customerId = null) {}

    public function handle(
        WhatsAppCloudClient $whatsapp,
        SiteAgentBilling $billing,
        ShabbatClock $clock,
    ): void {
        if (! (bool) config('siteagent.enabled', false)) {
            return;
        }

        // Nothing is sent over Shabbat/Yom Tov. Nothing is recorded either, so
        // the first run afterwards says it once — rather than the state having
        // been quietly filed away as "told" while the customer heard nothing.
        if ($clock->isBlocked()) {
            return;
        }

        SiteAgentSubscriber::query()
            ->usable()
            ->when($this->customerId !== null, fn (Builder $query) => $query->where('customer_id', $this->customerId))
            ->with(['customer', 'site:id,domain'])
            // The entitlement for the whole chunk in one subquery. Asking it per
            // row is two subscription queries for every subscriber we have, on
            // a job that runs every hour forever.
            ->withExists(['customer as entitled' => fn (Builder $query) => $query
                ->whereHas('subscriptions', fn (Builder $s) => SiteAgentAccess::entitling($s))])
            ->chunkById(200, function ($subscribers) use ($whatsapp, $billing): void {
                foreach ($subscribers as $subscriber) {
                    $this->reconcile($subscriber, $whatsapp, $billing);
                }
            });
    }

    /** One subscriber: say it if it changed, record it either way. */
    private function reconcile(
        SiteAgentSubscriber $subscriber,
        WhatsAppCloudClient $whatsapp,
        SiteAgentBilling $billing,
    ): void {
        $actual = $subscriber->entitled
            ? SiteAgentSubscriber::STATE_ACTIVE
            : SiteAgentSubscriber::STATE_PAUSED;

        if ($subscriber->notified_service_state === $actual) {
            return;
        }

        // Never told before. Record what is true and say nothing: this is a
        // number that has just been set up, and announcing "the service is
        // active" to somebody who was activated five minutes ago — or worse,
        // "the subscription is not active" to somebody who never had one — is
        // noise at best.
        if ($subscriber->notified_service_state === null) {
            $this->record($subscriber, $actual);

            return;
        }

        $paused = $actual === SiteAgentSubscriber::STATE_PAUSED;

        // Nobody asked us for this message, so it is outside the 24-hour window
        // a customer's own message opens and Meta would refuse it as free text.
        // A customer whose last message was three weeks ago is exactly the
        // customer this notice is for.
        $template = (string) config(
            'siteagent.whatsapp.templates.'.($paused ? 'service_paused' : 'service_resumed'),
            '',
        );

        $sent = $template !== ''
            ? $whatsapp->sendTemplate($subscriber->phone, $template, $paused
                ? $billing->pausedTemplateParameters($subscriber)
                : $billing->resumedTemplateParameters($subscriber))
            : $whatsapp->sendText($subscriber->phone, $paused
                ? $billing->pausedMessage($subscriber)
                : $billing->resumedMessage($subscriber));

        if ($sent === null) {
            // Not recorded, so the next run tries again. Recording an
            // undelivered message is how a customer never finds out at all.
            return;
        }

        $this->record($subscriber, $actual);

        SystemLog::record(
            $actual === SiteAgentSubscriber::STATE_PAUSED ? 'warning' : 'info',
            'site-agent',
            $actual === SiteAgentSubscriber::STATE_PAUSED
                ? 'סוכן האתר '.($subscriber->site?->domain ?? '').' הושהה — אין מנוי פעיל'
                : 'סוכן האתר '.($subscriber->site?->domain ?? '').' חזר לפעול',
            ['subscriber_id' => $subscriber->id, 'customer_id' => $subscriber->customer_id],
        );
    }

    private function record(SiteAgentSubscriber $subscriber, string $state): void
    {
        $subscriber->forceFill([
            'notified_service_state' => $state,
            'notified_service_state_at' => now(),
        ])->save();
    }
}
