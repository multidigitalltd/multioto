<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Schema;
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
            $problems = array_merge($problems, $this->fileProblems($zip, $manifest));

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
     * Every table member is present, intact, and holds exactly the rows the
     * manifest promises — every one of them readable.
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

            // The columns this installation actually has. A table the archive
            // names and the database does not is already reported by
            // tableProblems(); there is nothing here to compare against.
            $schema = Schema::hasTable($table) ? $this->columnsOf($table) : null;

            $read = $this->readRows($stream, $schema);

            if ($read['corrupt']) {
                // The checksum, and nothing else, notices this one: a bit flip
                // inside a row can leave both the line structure and the row
                // count intact, so counting and parsing would pass an archive
                // whose business data has been quietly altered. The restore
                // refuses the same member, and a drill that certifies what the
                // restore will refuse is worse than no drill.
                $problems[] = "הטבלה {$table}: תוכן פגום בארכיון (סכום ביקורת שגוי) — הארכיון הזה לא ישוחזר.";

                continue;
            }

            if ($read['rows'] !== (int) $expected) {
                $problems[] = "הטבלה {$table}: הארכיון מכיל {$read['rows']} שורות במקום ".(int) $expected.'.';
            }

            if ($read['damaged'] > 0) {
                $problems[] = "הטבלה {$table}: {$read['damaged']} שורות אינן קריאות.";
            }

            if ($read['unknown'] !== []) {
                $problems[] = "הטבלה {$table}: הארכיון מכיל עמודות שאינן קיימות בטבלה ("
                    .$this->few($read['unknown']).') — השחזור ייעצר.';
            }

            if ($read['absent'] !== []) {
                $problems[] = "הטבלה {$table}: חסרות בארכיון עמודות חובה ("
                    .$this->few($read['absent']).') — השחזור ייעצר.';
            }

            if ($read['nulls'] !== []) {
                $problems[] = "הטבלה {$table}: עמודות שאינן יכולות להיות ריקות מכילות ערך ריק בארכיון ("
                    .$this->few($read['nulls']).') — השחזור ייעצר.';
            }

            if ($read['mixed']) {
                $problems[] = "הטבלה {$table}: לשורות בארכיון מבנה שונה זו מזו — השחזור ייעצר.";
            }
        }

        return $problems;
    }

    /**
     * A table's columns, the ones a row cannot leave out, and the ones that
     * cannot hold NULL.
     *
     * Required means the database has nothing to put there: NOT NULL, with no
     * default of its own and no counter behind it. Every other column may be
     * absent from a row — the insert fills it in — and demanding them all would
     * fail perfectly restorable archives written before a nullable column
     * existed, which is the kind of false alarm that teaches people to ignore
     * the report that matters.
     *
     * "Cannot hold NULL" is the wider set: a default saves a column that is
     * absent, and does nothing for one that is present and null.
     *
     * @return array{columns: list<string>, required: list<string>, notNull: list<string>}
     */
    private function columnsOf(string $table): array
    {
        $columns = [];
        $required = [];
        $notNull = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];
            $columns[] = $name;

            if ($column['nullable'] ?? false) {
                continue;
            }

            $notNull[] = $name;

            if (($column['default'] ?? null) === null && ! ($column['auto_increment'] ?? false)) {
                $required[] = $name;
            }
        }

        return ['columns' => $columns, 'required' => $required, 'notNull' => $notNull];
    }

    /**
     * Read one table member to the end and count what is in it.
     *
     * Read in CHUNKS rather than with fgets(), because the two answer different
     * questions. fgets() stops at the last line and reports the end of the data;
     * the archive's checksum is verified by the read that comes AFTER it, which
     * fgets() never makes — so damage that happens to leave the declared number
     * of parseable rows behind would pass unseen. Reading through, exactly as
     * BackupRestorer::assertMemberIntact() does, is what surfaces it.
     *
     * @param  resource  $stream
     * @param  array{columns: list<string>, required: list<string>, notNull: list<string>}|null  $schema
     * @return array{rows: int, damaged: int, unknown: list<string>, absent: list<string>, nulls: list<string>, mixed: bool, corrupt: bool}
     */
    private function readRows($stream, ?array $schema): array
    {
        $rows = 0;
        $damaged = 0;
        $unknown = [];
        $absent = [];
        $nulls = [];
        $mixed = false;
        $buffer = '';

        // Rows in one member all carry the same key set, so the SHAPE is
        // examined once per distinct set rather than once per row — a table
        // with millions of them should not pay for that check a million times.
        $accepted = null;

        // The columns of that shape which cannot hold NULL. Values, unlike
        // shape, are per row and have to be looked at every time — but only
        // these few columns, not all of them.
        $noNulls = [];

        $count = function (string $line) use (&$rows, &$damaged, &$unknown, &$absent, &$nulls, &$mixed, &$accepted, &$noNulls, $schema): void {
            if (trim($line) === '') {
                return;
            }

            $rows++;

            $row = json_decode($line, true);

            // A row is a MAP of column => value. A line reading "false" or "0"
            // parses perfectly and is not one; neither is a JSON list such as
            // ["x"], which decodes to an array with numeric keys and would be
            // handed to insert() as columns named 0, 1, 2. The restore fails on
            // all of them, and a drill that certifies what the restore will
            // refuse is worse than no drill.
            if (! is_array($row) || $row === [] || array_is_list($row)) {
                $damaged++;

                return;
            }

            if ($schema === null) {
                return;
            }

            $keys = array_keys($row);
            $signature = implode("\0", $keys);

            if ($signature !== $accepted) {
                // Named columns the table does not have. insert() is handed
                // exactly these keys and fails on them — with every table
                // already emptied, which is the point at which a restore is at
                // its least recoverable.
                $extra = array_diff($keys, $schema['columns']);

                // And the other direction: a column the table requires and the
                // row does not carry. The database has nothing to put there —
                // no value, no default, and NULL refused.
                $short = array_diff($schema['required'], $keys);

                if ($extra !== [] || $short !== []) {
                    foreach ($extra as $name) {
                        $unknown[(string) $name] = true;
                    }

                    foreach ($short as $name) {
                        $absent[(string) $name] = true;
                    }

                    return;
                }

                // Two shapes in one member, each fine on its own. The restore
                // batches rows into a single insert whose column list comes
                // from the first of them while every tuple keeps its own
                // values, so the second shape does not get its defaults filled
                // in — it makes the statement itself invalid.
                if ($accepted !== null) {
                    $mixed = true;
                }

                $accepted = $signature;
                $noNulls = array_values(array_intersect($schema['notNull'], $keys));
            }

            // A column that is present and null. A default rescues a column the
            // row leaves out and does nothing for one it explicitly empties, so
            // this is the wider set — and it has to be looked at per row, since
            // the shape says nothing about the values.
            foreach ($noNulls as $name) {
                if ($row[$name] === null) {
                    $nulls[$name] = true;
                }
            }
        };

        try {
            while (true) {
                $chunk = @fread($stream, self::READ_CHUNK);

                if ($chunk === false) {
                    return [
                        'rows' => $rows,
                        'damaged' => $damaged,
                        'unknown' => [],
                        'absent' => [],
                        'nulls' => [],
                        'mixed' => false,
                        'corrupt' => true,
                    ];
                }

                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;
                $lines = explode("\n", $buffer);

                // The last piece may be half a line, split across this chunk
                // and the next — it waits for the rest rather than being
                // counted as a row of its own.
                $buffer = (string) array_pop($lines);

                foreach ($lines as $line) {
                    $count($line);
                }
            }
        } finally {
            fclose($stream);
        }

        // A final line with no newline after it is still a row.
        $count($buffer);

        return [
            'rows' => $rows,
            'damaged' => $damaged,
            'unknown' => array_keys($unknown),
            'absent' => array_keys($absent),
            'nulls' => array_keys($nulls),
            'mixed' => $mixed,
            'corrupt' => false,
        ];
    }

    /**
     * Every attachment the archive declares is present, readable, AND belongs
     * somewhere this installation can put it back.
     *
     * Read to the end, not merely located: a corrupt payload or a bad checksum
     * still has a perfectly valid directory entry, so opening it succeeds and
     * only reading it through finds the damage. The restore does exactly this
     * before it overwrites the first live file — and a drill that passed an
     * archive the restore will refuse would be worse than no drill at all,
     * because it is the reassurance somebody acts on.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function fileProblems(ZipArchive $zip, array $manifest): array
    {
        $raw = $zip->getFromName(BackupArchive::FILE_LIST);

        if ($raw === false) {
            return ['לא נמצאה רשימת הקבצים בארכיון.'];
        }

        $list = json_decode($raw, true);

        if (! is_array($list)) {
            return ['רשימת הקבצים בארכיון אינה קריאה.'];
        }

        $files = array_values(array_filter($list, 'is_string'));
        $expected = (int) ($manifest['files'] ?? count($files));

        $problems = count($files) === $expected ? [] : [
            'רשימת הקבצים בארכיון חלקית ('.count($files)." מתוך {$expected}).",
        ];

        $missing = [];
        $damaged = [];
        $unplaceable = [];

        foreach ($files as $file) {
            if (($reason = $this->destinationProblem($file)) !== null) {
                $unplaceable[$reason][] = $file;

                continue;
            }

            $stream = $zip->getStream('files/'.$file);

            if ($stream === false) {
                $missing[] = $file;

                continue;
            }

            try {
                while (true) {
                    $chunk = @fread($stream, self::READ_CHUNK);

                    if ($chunk === false) {
                        $damaged[] = $file;

                        break;
                    }

                    if ($chunk === '') {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        if ($missing !== []) {
            $problems[] = 'קבצים שרשומים בארכיון וחסרים בו: '.$this->few($missing);
        }

        if ($damaged !== []) {
            $problems[] = 'קבצים בארכיון שלא ניתן לקרוא עד הסוף: '.$this->few($damaged);
        }

        foreach ($unplaceable as $reason => $names) {
            $problems[] = $reason.': '.$this->few($names);
        }

        return $problems;
    }

    /**
     * Why the restore would refuse to put this file back, or null when it would
     * accept it.
     *
     * The archive names its attachments "{disk}/{path}", and the restore stops
     * outright — before a single row is replaced — for a disk this installation
     * no longer configures, or for a path that would write outside the disk's
     * root. Configuration drift is exactly the kind of thing a monthly drill
     * exists to find: ATTACHMENT_DISK changed after a rebuild, and the archives
     * written before it became unrestorable without anybody touching them.
     */
    private function destinationProblem(string $entry): ?string
    {
        $slash = strpos($entry, '/');

        if ($slash === false) {
            return 'רשומות קבצים בארכיון שאינן נתיב תקין';
        }

        $disk = substr($entry, 0, $slash);
        $path = substr($entry, $slash + 1);

        if ($path === '') {
            return 'רשומות קבצים בארכיון שאינן נתיב תקין';
        }

        if (! array_key_exists($disk, (array) config('backup.files', []))) {
            return "הגיבוי מכיל קבצים מיעד אחסון \"{$disk}\" שאינו מוגדר בהתקנה הזו — השחזור ייעצר. "
                .'יש להגדיר את אותם יעדי אחסון (ATTACHMENT_DISK ו-backup.files)';
        }

        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return 'נתיבים לא חוקיים בארכיון — השחזור ייעצר';
        }

        return null;
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
