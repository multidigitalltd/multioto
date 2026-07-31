<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\SystemLog;
use App\Support\EmailList;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
    /**
     * Marks a row created by a scan that found the archive but could not read
     * it. Kept exact, because it is what tells a later scan to try again.
     */
    public const IMPORT_UNREADABLE = 'הארכיון נמצא ביעד אך לא ניתן לקרוא את תוכנו.';

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

            $this->warnAboutOmissions($backup, (array) ($manifest['skipped_files'] ?? []));

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
        return $this->recordUnstarted(
            $userId,
            'פעולת גיבוי או שחזור אחרת רצה באותו רגע — הגיבוי לא בוצע.'
        );
    }

    /**
     * Record a run that never got as far as writing anything — the lock was
     * taken, or the queue would not accept the job at all. It gets a row and an
     * alert like any other failure: a request that vanishes without a trace is
     * indistinguishable from a night nobody looked at.
     */
    public function recordUnstarted(?int $userId, string $reason): Backup
    {
        $backup = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => (string) config('backup.disk'),
            'path' => '',
            'user_id' => $userId,
        ]);

        $this->fail($backup, $reason);

        return $backup;
    }

    /**
     * Adopt archives that are at the destination but have no row here.
     *
     * This is the disaster the whole feature exists for: the server is gone,
     * the database with it, and the only thing left is a bucket full of ZIPs.
     * A fresh installation knows nothing about them — the history table is
     * deliberately left OUT of every archive, so it cannot bring itself back.
     * Without this the feature would work for every case except the one it was
     * built for.
     *
     * @return array{imported: int, unreadable: int}
     */
    public function importFromDisk(): array
    {
        $disk = (string) config('backup.disk');
        $prefix = trim((string) config('backup.path'), '/');

        $imported = 0;
        $unreadable = 0;

        foreach (Storage::disk($disk)->files($prefix) as $path) {
            if (! str_ends_with(mb_strtolower($path), '.zip')) {
                continue;
            }

            $existing = Backup::query()->where('disk', $disk)->where('path', $path)->first();

            // Already listed, and the read worked. A row whose read did NOT
            // work is tried again: a dropped connection or a full temp disk
            // makes a perfectly good archive look corrupt, and skipping it for
            // ever afterwards would leave the business unable to restore from
            // a backup that is sitting right there.
            if ($existing !== null && ! $this->isUnreadableImport($existing)) {
                continue;
            }

            $manifest = $this->manifestAt($disk, $path);

            if ($manifest === null) {
                $unreadable++;
            }

            $when = rescue(
                fn (): ?int => Storage::disk($disk)->lastModified($path),
                null,
                report: false,
            );

            $size = rescue(fn (): int => (int) Storage::disk($disk)->size($path), 0, report: false);

            $adopted = $existing === null
                ? $this->insertImport($disk, $path, $manifest, $size, $when)
                : $this->upgradeImport($existing, $manifest, $size, $when);

            if ($adopted && $manifest !== null) {
                $imported++;
            }
        }

        if ($imported > 0 || $unreadable > 0) {
            SystemLog::record('warning', 'backup', "יובאו {$imported} גיבויים מהיעד ({$unreadable} לא קריאים).");
        }

        return ['imported' => $imported, 'unreadable' => $unreadable];
    }

    /**
     * List an archive that has no row yet.
     *
     * @param  array<string, mixed>|null  $manifest  null when the archive could not be read
     */
    private function insertImport(string $disk, string $path, ?array $manifest, int $size, ?int $when): bool
    {
        $backup = new Backup([
            'disk' => $disk,
            'path' => $path,
            // An archive whose manifest cannot be read is listed too, but as a
            // failure: it is not restorable, and leaving it invisible would
            // just mean paying to store something nobody can see.
            'status' => $manifest === null ? BackupStatus::Failed : BackupStatus::Completed,
            'size_bytes' => $size,
            'manifest' => $manifest,
            'error' => $manifest === null ? self::IMPORT_UNREADABLE : null,
            'finished_at' => $when !== null ? Carbon::createFromTimestamp($when) : null,
        ]);

        // Dated by the file itself, so the list stays in the order the archives
        // were actually taken and retention keeps working.
        if ($when !== null) {
            $backup->created_at = Carbon::createFromTimestamp($when);
            $backup->updated_at = $backup->created_at;
        }

        try {
            $backup->save();

            return true;
        } catch (UniqueConstraintViolationException) {
            // Another scan got there first. Two rows for one archive would mean
            // deleting either takes the file out from under the other — but a
            // read that worked must not be thrown away just because the other
            // scan's read did not.
            $winner = Backup::query()->where('disk', $disk)->where('path', $path)->first();

            return $winner !== null && $this->upgradeImport($winner, $manifest, $size, $when);
        }
    }

    /**
     * Fill in a row a scan listed but could not read, now that it can be.
     *
     * Only ever in that direction. A failed read must never overwrite a row
     * another scan already completed — that would take a restorable archive
     * away again, on the strength of one dropped connection.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    private function upgradeImport(Backup $backup, ?array $manifest, int $size, ?int $when): bool
    {
        if ($manifest === null) {
            return false;
        }

        // Conditional, so a scan that read the archive at the same moment
        // cannot be undone by this one arriving late.
        return Backup::query()
            ->whereKey($backup->id)
            ->where('status', BackupStatus::Failed)
            ->where('error', self::IMPORT_UNREADABLE)
            ->update([
                'status' => BackupStatus::Completed,
                'size_bytes' => $size,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                'error' => null,
                'finished_at' => $when !== null ? Carbon::createFromTimestamp($when) : null,
            ]) === 1;
    }

    /** A row a scan created for an archive it could not read — worth retrying. */
    private function isUnreadableImport(Backup $backup): bool
    {
        return $backup->status === BackupStatus::Failed
            && $backup->manifest === null
            && $backup->error === self::IMPORT_UNREADABLE;
    }

    /** Read one archive's manifest without keeping the whole file around. */
    private function manifestAt(string $disk, string $path): ?array
    {
        $local = tempnam(sys_get_temp_dir(), 'multioto-import-');

        try {
            $source = Storage::disk($disk)->readStream($path);

            if (! is_resource($source)) {
                return null;
            }

            $target = fopen($local, 'wb');

            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);
                fclose($source);
            }

            return $this->archive->manifestOf($local);
        } catch (Throwable) {
            return null;
        } finally {
            if (is_string($local) && file_exists($local)) {
                unlink($local);
            }
        }
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

        $removed = 0;

        foreach ($stale as $backup) {
            // Keep the row when the object survives, so the archive stays
            // findable and retention keeps trying rather than losing track of
            // a file full of customer data.
            if (! $backup->deleteArchive()) {
                SystemLog::record('warning', 'backup', "לא ניתן היה למחוק את קובץ הגיבוי {$backup->path} — הרשומה נשמרה.", [
                    'backup_id' => $backup->id,
                ]);

                continue;
            }

            $backup->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * A backup that left files out is not a quiet success.
     *
     * A file too large to archive, or unreadable at the time, is not in the
     * archive — but its row is, so after losing the storage volume a restore
     * would bring back a row pointing at a file no backup ever held. The
     * archive is still worth keeping, so the run is not called a failure; it is
     * called out instead, in the log and to the team.
     *
     * @param  list<string>  $skipped
     */
    private function warnAboutOmissions(Backup $backup, array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $count = count($skipped);

        SystemLog::record('warning', 'backup', "הגיבוי הושלם אך {$count} קבצים לא נכללו בו.", [
            'backup_id' => $backup->id,
            'skipped' => array_slice($skipped, 0, 20),
        ]);

        $to = EmailList::parse(config('billing.notifications.team_email'));

        if ($to === []) {
            return;
        }

        rescue(fn () => Mail::to($to)->send(new NotificationMail(
            "הגיבוי הושלם — אך {$count} קבצים לא נכללו",
            "הגיבוי האחרון הצליח, אך {$count} קבצים לא נכנסו אליו (גדולים מהמותר או שלא ניתן היה לקרוא אותם).\n\n"
            ."הרשומות שמצביעות עליהם כן מגובות, כך שאחרי שחזור הן יצביעו לקבצים שאינם קיימים.\n\n"
            .implode("\n", array_slice($skipped, 0, 20))
            ."\n\nאפשר להגדיל את המגבלה דרך BACKUP_MAX_FILE_BYTES, או לטפל בקבצים האלה בנפרד.",
        )), report: false);
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
