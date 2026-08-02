<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\SystemLog;
use App\Services\Backup\BackupDrill;
use App\Support\EmailList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(BackupDrill $drill): void
    {
        if (! config('backup.enabled')) {
            return;
        }

        $backup = $drill->latest();

        if (! $backup) {
            // Nothing has ever been written. The backup check on /health
            // already says so, loudly and every minute; repeating it here would
            // be a second alarm for one fact.
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
