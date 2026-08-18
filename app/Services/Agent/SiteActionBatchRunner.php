<?php

namespace App\Services\Agent;

use App\Jobs\ContinueSiteActionBatchJob;
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
    private const MAX_CALLS = 500;

    /**
     * Calls carried out per run.
     *
     * An approval is clicked in a browser request, and four hundred shop calls
     * do not fit in one. So a run does a slice and asks for the next, until
     * there is none — the batch is never cut short, it is only spread out.
     */
    private const CHUNK = 20;

    public function __construct(
        private SiteActionRunner $runner,
        private SiteToolCatalog $catalog,
    ) {}

    /**
     * Carry out the next slice, and queue the one after it.
     *
     * Returns a report of what this run did and whether more is coming.
     */
    public function run(PendingAction $action): string
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $calls = array_values((array) data_get($action->payload, 'calls', []));

        if (! $site || $calls === []) {
            throw new \RuntimeException('האתר או רשימת הפעולות חסרים בהצעה.');
        }

        if (count($calls) > self::MAX_CALLS) {
            throw new \RuntimeException(
                'אצווה אחת מוגבלת ל-'.self::MAX_CALLS.' פעולות; התקבלו '.count($calls).'.'
            );
        }

        $this->assertEveryCallIsAChange($site, $calls);

        /*
         * Where to resume, read from the journal rather than carried along.
         *
         * Each call writes exactly one row as it lands, so the number of rows
         * IS the number of calls already carried out — including on a run that
         * died half-way. Nothing to keep in sync, and no state that can
         * disagree with what actually happened to the shop.
         */
        $completed = SiteChange::where('pending_action_id', $action->id)->count();
        $slice = array_slice($calls, $completed, self::CHUNK, preserve_keys: true);

        if ($slice === []) {
            return 'כל '.count($calls).' הפעולות כבר בוצעו.';
        }

        $done = [];

        foreach ($slice as $index => $call) {
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
                    .($completed + count($done) === 0
                        ? ' לא בוצע שום שינוי.'
                        : ' בוצעו '.($completed + count($done)).' פעולות לפניה, וניתן לשחזר אותן ביומן השינויים.'),
                    0,
                    $e,
                );
            }

            $done[] = $label;
        }

        $completed += count($done);
        $remaining = count($calls) - $completed;

        // More to do: ask for the next slice and say so. An owner who was told
        // "20 done" about a sale of 213 would go and look at the shop, find most
        // of it unchanged, and conclude the whole thing had failed.
        if ($remaining > 0) {
            ContinueSiteActionBatchJob::dispatch($action->id);

            return "בוצעו {$completed} מתוך ".count($calls).' פעולות על '.$site->domain
                .". הביצוע ממשיך ברקע — נותרו {$remaining}.";
        }

        return "כל {$completed} הפעולות בוצעו על ".$site->domain
            .' ('.count($done)." בהרצה הזו):\n· ".implode("\n· ", $done);
    }

    /**
     * Every call in a batch must be a change.
     *
     * Not a style rule — the resume depends on it. Progress is counted from the
     * journal, and only a state-changing tool writes a journal row. A read
     * mixed into the batch would leave the count standing still, the same slice
     * would be handed out again, and the batch would run in a circle applying
     * the same changes for as long as the queue allowed.
     *
     * @param  list<array<string, mixed>>  $calls
     */
    private function assertEveryCallIsAChange(Site $site, array $calls): void
    {
        foreach ($calls as $index => $call) {
            $tool = (string) data_get($call, 'tool');

            if ($tool !== '' && $this->catalog->resolveTier($site, $tool) < 1) {
                throw new \RuntimeException(
                    'פעולה '.($index + 1)." באצווה היא כלי קריאה ({$tool}). "
                    .'אצווה מורכבת משינויים בלבד — כלי קריאה אינו נרשם ביומן, ולכן ההתקדמות לא הייתה מתקדמת.'
                );
            }
        }
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
