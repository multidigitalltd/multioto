<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\SystemLog;
use App\Support\EmailList;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one backup end to end: build the archive locally, push it to the
 * external disk, record what it holds, then prune what is no longer needed.
 *
 * The row is written BEFORE the work starts and marked failed on the way out,
 * because the failure that hurts is the one nobody sees — a backup screen
 * showing nothing looks identical whether the job never ran or never worked.
 */
class BackupRunner
{
    public function __construct(private BackupArchive $archive) {}

    /** @param  int|null  $userId  who pressed the button; null for the nightly run */
    public function run(?int $userId = null): Backup
    {
        $disk = (string) config('backup.disk');
        $path = $this->pathFor();

        $backup = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => $disk,
            'path' => $path,
            'user_id' => $userId,
        ]);

        $local = tempnam(sys_get_temp_dir(), 'multioto-backup-');

        try {
            // Inside the recorded lifecycle on purpose. Thrown before the row
            // exists, a misconfigured destination would make the nightly run
            // vanish with no failed row and no alert — exactly the silence this
            // whole design is built to avoid.
            $this->assertUsableDestination($disk);

            $manifest = $this->archive->write($local);

            $stream = fopen($local, 'rb');

            try {
                // Streamed, so a large archive never has to fit in memory.
                if (! Storage::disk($disk)->put($path, $stream)) {
                    throw new \RuntimeException("ההעלאה ליעד \"{$disk}\" נכשלה.");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $backup->update([
                'status' => BackupStatus::Completed,
                'size_bytes' => (int) filesize($local),
                'manifest' => $manifest,
                'finished_at' => now(),
            ]);

            SystemLog::record('info', 'backup', "גיבוי הושלם: {$path}", [
                'backup_id' => $backup->id,
                'rows' => $manifest['rows'] ?? 0,
                'files' => $manifest['files'] ?? 0,
            ]);

            $this->prune();
        } catch (Throwable $e) {
            // A half-written object on the destination would look like a real
            // archive in the bucket listing.
            $backup->deleteArchive();

            $this->fail($backup, $e->getMessage());

            throw $e;
        } finally {
            if (is_string($local) && file_exists($local)) {
                unlink($local);
            }
        }

        return $backup->fresh();
    }

    /**
     * Mark a run failed, log it and tell the team — the single place that does
     * so, because a failure recorded without the email is a failure nobody
     * hears about, which is the same as no backup at all.
     */
    public function fail(Backup $backup, string $reason): void
    {
        $backup->update([
            'status' => BackupStatus::Failed,
            'error' => mb_substr($reason, 0, 2000),
            'finished_at' => now(),
        ]);

        SystemLog::record('error', 'backup', 'גיבוי נכשל: '.mb_substr($reason, 0, 300), [
            'backup_id' => $backup->id,
        ]);

        $this->alert($reason);
    }

    /**
     * Record a run that never started because another backup or restore held
     * the lock. Silence would mean a night with no copy of the business and
     * nobody the wiser.
     */
    public function recordBlocked(?int $userId): Backup
    {
        $backup = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => (string) config('backup.disk'),
            'path' => '',
            'user_id' => $userId,
        ]);

        $this->fail($backup, 'פעולת גיבוי או שחזור אחרת רצה באותו רגע — הגיבוי לא בוצע.');

        return $backup;
    }

    /**
     * Refuse to write an archive somewhere the web can read it. The panel
     * rejects a public disk too, but BACKUP_DISK can also be set straight in
     * .env — and this archive holds every customer record under a predictable
     * name, so the check belongs where the writing happens.
     */
    private function assertUsableDestination(string $disk): void
    {
        if ((config("filesystems.disks.{$disk}.visibility") ?? null) === 'public') {
            throw new \RuntimeException(
                "יעד הגיבוי \"{$disk}\" ציבורי — הארכיון מכיל פרטי לקוחות וחייב יעד פרטי."
            );
        }

        // A disk we back UP cannot also be where the backup lands. It sits on
        // the same machine, so it is not disaster recovery at all — and since
        // that disk is itself archived, every run would swallow the previous
        // archives and grow without end.
        if (array_key_exists($disk, (array) config('backup.files', []))) {
            throw new \RuntimeException(
                "יעד הגיבוי \"{$disk}\" הוא אחד מהמקורות שמגובים — צריך יעד חיצוני, מחוץ לשרת."
            );
        }
    }

    /**
     * Drop archives past the retention window — but never go below the floor,
     * so a badly-set retention cannot leave the business with nothing.
     */
    public function prune(): int
    {
        $days = (int) config('backup.retention_days');
        $keep = max(1, (int) config('backup.keep_at_least'));

        if ($days <= 0) {
            return 0;
        }

        $protected = Backup::query()->restorable()
            ->latest('id')
            ->limit($keep)
            ->pluck('id');

        $stale = Backup::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('id', $protected)
            ->get();

        foreach ($stale as $backup) {
            $backup->deleteArchive();
            $backup->delete();
        }

        return $stale->count();
    }

    /**
     * Tell the team the nightly backup failed. A backup nobody knows is broken
     * is the same as no backup, and the only moment that becomes obvious is the
     * one where it is already too late.
     */
    private function alert(string $reason): void
    {
        $to = EmailList::parse(config('billing.notifications.team_email'));

        if ($to === []) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new NotificationMail(
            'הגיבוי האוטומטי נכשל',
            "הגיבוי האוטומטי של המערכת לא הושלם.\n\nסיבה: ".mb_substr($reason, 0, 500)
            ."\n\nכדאי לבדוק את הגדרות היעד במסך \"גיבוי ושחזור\".",
        )), report: false);
    }

    /**
     * Sortable, unambiguous, and readable in a bucket listing — with a random
     * tail, because two runs finishing inside the same second would otherwise
     * share a path: the second upload would overwrite the first, both history
     * rows would download the same file, and pruning either would delete the
     * object the other still points at.
     */
    private function pathFor(): string
    {
        $folder = trim((string) config('backup.path'), '/');
        $name = 'multioto-'.now()->format('Y-m-d-His').'-'.Str::lower(Str::random(6)).'.zip';

        return $folder === '' ? $name : "{$folder}/{$name}";
    }
}
