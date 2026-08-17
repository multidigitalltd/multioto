<?php

namespace App\Services\Agent;

use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;
use Illuminate\Support\Str;

/**
 * Many tool calls on one site, under ONE approval — and one undo.
 *
 * A sale is not twenty decisions. "תוריד 20% על כל החולצות עד סוף החודש" is a
 * single business decision that happens to touch twenty products, and asking
 * the owner to approve it twenty times turns a considered approval into a
 * reflex — which is exactly how the twenty-first proposal gets waved through
 * without being read.
 *
 * The same is true going back. Reverting a sale one product at a time leaves a
 * shop half on sale for as long as it takes, and leaves whoever is clicking to
 * remember where they stopped. So the batch is the unit in both directions.
 *
 * Grouping needs no new storage: every change journalled here carries the
 * batch's own `pending_action_id`, so "the changes this approval made" is
 * already a query.
 *
 * payload shape: { site_id, calls: [{ tool, arguments?, label?, reverts_change_id? }] }
 */
class SiteActionBatchRunner
{
    /** Calls one approval may cover. */
    private const MAX_CALLS = 100;

    public function __construct(private SiteActionRunner $runner) {}

    /** Run every call in order. Returns a per-item report. */
    public function run(PendingAction $action): string
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $calls = (array) data_get($action->payload, 'calls', []);

        if (! $site || $calls === []) {
            throw new \RuntimeException('האתר או רשימת הפעולות חסרים בהצעה.');
        }

        if (count($calls) > self::MAX_CALLS) {
            throw new \RuntimeException(
                'אצווה אחת מוגבלת ל-'.self::MAX_CALLS.' פעולות; התקבלו '.count($calls).'.'
            );
        }

        $done = [];

        foreach (array_values($calls) as $index => $call) {
            $tool = (string) data_get($call, 'tool');

            if ($tool === '') {
                throw new \RuntimeException('פעולה מספר '.($index + 1).' באצווה חסרת כלי.');
            }

            $label = trim((string) data_get($call, 'label')) ?: $tool;

            try {
                $this->runner->execute(
                    $action,
                    $site,
                    $tool,
                    (array) data_get($call, 'arguments', []),
                    Str::limit($label, 250),
                    ($revertsId = data_get($call, 'reverts_change_id')) !== null ? (int) $revertsId : null,
                );
            } catch (\Throwable $e) {
                /*
                 * Stop here, and say exactly where.
                 *
                 * Carrying on past a failure would spread an unknown fault
                 * across the rest of the shop before anybody has looked at the
                 * first one. Everything applied so far is already journalled
                 * with its own way back, so the owner can undo what happened;
                 * what they must not be given is a half-applied sale reported
                 * as a success.
                 */
                throw new \RuntimeException(
                    'האצווה נעצרה על "'.$label.'" (פעולה '.($index + 1).' מתוך '.count($calls).'): '
                    .Str::limit($e->getMessage(), 300)
                    .($done === [] ? ' לא בוצע שום שינוי.' : ' בוצעו '.count($done).' פעולות לפניה, וניתן לשחזר אותן ביומן השינויים.'),
                    0,
                    $e,
                );
            }

            $done[] = $label;
        }

        return count($done).' פעולות בוצעו על '.$site->domain.":\n· ".implode("\n· ", $done);
    }

    /**
     * Turn an applied batch into the proposal that undoes it.
     *
     * In reverse order, because the calls were not necessarily independent —
     * undoing them in the order they were made can re-apply an earlier state on
     * top of a later one.
     *
     * Changes with no recorded way back are skipped rather than guessed at, and
     * reported, so "restore everything" never quietly means "restore most of
     * it". Already-reverted rows are skipped too: undoing an undo would put the
     * change back.
     *
     * @return array{calls: list<array<string, mixed>>, skipped: int}
     */
    public function revertPlan(PendingAction $applied): array
    {
        $changes = SiteChange::query()
            ->where('pending_action_id', $applied->id)
            ->whereNull('reverted_at')
            ->orderByDesc('id')
            ->get();

        $calls = [];
        $skipped = 0;

        foreach ($changes as $change) {
            if (blank($change->revert_tool)) {
                $skipped++;

                continue;
            }

            $calls[] = [
                'tool' => $change->revert_tool,
                'arguments' => $change->revert_arguments ?? [],
                'label' => '↩ '.$change->summary,
                'reverts_change_id' => $change->id,
            ];
        }

        return ['calls' => $calls, 'skipped' => $skipped];
    }
}
