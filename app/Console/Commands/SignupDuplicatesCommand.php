<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Find (and optionally remove) customers the signup form opened more than once.
 *
 * Until the form guarded against it, a second click on "אישור וסיום" filed the
 * whole thing again: another customer, another site in monitoring, another
 * welcome message and another "השלמת הסדר תשלום" ticket — on every click. The
 * form no longer does that, but the rows it already created are still there.
 *
 * By default this only reports. `--clean` deletes, and only a duplicate that is
 * provably still untouched: no subscription, no invoice, no payment token, no
 * extra contact, and no ticket carrying anything beyond the note signup itself
 * wrote. Anything a human has since worked on is listed and left alone —
 * merging customer histories is not something a command should decide.
 */
class SignupDuplicatesCommand extends Command
{
    protected $signature = 'signup:duplicates
        {--clean : Delete the untouched duplicates (asks first)}';

    protected $description = 'Show customers the signup form opened twice, and optionally remove the untouched copies';

    public function handle(): int
    {
        $groups = $this->groups();

        if ($groups === []) {
            $this->info('לא נמצאו הרשמות כפולות.');

            return self::SUCCESS;
        }

        $removable = [];
        $keptBack = [];

        foreach ($groups as $group) {
            /** @var Customer $keeper */
            $keeper = array_shift($group);

            $this->newLine();
            $this->info("{$keeper->name} · {$keeper->email}");
            $this->line("  נשמר: #{$keeper->id} · נפתח {$keeper->created_at?->format('d/m/Y H:i')}");

            foreach ($group as $duplicate) {
                $blockers = $this->blockers($duplicate);
                $when = $duplicate->created_at?->format('d/m/Y H:i');

                if ($blockers === []) {
                    $removable[] = $duplicate;
                    $this->line("  כפילות: #{$duplicate->id} · {$when} · ריקה — ניתן למחוק");

                    continue;
                }

                $keptBack[] = $duplicate;
                $this->warn("  כפילות: #{$duplicate->id} · {$when} · לא תימחק — ".implode(', ', $blockers));
            }
        }

        $this->newLine();
        $this->info(sprintf('סה״כ %d כפילויות ריקות, %d כפילויות שכבר טופלו.', count($removable), count($keptBack)));

        if (! $this->option('clean')) {
            $this->line('להסרה: php artisan signup:duplicates --clean');

            return self::SUCCESS;
        }

        if ($removable === []) {
            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('למחוק %d לקוחות כפולים (כולל האתרים והפניות שנפתחו איתם)?', count($removable)))) {
            $this->line('בוטל — לא נמחק דבר.');

            return self::SUCCESS;
        }

        foreach ($removable as $duplicate) {
            DB::transaction(function () use ($duplicate): void {
                // Tickets are detached from a deleted customer rather than
                // removed with it, so the signup follow-ups have to go
                // explicitly — otherwise the queue keeps six copies of a
                // conversation that never had a second side.
                $duplicate->tickets()->each(fn (Ticket $ticket) => $ticket->delete());

                // Sites, subscriptions, tokens and contacts cascade.
                $duplicate->delete();
            });
        }

        $this->info(sprintf('נמחקו %d לקוחות כפולים.', count($removable)));

        return self::SUCCESS;
    }

    /**
     * Customers that look like the same form filed more than once, grouped and
     * ordered oldest first — the first one is the real signup.
     *
     * Grouped on every identifying field the form collects, so two genuinely
     * different people are never grouped together, and only counted as a repeat
     * when the copies landed within the same window the form itself now guards.
     *
     * @return list<list<Customer>>
     */
    private function groups(): array
    {
        $window = max(1, (int) config('billing.signup.duplicate_window_minutes', 30));
        $groups = [];

        Customer::query()->orderBy('id')->chunk(500, function ($chunk) use (&$groups): void {
            foreach ($chunk as $customer) {
                $key = implode('|', [
                    mb_strtolower((string) $customer->email),
                    (string) $customer->name,
                    (string) $customer->contact_name,
                    (string) $customer->phone,
                    (string) ($customer->business_type?->value ?? ''),
                    (string) $customer->payment_method,
                    (string) $customer->business_number,
                ]);

                $groups[$key][] = $customer;
            }
        });

        $out = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $first = $group[0];
            $repeats = array_values(array_filter(
                array_slice($group, 1),
                fn (Customer $c): bool => $first->created_at !== null
                    && $c->created_at !== null
                    && abs($c->created_at->diffInMinutes($first->created_at)) <= $window,
            ));

            if ($repeats !== []) {
                $out[] = [$first, ...$repeats];
            }
        }

        return $out;
    }

    /**
     * Why this duplicate must not be deleted — empty when nothing has attached
     * itself to it since.
     *
     * @return list<string>
     */
    private function blockers(Customer $customer): array
    {
        $blockers = [];

        foreach ([
            'מנוי' => $customer->subscriptions(),
            'חשבונית' => $customer->invoices(),
            'אמצעי תשלום' => $customer->paymentTokens(),
            'איש קשר' => $customer->contacts(),
        ] as $label => $relation) {
            if ($relation->exists()) {
                $blockers[] = $label;
            }
        }

        // A ticket is only disposable while it still holds nothing but the note
        // signup wrote itself. One reply — from us or from the customer — and
        // this is a conversation, not a stray row.
        $ownNote = 'signup-payment-'.$customer->id;

        $worked = $customer->tickets()
            ->whereHas('messages', fn ($q) => $q->where('external_message_id', '!=', $ownNote)
                ->orWhereNull('external_message_id'))
            ->exists();

        if ($worked) {
            $blockers[] = 'פנייה עם התכתבות';
        }

        return $blockers;
    }
}
