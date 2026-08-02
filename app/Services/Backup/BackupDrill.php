<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Open the newest archive and read it through, without restoring anything.
 *
 * A backup that has never been opened is a hope. The nightly run can report
 * success for a year while the destination truncates large uploads, while the
 * archive format drifts past the code that reads it, or while migrations move
 * the schema out from under it — and every one of those is discovered on the
 * one day it must not be. So the archive is fetched, opened, and every row in
 * it counted, once a month.
 *
 * NOTHING is restored. A drill that put rows back would be the most dangerous
 * job in the system, and it would have to run on the live database to be worth
 * anything. What it proves instead is everything up to that point: the file is
 * there, it opens, it declares what it should, every table it names is present,
 * every row in them is readable, and the schema it was taken from is still the
 * schema we have. Beyond that only a real restore can speak, and that stays a
 * decision a person makes.
 */
class BackupDrill
{
    /** Copied in pieces so a large archive does not have to fit in memory. */
    private const READ_CHUNK = 8 * 1024 * 1024;

    /** Attachments checked by name inside the archive; a sample, not the lot. */
    private const FILE_SAMPLE = 50;

    public function __construct(private BackupArchive $archive) {}

    /**
     * The newest completed archive, or null when there is nothing to open yet.
     */
    public function latest(): ?Backup
    {
        return Backup::query()->restorable()->latest('finished_at')->latest('id')->first();
    }

    /**
     * Read the archive through and say what is wrong with it.
     *
     * @return array{checked_at: string, tables: int, rows: int, files: int, problems: list<string>}
     */
    public function run(Backup $backup): array
    {
        $local = tempnam(sys_get_temp_dir(), 'multioto-drill-');

        try {
            $this->download($backup, $local);

            return $this->inspect($local);
        } finally {
            if (is_string($local) && file_exists($local)) {
                unlink($local);
            }
        }
    }

