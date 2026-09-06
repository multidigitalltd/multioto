<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Which card a customer is actually charged on — and what disagrees with it.
 *
 * "The customer entered a new card and it still fails" has several possible
 * causes that look identical from the panel: the card was never saved at all,
 * it was saved but the customer's default still points at the old one, the
 * default is right but a subscription still holds the old token, or every
 * pointer is right and the card itself is simply expired. Guessing between them
 * costs a round trip through the customer each time.
 *
 * So this prints all four in one screen and names the mismatch. Read-only: the
 * repair is "הפוך לפעיל" on the customer's כרטיסי אשראי tab, which does the
 * wiring in one place for the panel and the capture flow alike.
 */
class CustomerCardsCommand extends Command
{
    protected $signature = 'cardcom:cards
        {customer? : Customer id, name fragment, or email — omit to list every customer with a problem}';

    protected $description = 'Show a customer\'s saved cards, which one is charged, and any mismatch';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('customer'));

        if ($needle === '') {
            return $this->reportProblems();
        }

        $customers = $this->find($needle);

        if ($customers->isEmpty()) {
            $this->error("לא נמצא לקוח שמתאים ל־\"{$needle}\".");

            return self::FAILURE;
        }

        foreach ($customers as $customer) {
            $this->report($customer);
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Customer> */
    private function find(string $needle)
    {
        return Customer::query()
            ->with(['paymentTokens', 'subscriptions', 'defaultToken'])
            ->when(ctype_digit($needle), fn ($q) => $q->whereKey((int) $needle))
            ->when(! ctype_digit($needle), fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('name', 'like', "%{$needle}%")
                    ->orWhere('email', 'like', "%{$needle}%")))
            ->limit(10)
            ->get();
    }

    private function report(Customer $customer): void
    {
        $this->newLine();
        $this->info("לקוח #{$customer->id} — {$customer->name}");

        $default = $customer->defaultToken;

        $this->line('כרטיס לחיוב: '.($default?->label() ?? '⚠️  אין (default_token_id ריק)'));

        if ($default?->hasExpired()) {
            $this->warn('  ⚠️  הכרטיס לחיוב פג תוקף — כל חיוב עליו יידחה.');
        }

        if ($customer->pending_card_lp_id) {
            $this->warn("  ⚠️  יש בקשת כרטיס פתוחה (LowProfileId {$customer->pending_card_lp_id}) שלא סונכרנה.");
            $this->line('     ייתכן שהלקוח הזין כרטיס וההתראה מקארדקום אבדה: "בדיקת כרטיס בקארדקום" בכרטיס הלקוח, או php artisan cardcom:card-failures --fetch');
        }

        $tokens = $customer->paymentTokens->sortByDesc('id');

        if ($tokens->isEmpty()) {
            $this->line('אין כרטיסים שמורים.');
        } else {
            $this->table(
                ['#', 'כרטיס', 'תוקף', 'סטטוס', 'לחיוב', 'נשמר'],
                $tokens->map(fn (PaymentToken $t): array => [
                    $t->id,
                    trim(($t->card_brand ?? '').' '.($t->card_last4 ? '****'.$t->card_last4 : '')) ?: '—',
                    ($t->expiryLabel() ?? '—').($t->hasExpired() ? ' ⚠️ פג' : ''),
                    $t->status->getLabel(),
                    (int) $customer->default_token_id === (int) $t->id ? '✅' : '',
                    $t->created_at?->format('d/m/Y H:i') ?? '—',
                ])->all(),
            );

            $activeCount = $tokens->where('status', TokenStatus::Active)->count();

            if ($activeCount > 1) {
                $this->warn("  ⚠️  {$activeCount} כרטיסים מסומנים 'פעיל'. רק אחד אמור להיות — סימן שכרטיס נשמר בלי לעבור דרך CardTokenService.");
            }
        }

        $subscriptions = $customer->subscriptions
            ->where('status', '!=', SubscriptionStatus::Canceled)
            ->sortBy('id');

        if ($subscriptions->isEmpty()) {
            $this->line('אין מנויים פעילים.');

            return;
        }

        $this->table(
            ['מנוי', 'סטטוס', 'כרטיס המנוי', 'חיוב הבא', 'דאנינג', 'הערה'],
            $subscriptions->map(fn (Subscription $s): array => [
                $s->id,
                $s->status->getLabel(),
                $s->token_id ?? '—',
                $s->next_charge_at?->format('d/m/Y H:i') ?? '—',
                $s->dunning_stage,
                $this->subscriptionNote($customer, $s),
            ])->all(),
        );
    }

    /** The one sentence that explains why this subscription is not collecting. */
    private function subscriptionNote(Customer $customer, Subscription $subscription): string
    {
        if ($subscription->token_id === null) {
            return '⚠️ אין כרטיס — לא ייגבה';
        }

        if ((int) $subscription->token_id !== (int) $customer->default_token_id) {
            // The mismatch this command exists for: a fresh card on file while
            // the subscription still charges the one it replaced.
            return '⚠️ מצביע על כרטיס אחר מהכרטיס לחיוב';
        }

        $token = $customer->paymentTokens->firstWhere('id', $subscription->token_id);

        if ($token === null) {
            return '⚠️ כרטיס לא נמצא';
        }

        if ($token->status !== TokenStatus::Active) {
            return '⚠️ הכרטיס של המנוי אינו פעיל ('.$token->status->getLabel().')';
        }

        return $token->hasExpired() ? '⚠️ הכרטיס פג תוקף' : '✓';
    }

    /** Every customer whose card wiring disagrees with itself. */
    private function reportProblems(): int
    {
        $suspects = Customer::query()
            ->with(['paymentTokens', 'subscriptions', 'defaultToken'])
            ->whereHas('paymentTokens')
            ->get()
            ->filter(fn (Customer $c): bool => $this->hasMismatch($c));

        if ($suspects->isEmpty()) {
            $this->info('כל הלקוחות עם כרטיס שמור מחווטים נכון.');

            return self::SUCCESS;
        }

        $this->warn("נמצאו {$suspects->count()} לקוחות עם אי-התאמה בכרטיסים:");

        foreach ($suspects as $customer) {
            $this->report($customer);
        }

        return self::SUCCESS;
    }

    private function hasMismatch(Customer $customer): bool
    {
        if ($customer->paymentTokens->where('status', TokenStatus::Active)->count() > 1) {
            return true;
        }

        if ($customer->defaultToken?->hasExpired()) {
            return true;
        }

        return $customer->subscriptions
            ->where('status', '!=', SubscriptionStatus::Canceled)
            ->contains(fn (Subscription $s): bool => $s->token_id !== null
                && (int) $s->token_id !== (int) $customer->default_token_id);
    }
}
