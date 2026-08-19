<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
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
                $blockers = $this->blockers($duplicate, $keeper);
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
            foreach ($this->clusters($group, $window) as $cluster) {
                if (count($cluster) > 1) {
                    $out[] = $cluster;
                }
            }
        }

        return $out;
    }

    /**
     * Split one identity's rows into bursts — rows that landed within the window
     * OF EACH OTHER, not of the oldest row ever.
     *
     * A customer who signed up properly a year ago and then double-clicked the
     * form today produces two rows today, minutes apart and months away from the
     * original. Measuring everything against the earliest record would put both
     * of today's rows outside the window and report nothing — missing the only
     * duplicate actually there.
     *
     * @param  list<Customer>  $group  ordered oldest first
     * @return list<list<Customer>>
     */
    private function clusters(array $group, int $window): array
    {
        $clusters = [];
        $current = [];

        foreach ($group as $customer) {
            $anchor = $current[0] ?? null;

            $joins = $anchor !== null
                && $anchor->created_at !== null
                && $customer->created_at !== null
                && abs($customer->created_at->diffInMinutes($anchor->created_at)) <= $window;

            if ($joins) {
                $current[] = $customer;

                continue;
            }

            if ($current !== []) {
                $clusters[] = $current;
            }

            $current = [$customer];
        }

        if ($current !== []) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    /**
     * Every column signup itself writes — plus the ones its own jobs fill in
     * moments later.
     *
     * The check below is deliberately the other way round: anything NOT on this
     * list that carries a value was put there by a person. Listing what signup
     * writes is a closed set that this file can be sure of; listing what a
     * person might edit is not, and a column added next month would quietly
     * stop being protected.
     */
    private const SIGNUP_WRITTEN = [
        'id', 'name', 'contact_name', 'business_number', 'business_type', 'vat_exempt',
        'email', 'phone', 'payment_method', 'terms_accepted_at', 'signature_path',
        'signed_ip', 'signed_pdf_path', 'status', 'card_link_token',
        'pending_card_lp_id', 'created_at', 'updated_at',
    ];

    /**
     * Why this duplicate must not be deleted — empty when nothing has attached
     * itself to it since.
     *
     * @return list<string>
     */
    private function blockers(Customer $customer, Customer $keeper): array
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

        // A site the original does not have is not a duplicate of anything —
        // it is a second domain this customer asked us to watch, and deleting
        // the row would cascade it out of monitoring without a trace.
        $extraSites = $customer->sites()->pluck('domain')
            ->diff($keeper->sites()->pluck('domain'))
            ->values();

        if ($extraSites->isNotEmpty()) {
            $blockers[] = 'אתר שאינו קיים במקורי: '.$extraSites->implode(', ');
        }

        // Anything a person typed onto the customer card itself — notes, a
        // changed status, an onboarding checklist, an address. None of it is
        // written by signup, so a value here means somebody worked on this row.
        $edited = collect($customer->getAttributes())
            ->except(self::SIGNUP_WRITTEN)
            ->reject(fn ($value): bool => blank($value) || in_array($value, ['[]', '{}'], true))
            ->keys();

        if ($edited->isNotEmpty()) {
            $blockers[] = 'שדות שנערכו: '.$edited->implode(', ');
        }

        if ($customer->status !== CustomerStatus::Active) {
            $blockers[] = 'סטטוס שונה';
        }

        return $blockers;
    }
}