    /** Pull the archive down from wherever it lives. */
    private function download(Backup $backup, string $to): void
    {
        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if ($stream === null || $stream === false) {
            throw new RuntimeException('קובץ הגיבוי אינו נמצא ביעד האחסון.');
        }

        $out = fopen($to, 'wb');

        try {
            while (! feof($stream)) {
                $copied = stream_copy_to_stream($stream, $out, self::READ_CHUNK);

                if ($copied === false) {
                    throw new RuntimeException('קריאת קובץ הגיבוי מהיעד נכשלה.');
                }

                // A read that returns nothing without reporting the end would
                // spin here for ever. Stopping is safe: an archive that came
                // down short cannot pass the checks below — the zip directory
                // sits at the end of the file.
                if ($copied === 0) {
                    break;
                }
            }
        } finally {
            fclose($out);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Everything that can be asked of an archive without putting it back.
     *
     * Problems are collected rather than thrown one at a time: the point of a
     * drill is to learn everything that is wrong while there is time to fix it,
     * not to stop at the first thing.
     *
     * @return array{checked_at: string, tables: int, rows: int, files: int, problems: list<string>}
     */
    private function inspect(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('קובץ הגיבוי אינו נפתח — הארכיון פגום או חלקי.');
        }

        try {
            $manifest = $this->manifest($zip);
            $problems = [];

            if ((int) ($manifest['format'] ?? 0) !== BackupArchive::FORMAT) {
                $problems[] = 'פורמט הגיבוי אינו נתמך בגרסה הזו — הארכיון הזה לא ישוחזר.';
            }

            $declared = (array) ($manifest['tables'] ?? []);
            $problems = array_merge($problems, $this->tableProblems(array_keys($declared)));
            $problems = array_merge($problems, $this->schemaProblems((array) ($manifest['migrations'] ?? [])));
            $problems = array_merge($problems, $this->rowProblems($zip, $declared));
            $problems = array_merge($problems, $this->fileProblems($zip));

            return [
                'checked_at' => now()->toIso8601String(),
                'tables' => count($declared),
                'rows' => (int) array_sum(array_map('intval', $declared)),
                'files' => (int) ($manifest['files'] ?? 0),
                'problems' => array_values($problems),
            ];
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    private function manifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName(BackupArchive::MANIFEST);

        if ($raw === false) {
            throw new RuntimeException('לא נמצא מניפסט בארכיון — הקובץ אינו גיבוי תקין.');
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            throw new RuntimeException('המניפסט בארכיון אינו קריא.');
        }

        return $manifest;
    }

    /**
     * The archive declares exactly the tables a restore would replace — no
     * fewer (live rows would be left standing beside restored ones) and no
     * more (a table that must never be replaced would be).
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    private function tableProblems(array $declared): array
    {
        $expected = $this->archive->tables();
        $problems = [];

        if (($missing = array_values(array_diff($expected, $declared))) !== []) {
            $problems[] = 'הגיבוי אינו כולל טבלאות: '.$this->few($missing);
        }

        if (($extra = array_values(array_diff($declared, $expected))) !== []) {
            $problems[] = 'הגיבוי כולל טבלאות שאינן אמורות להיות בו: '.$this->few($extra);
        }

        return $problems;
    }

    /**
     * The schema has not moved since. This is the finding that ages an archive
     * without anybody touching it: every deployment that adds a column makes
     * yesterday's backup a little less restorable, and only asking out loud
     * turns that into something a person knows before they need it.
     *
     * @param  list<string>  $recorded
     * @return list<string>
     */
    private function schemaProblems(array $recorded): array
    {
        $current = $this->archive->migrations();
        $drift = array_values(array_merge(
            array_diff($recorded, $current),
            array_diff($current, $recorded),
        ));

        return $drift === [] ? [] : [
            'מבנה בסיס הנתונים השתנה מאז הגיבוי ('.count($drift).' שינויים) — שחזור ישיר של הארכיון הזה ייעצר. '
                .'הגיבוי הלילי הבא כבר ייכתב במבנה הנוכחי.',
        ];
    }

    /**
     * Every table member is present and holds exactly the rows the manifest
     * promises, and every one of those rows is readable.
     *
     * Counted by reading, not by trusting: a truncated upload leaves a member
     * that opens and ends early, and its manifest still claims the full number.
     *
     * @param  array<string, mixed>  $declared
     * @return list<string>
     */
    private function rowProblems(ZipArchive $zip, array $declared): array
    {
        $problems = [];

        foreach ($declared as $table => $expected) {
            $member = "database/{$table}.ndjson";
            $stream = $zip->getStream($member);

            if ($stream === false) {
                $problems[] = "חסר בארכיון: {$member}";

                continue;
            }

            $rows = 0;
            $damaged = 0;

            try {
                while (($line = fgets($stream)) !== false) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $rows++;

                    if (json_decode($line, true) === null) {
                        $damaged++;
                    }
                }
            } finally {
                fclose($stream);
            }

            if ($rows !== (int) $expected) {
                $problems[] = "הטבלה {$table}: הארכיון מכיל {$rows} שורות במקום ".(int) $expected.'.';
            }

            if ($damaged > 0) {
                $problems[] = "הטבלה {$table}: {$damaged} שורות אינן קריאות.";
            }
        }

        return $problems;
    }

    /**
     * A sample of the attachments the archive says it carries really are in it.
     *
     * A sample rather than all of them: an archive can hold tens of thousands
     * of files, and a member list that is right for fifty consecutive entries
     * and wrong afterwards is not the failure mode — a broken upload loses the
     * end of the file, which the row counts above already catch.
     *
     * @return list<string>
     */
    private function fileProblems(ZipArchive $zip): array
    {
        $raw = $zip->getFromName(BackupArchive::FILE_LIST);

        if ($raw === false) {
            return ['לא נמצאה רשימת הקבצים בארכיון.'];
        }

        $files = json_decode($raw, true);

        if (! is_array($files)) {
            return ['רשימת הקבצים בארכיון אינה קריאה.'];
        }

        $missing = [];

        foreach (array_slice(array_values($files), 0, self::FILE_SAMPLE) as $file) {
            if (! is_string($file)) {
                continue;
            }

            if ($zip->locateName('files/'.$file) === false) {
                $missing[] = $file;
            }
        }

        return $missing === [] ? [] : ['קבצים שרשומים בארכיון וחסרים בו: '.$this->few($missing)];
    }

    /**
     * A few names and a count, not a wall of them.
     *
     * @param  list<string>  $names
     */
    private function few(array $names): string
    {
        return implode(', ', array_slice($names, 0, 5))
            .(count($names) > 5 ? ' ועוד '.(count($names) - 5) : '');
    }
}
