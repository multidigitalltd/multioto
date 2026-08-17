<?php

namespace App\Services\Billing;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendDunningNotificationJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Charge;
use App\Models\Subscription;

/**
 * The dunning state machine (§5). Stage timings and behavior are defined in
 * config('billing.dunning.stages') — never hardcoded here.
 *
 * Stage 0 = healthy. A failed charge advances the subscription one stage:
 * notify (WhatsApp + email) → schedule a retry → and at the final stage,
 * suspend the site and stop retrying. Recovery resets everything to stage 0.
 */
class DunningMachine
{
    /**
     * Advance the machine after a failed charge attempt.
     */
    public function handleFailure(Subscription $subscription, Charge $charge): void
    {
        $stages = config('billing.dunning.stages');
        $stage = min($subscription->dunning_stage + 1, max(array_keys($stages)));
        $config = $stages[$stage];

        $subscription->update([
            'status' => $config['suspend'] ? SubscriptionStatus::Suspended : SubscriptionStatus::PastDue,
            'dunning_stage' => $stage,
            'next_charge_at' => $config['retry_in_days'] !== null
                ? now()->addDays($config['retry_in_days'])->startOfDay()
                : null,
        ]);

        $this->notify($subscription, $charge, $stage, $this->templateFor($subscription, $config['template']));

        if ($config['suspend'] && $subscription->site_id) {
            SuspendSiteJob::dispatch($subscription->site_id);
        }
    }

    /**
     * The wording for this subscription, which is not always the stage's default.
     *
     * The ladder was written when every subscription was a hosted site, so its
     * last two rungs threaten to suspend one. A subscription with no site — a
     * plugin licence, a retainer — cannot have a site suspended, and telling a
     * customer their website is about to go down when it is not is both untrue
     * and the fastest way to teach people that our collection mail is noise.
     *
     * Resolution is by suffix, most specific first, falling back to the stage's
     * own template when no variant is defined. That way adding a variant is
     * adding a line to lang/he/dunning.php, and stages 1–2 — which never mention
     * a site — need none at all.
     */
    protected function templateFor(Subscription $subscription, string $template): string
    {
        if ($subscription->site_id !== null) {
            return $template;
        }

        $suffix = $subscription->license()->exists() ? '_license' : '_no_site';

        // A missing key comes back from the translator as the key itself, which
        // is exactly the check: no variant defined → keep the stage's wording.
        return __("dunning.{$template}{$suffix}.subject") === "dunning.{$template}{$suffix}.subject"
            ? $template
            : $template.$suffix;
    }

    /**
     * Queue the stage notification on every configured channel the customer
     * actually has an address for.
     */
    protected function notify(Subscription $subscription, Charge $charge, int $stage, string $templateKey): void
    {
        $customer = $subscription->customer;

        foreach (config('billing.dunning.channels') as $channel) {
            $reachable = match ($channel) {
                'whatsapp' => filled($customer->whatsapp_jid) || filled($customer->phone),
                'email' => filled($customer->email),
                default => false,
            };

            if (! $reachable) {
                continue;
            }

            $event = $subscription->dunningEvents()->create([
                'charge_id' => $charge->id,
                'stage' => $stage,
                'channel' => DunningChannel::from($channel),
                'template_key' => $templateKey,
                'status' => DunningStatus::Queued,
            ]);

            SendDunningNotificationJob::dispatch($event->id);
        }
    }
}
