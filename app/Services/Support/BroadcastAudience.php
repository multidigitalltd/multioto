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
    public function reachable(BroadcastChannel $channel, ?array $segment): Builder
    {
        $query = $this->query($segment);

        return $channel === BroadcastChannel::Email
            ? $query->where(fn ($q) => $this->filled($q, 'email'))
            : $query->where(fn ($q) => $this->filled($q, 'whatsapp_jid')
                ->orWhere(fn ($p) => $this->filled($p, 'phone')));
    }

    /**
     * Recipient counts for the panel: how many the segment selects, how many are
     * reachable on the chosen channel, and how many will therefore be skipped.
     *
     * @return array{total: int, reachable: int, unreachable: int}
     */
    public function summary(BroadcastChannel $channel, ?array $segment): array
    {
        $total = $this->query($segment)->count();
        $reachable = $this->reachable($channel, $segment)->count();

        return [
            'total' => $total,
            'reachable' => $reachable,
            'unreachable' => max(0, $total - $reachable),
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
