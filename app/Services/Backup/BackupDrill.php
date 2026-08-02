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

    /**
     * How many key values to remember per table while looking for duplicates.
     *
     * The set is the only part of this job that grows with the size of a table,
     * and a drill that runs the worker out of memory is worse than one that
     * says it stopped looking.
     */
    private const MAX_KEYS = 500000;

    /**
     * What is left of the key budget for this archive.
     *
     * Shared across every table, because the memory it protects is: a ceiling
     * applied per table would be no ceiling at all with fifty of them.
     */
    private int $keyBudget = self::MAX_KEYS;

    public function __construct(private BackupArchive $archive) {}

    /**
     * The newest completed archive, or null when there is nothing to open yet.
     *
     * Undated rows go LAST, not first. An archive found in the bucket by the
     * import is saved with no finished_at when the destination cannot say when
     * it was written, and PostgreSQL sorts NULLs first in a descending order —
     * so one such row would win this query for ever, and the drill would spend
     * every month re-reading the same arbitrary archive while the newest
     * recovery point went unopened. SQLite sorts them last of its own accord,
     * which is exactly why this cannot be left to the driver.
     */
    public function latest(): ?Backup
    {
        return Backup::query()
            ->restorable()
            ->orderByRaw('finished_at IS NULL')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
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
            $this->keyBudget = self::MAX_KEYS;

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
        $schemas = [];
        $collected = [];
        $faulty = [];

        foreach (array_keys($declared) as $table) {
            if (Schema::hasTable($table)) {
                $schemas[$table] = $this->columnsOf($table);
            }
        }

        // Which columns some OTHER table points at, so their values are
        // remembered while that table is read and the references can be
        // resolved once every member has been seen.
        $referenced = [];

        foreach ($schemas as $schema) {
            foreach ($schema['foreign'] as $key) {
                if (array_key_exists($key['table'], $schemas)) {
                    $referenced[$key['table']][implode(',', $key['references'])] = $key['references'];
                }
            }
        }

        foreach ($declared as $table => $expected) {
            $member = "database/{$table}.ndjson";
            $stream = $zip->getStream($member);

            if ($stream === false) {
                $problems[] = "חסר בארכיון: {$member}";
                $faulty[$table] = true;

                continue;
            }

            // The columns this installation actually has. A table the archive
            // names and the database does not is already reported by
            // tableProblems(); there is nothing here to compare against.
            $schema = $schemas[$table] ?? null;

            $read = $this->readRows($stream, $schema, $this->watched($table, $schema, $referenced, $schemas));
            $collected[$table] = $read['values'];
            $found = [];

            if ($read['corrupt']) {
                // The checksum, and nothing else, notices this one: a bit flip
                // inside a row can leave both the line structure and the row
                // count intact, so counting and parsing would pass an archive
                // whose business data has been quietly altered. The restore
                // refuses the same member, and a drill that certifies what the
                // restore will refuse is worse than no drill.
                $problems[] = "הטבלה {$table}: תוכן פגום בארכיון (סכום ביקורת שגוי) — הארכיון הזה לא ישוחזר.";
                $faulty[$table] = true;

                continue;
            }

            if ($read['rows'] !== (int) $expected) {
                $found[] = "הטבלה {$table}: הארכיון מכיל {$read['rows']} שורות במקום ".(int) $expected.'.';
            }

            if ($read['damaged'] > 0) {
                $found[] = "הטבלה {$table}: {$read['damaged']} שורות אינן קריאות.";
            }

            if ($read['unknown'] !== []) {
                $found[] = "הטבלה {$table}: הארכיון מכיל עמודות שאינן קיימות בטבלה ("
                    .$this->few($read['unknown']).') — השחזור ייעצר.';
            }

            if ($read['absent'] !== []) {
                $found[] = "הטבלה {$table}: חסרות בארכיון עמודות חובה ("
                    .$this->few($read['absent']).') — השחזור ייעצר.';
            }

            if ($read['nulls'] !== []) {
                $found[] = "הטבלה {$table}: עמודות שאינן יכולות להיות ריקות מכילות ערך ריק בארכיון ("
                    .$this->few($read['nulls']).') — השחזור ייעצר.';
            }

            if ($read['mistyped'] !== []) {
                $found[] = "הטבלה {$table}: ערכים שאינם מתאימים לסוג העמודה ("
                    .$this->few($read['mistyped']).') — השחזור ייעצר.';
            }

            if ($read['repeated'] !== []) {
                $found[] = "הטבלה {$table}: ערכים כפולים במפתח ייחודי ("
                    .$this->few($read['repeated']).') — השחזור ייעצר.';
            }

            if ($read['unchecked']) {
                $found[] = "הטבלה {$table}: בדיקת הכפילויות וההפניות נעצרה בתקרה של "
                    .number_format(self::MAX_KEYS).' ערכים ולא כיסתה את כל הגיבוי.';
            }

            if ($read['mixed']) {
                $found[] = "הטבלה {$table}: לשורות בארכיון מבנה שונה זו מזו — השחזור ייעצר.";
            }

            if ($found !== []) {
                $faulty[$table] = true;
                $problems = array_merge($problems, $found);
            }
        }

        return array_merge($problems, $this->referenceProblems($schemas, $collected, $faulty));
    }

    /**
     * The value sets worth remembering while one table is read: what other
     * tables point at, and what this one points at.
     *
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, array<string, list<string>>>  $referenced
     * @param  array<string, array<string, mixed>>  $schemas
     * @return array<string, list<string>>
     */
    private function watched(string $table, ?array $schema, array $referenced, array $schemas): array
    {
        if ($schema === null) {
            return [];
        }

        $watch = [];

        foreach ($referenced[$table] ?? [] as $signature => $columns) {
            $watch['p:'.$signature] = $columns;
        }

        foreach ($schema['foreign'] as $index => $key) {
            if (array_key_exists($key['table'], $schemas)) {
                $watch['c:'.$index] = $key['columns'];
            }
        }

        return $watch;
    }

    /**
     * References whose row is not in the archive.
     *
     * A child row pointing at a parent the backup does not contain restores no
     * further than its insert — the constraint refuses it, with every table
     * already emptied. Resolved here rather than while reading, because a child
     * member can be read long before the parent it points at.
     *
     * A table that already has a problem of its own is not asked: its missing
     * rows would make every child that points at them look broken too, and one
     * fault should not fill the report with its consequences.
     *
     * @param  array<string, array<string, mixed>>  $schemas
     * @param  array<string, array<string, array<string, bool>>>  $collected
     * @param  array<string, bool>  $faulty
     * @return list<string>
     */
    private function referenceProblems(array $schemas, array $collected, array $faulty): array
    {
        $problems = [];

        foreach ($schemas as $table => $schema) {
            if (isset($faulty[$table])) {
                continue;
            }

            foreach ($schema['foreign'] as $index => $key) {
                $parent = $key['table'];

                if (! array_key_exists($parent, $schemas) || isset($faulty[$parent])) {
                    continue;
                }

                $held = $collected[$table]['c:'.$index] ?? [];
                $exists = $collected[$parent]['p:'.implode(',', $key['references'])] ?? [];
                $missing = array_diff_key($held, $exists);

                if ($missing === []) {
                    continue;
                }

                $problems[] = "הטבלה {$table}: ".count($missing).' הפניות בעמודה '
                    .implode(', ', $key['columns'])." אינן קיימות בטבלה {$parent} ("
                    .$this->few(array_map(
                        fn (string $value): string => str_replace("\0", '+', $value),
                        array_keys($missing),
                    )).') — השחזור ייעצר.';
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
     * The unique keys travel with them: a member holding the same primary key
     * twice restores no further than the first insert.
     *
     * The unique keys, the foreign keys and the numeric columns travel with
     * them: everything the insert would refuse, asked before it is attempted.
     *
     * @return array{columns: list<string>, required: list<string>, notNull: list<string>, unique: list<list<string>>, defaults: array<string, string>, numeric: array<string, bool>, foreign: list<array{columns: list<string>, table: string, references: list<string>}>}
     */
    private function columnsOf(string $table): array
    {
        $columns = [];
        $required = [];
        $notNull = [];
        $defaults = [];
        $numeric = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];
            $columns[] = $name;

            if (($column['default'] ?? null) !== null) {
                $defaults[$name] = (string) $column['default'];
            }

            // Only the one category a value can be checked against without
            // guessing: a column that holds numbers refuses a word.
            if (preg_match('/int|serial|numeric|decimal|real|double|float|money/i', (string) ($column['type_name'] ?? ''))) {
                $numeric[$name] = true;
            }

            if ($column['nullable'] ?? false) {
                continue;
            }

            $notNull[] = $name;

            if (($column['default'] ?? null) === null && ! ($column['auto_increment'] ?? false)) {
                $required[] = $name;
            }
        }

        $foreign = [];

        foreach (Schema::getForeignKeys($table) as $key) {
            $foreign[] = [
                'columns' => array_values(array_map('strval', (array) $key['columns'])),
                'table' => (string) $key['foreign_table'],
                'references' => array_values(array_map('strval', (array) $key['foreign_columns'])),
            ];
        }

        $unique = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) || ($index['unique'] ?? false)) {
                $unique[] = array_values(array_map('strval', (array) $index['columns']));
            }
        }

        return [
            'columns' => $columns,
            'required' => $required,
            'notNull' => $notNull,
            'unique' => $unique,
            'defaults' => $defaults,
            'numeric' => $numeric,
            'foreign' => $foreign,
        ];
    }

    /**
     * Whether this value reaches the table as NULL.
     *
     * Not the same question as "is it null in the archive". A value the backup
     * could not write as UTF-8 is stored as a {"__b64": …} marker, and the
     * restore turns an unreadable or empty one into NULL on the way in — so a
     * NOT NULL column holding such a marker passes every reading of the JSON
     * and still fails the insert. What matters is the value the restore would
     * produce, not the one the line shows.
     */
    private function arrivesEmpty(mixed $value): bool
    {
        if (is_array($value) && array_key_exists('__b64', $value)) {
            // Deliberately the same expression BackupRestorer::decodeRow() uses.
            return (base64_decode((string) $value['__b64'], true) ?: null) === null;
        }

        return $value === null;
    }

    /**
     * One row's value for a unique key, or null when it has no complete one.
     *
     * A column the row leaves out is NOT uncheckable when the table fills it in:
     * charges.attempt_number defaults to 1 and belongs to the unique key on
     * (subscription_id, period_start, attempt_number), so two rows that both
     * omit it are handed the same 1 and collide. Skipping them would leave the
     * commonest defaulted-key collision invisible.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $key
     * @param  array<string, string>  $defaults
     */
    private function keyValue(array $row, array $key, array $defaults): ?string
    {
        $parts = [];

        foreach ($key as $column) {
            if (array_key_exists($column, $row)) {
                if ($this->arrivesEmpty($row[$column])) {
                    return null;
                }

                $value = $row[$column];
                $parts[] = is_scalar($value) ? (string) $value : (string) json_encode($value);

                continue;
            }

            $default = $defaults[$column] ?? null;

            // No default, or one the database COMPUTES per row — nextval(),
            // now(), a generated uuid. Those are not a constant every row
            // shares, and treating them as one would invent collisions.
            if ($default === null || str_contains($default, '(')) {
                return null;
            }

            $parts[] = $this->literal($default);
        }

        return implode("\0", $parts);
    }

    /**
     * A column default as the value it stands for.
     *
     * Defaults come back in the database's own spelling — PostgreSQL writes a
     * string default as 'x'::character varying — and the point of stripping
     * that down is to compare it with a row that states the value outright.
     * A spelling this does not recognise costs a detection, never a false one.
     */
    private function literal(string $default): string
    {
        $value = trim((string) preg_replace('/::[a-z0-9_ \[\]"]+$/i', '', trim($default)));

        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
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
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, list<string>>  $watch  value sets to remember, by name
     * @return array{rows: int, damaged: int, unknown: list<string>, absent: list<string>, nulls: list<string>, mistyped: list<string>, repeated: list<string>, values: array<string, array<string, bool>>, unchecked: bool, mixed: bool, corrupt: bool}
     */
    private function readRows($stream, ?array $schema, array $watch = []): array
    {
        $rows = 0;
        $damaged = 0;
        $unknown = [];
        $absent = [];
        $nulls = [];
        $mistyped = [];
        $repeated = [];
        $values = [];
        $mixed = false;
        $buffer = '';

        // Values already seen for each unique key, so a member holding the same
        // primary key twice is found here rather than by the insert that stops
        // half way through a restore. Bounded, and never silently: a table past
        // the ceiling says so in the report instead of passing as if it had
        // been examined.
        $seen = [];
        $unchecked = false;

        // Rows in one member all carry the same key set, so the SHAPE is
        // examined once per distinct set rather than once per row — a table
        // with millions of them should not pay for that check a million times.
        $accepted = null;

        // The columns of that shape which cannot hold NULL. Values, unlike
        // shape, are per row and have to be looked at every time — but only
        // these few columns, not all of them.
        $noNulls = [];

        $count = function (string $line) use (&$rows, &$damaged, &$unknown, &$absent, &$nulls, &$mistyped, &$repeated, &$values, &$unchecked, &$seen, &$mixed, &$accepted, &$noNulls, $schema, $watch): void {
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
                if ($this->arrivesEmpty($row[$name])) {
                    $nulls[$name] = true;
                }
            }

            // A value of the wrong kind altogether. An array that is not a
            // b64 marker reaches insert() as an array and no driver takes one;
            // a word in a column that holds numbers is refused by every
            // database that has types. Both read as perfectly good JSON.
            foreach ($row as $column => $value) {
                if (is_array($value) && ! array_key_exists('__b64', $value)) {
                    $mistyped[(string) $column] = true;

                    continue;
                }

                if (isset($schema['numeric'][$column]) && is_string($value) && ! is_numeric($value)) {
                    $mistyped[(string) $column] = true;
                }
            }

            foreach ($schema['unique'] as $key) {
                if ($this->keyBudget <= 0) {
                    $unchecked = true;

                    break;
                }

                $value = $this->keyValue($row, $key, $schema['defaults']);

                // A key the row does not carry in full is not a duplicate of
                // anything, and NULL never collides with NULL in SQL.
                if ($value === null) {
                    continue;
                }

                $name = implode(', ', $key);

                if (isset($seen[$name][$value])) {
                    $repeated[$name] = true;

                    continue;
                }

                $seen[$name][$value] = true;
                $this->keyBudget--;
            }

            // Distinct values only: a thousand children pointing at the same
            // parent are one value to remember, not a thousand.
            foreach ($watch as $name => $columns) {
                if ($this->keyBudget <= 0) {
                    $unchecked = true;

                    break;
                }

                $value = $this->keyValue($row, $columns, $schema['defaults']);

                if ($value === null || isset($values[$name][$value])) {
                    continue;
                }

                $values[$name][$value] = true;
                $this->keyBudget--;
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
                        'mistyped' => [],
                        'repeated' => [],
                        'values' => [],
                        'unchecked' => false,
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
            'mistyped' => array_keys($mistyped),
            'repeated' => array_keys($repeated),
            'values' => $values,
            'unchecked' => $unchecked,
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
