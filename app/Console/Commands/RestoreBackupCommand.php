<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\RestoreClaim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Restore from an archive here and now, without the queue.
 *
 * The deployment guide tells the operator to stop the queue worker before a
 * restore — a charge already on its way to Cardcom cannot be recalled when the
 * row recording it is replaced a second later. But the screen's button hands
 * the work to a worker, and with the worker stopped there is nobody to do it:
 * the panel would say the restore started and nothing would happen.
 *
 * So the documented procedure runs it from here instead. It is also the way in
 * when the queue itself is part of what broke.
 *
 *   php artisan backup:restore 42
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore {id : מזהה הגיבוי לשחזור} {--force : לדלג על שאלת האישור}';

    protected $description = 'שחזור מגיבוי בחזית, בלי תלות בעובד התור (הדרך המומלצת: לעצור את ה-worker ואז להריץ את זה)';

    public function handle(BackupRestorer $restorer, RestoreClaim $claims): int
    {
        $backup = Backup::find((int) $this->argument('id'));

        if ($backup === null) {
            $this->error('לא נמצא גיבוי עם המזהה הזה.');

            return self::FAILURE;
        }

        // Adopting an unstarted claim is allowed here: the operator is standing
        // in front of the server precisely because the worker is stopped.
        if (($reason = $restorer->blockedReason($backup, adoptUnstarted: true)) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        $this->warn("שחזור מגיבוי #{$backup->id} ({$backup->path}) — כל הנתונים הנוכחיים יימחקו ויוחלפו.");

        // The queue is not the only thing that spends money. A request being
        // served right now can be inside Cardcom or Linet — it passed the gate
        // a moment before this claim existed — and its write would either be
        // deleted by the restore or land against restored data. Maintenance
        // mode is what stops new ones arriving; the wait for the ones already
        // in flight is the operator's, and the guide says so.
        if (! $this->laravel->isDownForMaintenance()) {
            $this->warn('האפליקציה אינה במצב תחזוקה. בקשה שנמצאת כרגע באמצע קריאה לקארדקום או ללינט תסתיים אחרי השחזור, והשורה שרושמת אותה תימחק או תיכתב על נתונים משוחזרים.');

            if (! $this->option('force')
                && ! $this->confirm('האפליקציה אינה במצב תחזוקה — להמשיך בכל זאת?', false)) {
                $this->line('בוטל. הריצו php artisan down, המתינו לסיום הבקשות שרצות, ונסו שוב.');

                return self::FAILURE;
            }
        }

        // "horizon:terminate" asks for a shutdown and returns; a job that is
        // already inside a call to Cardcom or Linet keeps going. Anything still
        // in the queue means a worker may still be alive, and the money it is
        // spending right now cannot be recalled once its row is replaced.
        $pending = rescue(fn (): int => (int) Queue::size(), 0, report: false);

        if ($pending > 0) {
            $this->warn("יש עוד {$pending} משימות בתור — ודאו שעובד התור באמת נעצר (horizon:status מדווח inactive) לפני שממשיכים.");

            if (! $this->option('force') && ! $this->confirm('להמשיך בכל זאת?', false)) {
                $this->line('בוטל.');

                return self::FAILURE;
            }
        }

        $word = (string) config('backup.restore_confirmation');

        if (! $this->option('force') && trim((string) $this->ask("להמשך הקלידו: {$word}")) !== $word) {
            $this->line('בוטל.');

            return self::FAILURE;
        }

        // Adopted even if the screen already claimed it and the job never ran —
        // that is the whole case this command exists for. The attempt id
        // changes with the claim, so a payload delivered late finds itself
        // superseded and stops.
        $attempt = $claims->take($backup, takeOverUnstarted: true);

        if ($attempt === null || ! $claims->markStarted($backup, $attempt)) {
            $this->error('שחזור מהגיבוי הזה כבר רץ — לא בוצע דבר.');

            return self::FAILURE;
        }

        // The same lock a backup takes: one operation on the data at a time,
        // whichever direction it runs in.
        $lock = Cache::lock(RunBackupJob::LOCK, 3600);

        if (! $lock->get()) {
            $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => 'פעולת גיבוי או שחזור אחרת רצה באותו רגע — השחזור לא בוצע.',
            ]);

            $this->error('פעולת גיבוי או שחזור אחרת רצה כרגע. נסו שוב בעוד כמה דקות.');

            return self::FAILURE;
        }

        try {
            $restorer->restore($backup->refresh());
        } catch (\Throwable $e) {
            // Already recorded on the row by the restorer, which also knows
            // whether the replacement committed. Here it only has to be said
            // out loud to whoever is watching the console.
            $this->error('השחזור נכשל: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $backup->refresh();

        if ($backup->restore_error !== null) {
            $this->warn($backup->restore_error);
        }

        $report = (array) ($backup->restore_report ?? []);

        if ($report !== []) {
            $this->warn('יש רשימת התאמות לבדיקה מול קארדקום ולינט — ראו את מסך "גיבוי ושחזור".');
        }

        $this->info('השחזור הושלם.');

        return self::SUCCESS;
    }
}
