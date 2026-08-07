<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\SystemLog;
use App\Services\Backup\BackupDrill;
use App\Support\EmailList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The monthly "can we actually get it back" pass.
 *
 * Everything else about backups reports on the WRITE: the run finished, the
 * upload succeeded, the size looks right. None of that is the question anybody
 * has on the day it matters, which is whether the archive can be read — and
 * that question has never once been asked of a file that had been sitting in a
 * bucket for eleven months.
 *
 * So it is asked out loud, on a schedule, of the newest archive. Nothing is
 * restored (see BackupDrill); what comes back is either silence or a list of
 * reasons the archive would not have worked.
 */
class DrillBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * Whether a person asked for this one.
     *
     * The nightly automation switch turns off the automation — it does not mean
     * the archives already in the bucket stopped mattering, and somebody who
     * presses "בדוק שחזור" is asking about those. A button that reports the
     * check started and then quietly does nothing is worse than no button.
     */
    public function __construct(public bool $manual = false) {}

    /** Has enough time passed since the last drill of any outcome? */
    private function due(): bool
    {
        $days = max(1, (int) config('backup.drill_interval_days', 30));

        $last = Backup::query()->whereNotNull('drilled_at')->max('drilled_at');

        return $last === null || Carbon::parse($last)->lt(now()->subDays($days));
    }

    public function handle(BackupDrill $drill): void
    {
        if (! $this->manual && ! config('backup.enabled')) {
            return;
        }

        // The schedule asks every day; the interval is decided here. Putting it
        // in the job rather than in the cron expression is what makes a missed
        // day recoverable — the run is due until it happens, instead of being
        // due for one minute and then not again for a month.
        if (! $this->manual && ! $this->due()) {
            return;
        }

        $backup = $drill->latest();

        if (! $backup) {
            // Nothing has ever been written. On the schedule that is silence by
            // design — the backup check on /health already says so, loudly and
            // every minute, and repeating it here would be a second alarm for
            // one fact. But somebody who pressed the button was told the check
            // started, and a screen that acknowledges a no-op is how a person
            // comes to believe an archive was examined when none exists.
            if ($this->manual) {
                SystemLog::record('warning', 'backup', 'בדיקת שחזור התבקשה, אך אין גיבוי שהושלם לבדוק.');
            }

            return;
        }

        try {
            $report = $drill->run($backup);
        } catch (\Throwable $e) {
            $this->record($backup, [
                'checked_at' => now()->toIso8601String(),
                'problems' => [$e->getMessage()],
            ]);

            return;
        }

        $this->record($backup, $report);
    }

    /**
     * Write what was found, and say something only when there is something to say.
     *
     * @param  array<string, mixed>  $report
     */
    private function record(Backup $backup, array $report): void
    {
        $problems = array_values((array) ($report['problems'] ?? []));

        // Stamped whether it passed or failed: "when did anyone last open one"
        // is the question /health asks, and an archive that failed its drill was
        // still opened. What it found lives beside it.
        $backup->forceFill([
            'drilled_at' => now(),
            'drill_report' => $report,
        ])->save();

        if ($problems === []) {
            SystemLog::record('info', 'backup', 'בדיקת שחזור עברה', [
                'backup_id' => $backup->id,
                'tables' => $report['tables'] ?? null,
                'rows' => $report['rows'] ?? null,
            ]);

            return;
        }

        SystemLog::record('error', 'backup', 'בדיקת שחזור מצאה בעיות בגיבוי', [
            'backup_id' => $backup->id,
            'problems' => $problems,
        ]);

        $this->email($backup, $problems);
    }

    /**
     * Best-effort, like every other alert here: a mail that cannot go out must
     * not lose the log entry that is already written.
     *
     * @param  list<string>  $problems
     */
    private function email(Backup $backup, array $problems): void
    {
        $to = EmailList::parse(config('billing.notifications.team_email'));

        if ($to === []) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new NotificationMail(
            'בדיקת שחזור — הגיבוי האחרון לא עבר',
            'הגיבוי מ-'.($backup->finished_at?->format('d/m/Y H:i') ?? '—')." נבדק ולא עבר:\n\n• "
                .implode("\n• ", $problems)
                ."\n\nשום דבר לא שוחזר ושום דבר לא נמחק — זו בדיקה בלבד. "
                .'כדאי להריץ "גבה עכשיו" ולבדוק את היעד במסך "גיבוי ושחזור".',
        )), report: false);
    }
}
