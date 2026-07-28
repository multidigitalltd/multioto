<?php

namespace App\Services\Support;

use App\Enums\BroadcastChannel;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who receives a broadcast.
 *
 * Single source of truth for the segment definition, shared by the send job and
 * by the panel's "יישלח ל-X לקוחות" counter — so the number the operator
 * approves before pressing send is the exact set the job will write to, not an
 * estimate built by separate code that can drift.
 *
 * Segment shape (stored on broadcasts.segment):
 *   status       — a CustomerStatus value, or 'all' for every status (default: active)
 *   plan_ids     — only customers subscribed to one of these plans (empty: any)
 *   customer_ids — only these customers (empty: all who match the above)
 */
class BroadcastAudience
{
    /** The only columns filled() will interpolate into SQL. */
    private const ADDRESS_COLUMNS = ['email', 'phone', 'whatsapp_jid'];

    /** Every customer the segment selects, regardless of how we can reach them. */
    public function query(?array $segment): Builder
    {
        $segment ??= [];
        $status = (string) ($segment['status'] ?? CustomerStatus::Active->value);

        return Customer::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($segment['customer_ids'] ?? null, fn ($q, $ids) => $q->whereKey($ids))
            ->when($segment['plan_ids'] ?? null, fn ($q, $planIds) => $q->whereHas(
                'subscriptions', fn ($sq) => $sq->whereIn('plan_id', $planIds),
            ))
            ->orderBy('id');
    }

    /**
     * The segment narrowed to customers we can actually reach on this channel.
     * A customer with no email is not a failed email — they were never a
     * recipient, and counting them would make every send look partly broken.
     */
    public function reachable(BroadcastChannel $channel, ?array $segment, bool $marketing = false): Builder
    {
        $query = $this->query($segment);

        // An opt-out silences advertising only. A service announcement — planned
        // maintenance, a security notice — is not advertising, and withholding it
        // would leave the customer uninformed about their own site.
        if ($marketing) {
            $query->whereNull('marketing_opt_out_at');
        }

        // An address the provider told us is dead stays out of every send.
        // Retrying it is not just wasted — repeated bounces are what mailbox
        // providers score a sender on, so it degrades delivery for everyone else.
        return $channel === BroadcastChannel::Email
            ? $query->whereNull('email_bounced_at')->where(fn ($q) => $this->filled($q, 'email'))
            : $query->where(fn ($q) => $this->filled($q, 'whatsapp_jid')
                ->orWhere(fn ($p) => $this->filled($p, 'phone')));
    }

    /**
     * Recipient counts for the panel: how many the segment selects, how many are
     * reachable on the chosen channel, and how many will therefore be skipped.
     *
     * `opted_out` and `bounced` are split out from `unreachable` so the panel can
     * say why each customer is skipped: a missing address is something the
     * operator can fix by typing one in, a dead address needs a different one
     * from the customer, and an opt-out is not to be worked around at all.
     *
     * The buckets do not overlap — a customer who both opted out and bounced is
     * counted once, under the reason that comes first.
     *
     * @return array{total: int, reachable: int, unreachable: int, opted_out: int, bounced: int}
     */
    public function summary(BroadcastChannel $channel, ?array $segment, bool $marketing = false): array
    {
        $total = $this->query($segment)->count();
        $reachable = $this->reachable($channel, $segment, $marketing)->count();

        $optedOut = $marketing
            ? $this->query($segment)->whereNotNull('marketing_opt_out_at')->count()
            : 0;

        $bounced = $channel === BroadcastChannel::Email
            ? $this->query($segment)
                ->when($marketing, fn ($q) => $q->whereNull('marketing_opt_out_at'))
                ->whereNotNull('email_bounced_at')
                ->count()
            : 0;

        return [
            'total' => $total,
            'reachable' => $reachable,
            'unreachable' => max(0, $total - $reachable - $optedOut - $bounced),
            'opted_out' => $optedOut,
            'bounced' => $bounced,
        ];
    }

    /**
     * A column holding a real address: present, and not blank once trimmed.
     *
     * The trim matters — the send job trims before dispatching and skips what
     * is left empty, so a plain `!= ''` here would count a whitespace-only
     * value as a recipient. The count would then overstate the audience, and a
     * segment of nothing but such values would pass the "someone will receive
     * this" guard and be marked sent with zero deliveries.
     *
     * @param  'email'|'phone'|'whatsapp_jid'  $column
     */
    private function filled(Builder $query, string $column): Builder
    {
        // Whitelisted above; never interpolate anything caller-supplied here.
        if (! in_array($column, self::ADDRESS_COLUMNS, true)) {
            throw new \InvalidArgumentException("עמודה לא נתמכת לבדיקת כתובת: {$column}");
        }

        return $query->whereNotNull($column)->whereRaw("TRIM({$column}) <> ''");
    }
}
