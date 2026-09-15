<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\SystemLog;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Chase the security card that signup asked for and never got.
 *
 * The card page is the last step of the public signup and the customer record
 * is already committed by the time it opens — so a customer who closes the tab
 * leaves behind a record that looks exactly like a completed one. Nothing else
 * notices: RequestMissingCardJob runs off subscriptions whose charge date has
 * passed, and the customers this is about have no subscription for weeks and,
 * when they finally do, a manually-collected one that scope deliberately skips.
 *
 * Which makes this the job that gives the security-card arrangement something
 * behind it. A card that was never captured is a fallback that cannot fall
 * back: the transfer does not arrive, the grace period runs out, and the
 * collection that was promised quietly does nothing.
 *
 * This is a SERVICE message, not marketing — an unfinished signup step, like an
 * invoice or a dunning notice. It goes out regardless of a marketing opt-out.
 */
class ChaseMissingSecurityCardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(CardCaptureLinkSender $sender, TeamNotifier $notifier): void
    {
        $maxRequests = (int) config('billing.cards.security_missing.max_requests', 3);

        if ($maxRequests === 0) {
            return; // Switched off entirely.
        }

        $intervalDays = max(1, (int) config('billing.cards.security_missing.interval_days', 4));
        // A customer who signed up an hour ago may still have the card page
        // open. Chasing them on the same afternoon they signed up reads as a
        // system that is not paying attention to what it just did.
        $graceHours = max(1, (int) config('billing.cards.security_missing.grace_hours', 24));

        $customers = Customer::query()
            ->missingSecurityCard()
            ->where('security_card_terms_at', '<=', now()->subHours($graceHours))
            ->orderBy('security_card_terms_at')
            ->get();

        $asked = 0;

        foreach ($customers as $customer) {
            $requests = $this->requestsSince($customer);

            if ($requests['count'] >= $maxRequests) {
                // Past the cap this stops being something to automate. The
                // customer is on the "אין כרטיס ביטחון" list either way, so
                // nothing is lost — but the team is told once, because a
                // customer who ignored every request is a decision, not a
                // queue item.
                $this->handOver($customer, $notifier, $requests['count']);

                continue;
            }

            if ($requests['last'] !== null && $requests['last']->gt(now()->subDays($intervalDays))) {
                continue; // Asked recently — give them time to act.
            }

            try {
                $result = $sender->sendToCustomer($customer, 'card.security_missing', [
                    'method_label' => PaymentMethod::tryFrom((string) $customer->payment_method)?->getLabel() ?? 'אמצעי התשלום שנבחר',
                ]);
            } catch (\Throwable $e) {
                Log::warning('ChaseMissingSecurityCardJob: send failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['sent'] !== []) {
                $asked++;
            }
        }

        if ($asked > 0) {
            SystemLog::record('info', 'billing', "נשלחו {$asked} בקשות להשלמת כרטיס ביטחון ללקוחות שלא הזינו כרטיס בהרשמה");
        }
    }

    /**
     * Tell the team, once per customer, that asking has stopped helping.
     *
     * Keyed on the consent timestamp so a customer who signs a NEW arrangement
     * later can be handed over again — and so a redeploy or a re-run never
     * repeats the same alert.
     */
    private function handOver(Customer $customer, TeamNotifier $notifier, int $requests): void
    {
        $key = 'security-card-handover:'.$customer->id.':'.$customer->security_card_terms_at?->timestamp;

        if (! Cache::add($key, true, now()->addDays(60))) {
            return;
        }

        $method = PaymentMethod::tryFrom((string) $customer->payment_method)?->getLabel() ?? (string) $customer->payment_method;

        try {
            $notifier->alert(
                "💳 אין כרטיס ביטחון — {$customer->name}",
                implode("\n", [
                    "לקוח: {$customer->name} (#{$customer->id})",
                    "אמצעי תשלום: {$method}",
                    "נשלחו {$requests} בקשות להזנת כרטיס ביטחון ולא הוזן כרטיס.",
                    'אם תשלום לא יגיע במועד — אין ממה לגבות. יש ליצור קשר או להחליט להפסיק את השירות.',
                ]),
                rtrim((string) config('app.url'), '/').'/admin/customers/'.$customer->id,
            );
        } catch (\Throwable $e) {
            Log::warning('ChaseMissingSecurityCardJob: handover alert failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How many times this customer has been sent a card link since they agreed
     * to the arrangement, and when the last one went out.
     *
     * Counted from the outbound log because that is what actually reached them.
     * Every card link counts, not only this job's: a customer who got one from
     * a dunning message last week was already asked, and asking again tomorrow
     * because the sender was different is the machine losing count of who it is
     * talking to.
     *
     * Counted in ROUNDS, not rows. One request goes out over WhatsApp and email
     * both, so counting rows would make the cap mean three messages for a
     * customer we can only email and one and a half for everyone else — a limit
     * that quietly depends on which contact details we happen to hold.
     *
     * @return array{count: int, last: Carbon|null}
     */
    private function requestsSince(Customer $customer): array
    {
        $sentAt = NotificationLog::query()
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::CardLink)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $customer->security_card_terms_at)
            ->orderByDesc('sent_at')
            ->pluck('sent_at');

        return [
            // Grouped in PHP rather than with a date function in SQL: the same
            // count has to come out on SQLite and Postgres alike.
            'count' => $sentAt->map(fn (Carbon $at): string => $at->toDateString())->unique()->count(),
            'last' => $sentAt->first(),
        ];
    }
}
