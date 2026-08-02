<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
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

    /**
     * How far PostgreSQL's numeric type reaches, which is how far jsonb does:
     * every number in a jsonb document is stored as one.
     */
    /**
     * The first year PostgreSQL reaches, for a date and a timestamp alike.
     *
     * Unlike the ceiling, which differs between the two, this end is the same
     * for both.
     */
    private const EARLIEST_YEAR = -4713;

    private const NUMERIC_WHOLE_DIGITS = 131072;

    private const NUMERIC_FRACTION_DIGITS = 16383;

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

            if ($read['unstorable'] !== []) {
                $found[] = "הטבלה {$table}: ערכים שהמסד אינו יכול לאחסן — בית אפס, קידוד שבור או מספר מחוץ לטווח ("
                    .$this->few($read['unstorable']).') — השחזור ייעצר.';
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
     * @return array{columns: list<string>, required: list<string>, notNull: list<string>, unique: list<list<string>>, defaults: array<string, string>, limits: array<string, int>, uuid: array<string, bool>, boolean: array<string, bool>, integer: array<string, int>, numeric: array<string, ?array{precision: int, scale: int}>, textual: array<string, bool>, temporal: array<string, string>, precision: array<string, int>, json: array<string, string>, foreign: list<array{columns: list<string>, table: string, references: list<string>}>}
     */
    private function columnsOf(string $table): array
    {
        $columns = [];
        $required = [];
        $notNull = [];
        $defaults = [];
        $limits = [];
        $precision = [];
        $uuid = [];
        $boolean = [];
        $integer = [];
        $numeric = [];
        $temporal = [];
        $json = [];
        $textual = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];
            $columns[] = $name;

            if (($column['default'] ?? null) !== null) {
                $defaults[$name] = (string) $column['default'];
            }

            // Integers kept apart from the wider numeric family, because the
            // two answer differently: a bigint refuses "1.5", which every
            // decimal column takes, and 1 and "01" are ONE value to a bigint
            // and two different strings to everything else.
            $type = (string) ($column['type_name'] ?? '');

            // A declared width, where the database keeps one. PostgreSQL
            // renders varchar(3) as "character varying(3)"; SQLite reports the
            // type it was given, which for a Laravel string column carries no
            // width at all — so the check simply does not apply there.
            if (preg_match('/char/i', $type)
                && preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $width) === 1) {
                $limits[$name] = (int) $width[1];
            }

            // Which columns hold TEXT. Asked positively, and of the schema
            // rather than of the archive: a type this does not recognise gets
            // no check at all, which can miss a fault but cannot invent one.
            if (preg_match('/^(var)?char|^bpchar|^citext|^string|^clob|text/i', $type)) {
                $textual[$name] = true;
            }

            if (preg_match('/^uuid/i', $type)) {
                $uuid[$name] = true;
            } elseif (preg_match('/^bool/i', $type)) {
                $boolean[$name] = true;
            } elseif (preg_match('/^(big|small|medium|tiny)?(int|integer|serial)/i', $type)) {
                $integer[$name] = $this->integerCeiling($type);
            } elseif (preg_match('/numeric|decimal|real|double|float|money/i', $type)) {
                // With the declared width where there is one. numeric(8,2) holds
                // six digits in front of the point and refuses a seventh, and a
                // restore meets that as an overflow rather than as a type error.
                // A float column declares no such thing and gets none.
                $numeric[$name] = preg_match('/\((\d+)(?:,\s*(\d+))?\)/', (string) ($column['type'] ?? ''), $width) === 1
                    && preg_match('/numeric|decimal/i', $type) === 1
                        ? ['precision' => (int) $width[1], 'scale' => (int) ($width[2] ?? 0)]
                        : null;
            } elseif (preg_match('/date|time/i', $type)) {
                // Which KIND of moment, and all three are different: a date
                // column keeps no clock, a time column keeps no calendar, and
                // only a timestamp keeps both. Collapsing any two of them makes
                // one value look like another that the column stores apart —
                // or the reverse.
                // Whether it carries a zone matters as much: PostgreSQL's
                // plain timestamp IGNORES an offset in its input, so the same
                // clock written in two zones is one value there — and two
                // different instants in a timestamptz.
                $zoned = preg_match('/tz|with time zone/i', $type) === 1;

                $temporal[$name] = match (true) {
                    preg_match('/stamp|datetime/i', $type) === 1 => $zoned ? 'timestamptz' : 'timestamp',
                    preg_match('/^time/i', $type) === 1 => $zoned ? 'timetz' : 'time',
                    default => 'date',
                };

                // And how much of a second it keeps. A timestamp(0) rounds .1
                // and .2 to the same stored second, so holding six digits for
                // it would miss the collision that stops the restore.
                $precision[$name] = preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $digits) === 1
                    ? min(6, max(0, (int) $digits[1]))
                    : 6;
            } elseif (preg_match('/json/i', $type)) {
                // PostgreSQL refuses anything that is not a JSON document here.
                // SQLite stores the same column as text and reports it as text,
                // so this classification simply finds nothing there — the check
                // is real in production and inert in the test suite.
                // WHICH of the two: jsonb keeps the document as text and
                // refuses a U+0000 escape that a plain json column stores
                // without complaint.
                $json[$name] = preg_match('/jsonb/i', $type) === 1 ? 'jsonb' : 'json';
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
            'limits' => $limits,
            'uuid' => $uuid,
            'boolean' => $boolean,
            'integer' => $integer,
            'numeric' => $numeric,
            'textual' => $textual,
            'temporal' => $temporal,
            'precision' => $precision,
            'json' => $json,
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
        return $this->decoded($value) === null;
    }

    /**
     * The value the restore would hand to the insert.
     *
     * A marker is not a value: {"__b64":"b29wcw=="} is a perfectly good array
     * to every reading of the JSON and reaches the column as the word "oops".
     * Everything that judges a value has to judge this one, or it judges the
     * envelope instead of what is in it.
     */
    private function decoded(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('__b64', $value)) {
            // Deliberately the same expression BackupRestorer::decodeRow() uses.
            return base64_decode((string) $value['__b64'], true) ?: null;
        }

        return $value;
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
     * @param  array<string, mixed>  $schema
     */
    private function keyValue(array $row, array $key, array $schema): ?string
    {
        $parts = [];

        foreach ($key as $column) {
            if (array_key_exists($column, $row)) {
                // Decoded, like everything else that judges a value: the same
                // key written once as 1 and once as {"__b64":"MQ=="} is one
                // value to the restore and would be two envelopes here.
                $value = $this->decoded($row[$column]);

                if ($value === null) {
                    return null;
                }

                $parts[] = $this->normalised($column, $value, $schema);

                continue;
            }

            $default = $schema['defaults'][$column] ?? null;

            // No default, or one the database COMPUTES per row — nextval(),
            // now(), a generated uuid. Those are not a constant every row
            // shares, and treating them as one would invent collisions.
            if ($default === null || str_contains($default, '(')) {
                return null;
            }

            $parts[] = $this->normalised($column, $this->literal($default), $schema);
        }

        return implode("\0", $parts);
    }

    /**
     * One key component in the spelling the DATABASE would compare.
     *
     * A bigint reads 1 and "01" as one value; as strings they are two, and the
     * collision that stops a restore would go unreported. The same holds one
     * decimal place down: 1.50 and 1.5 are one number and two spellings.
     *
     * @param  array<string, mixed>  $schema
     */
    /**
     * A decimal in the one form the database would compare it by, or null when
     * it is not a decimal at all.
     *
     * Never through a float. 9007199254740992 and 9007199254740993 are two
     * values a numeric column keeps apart and ONE binary float, so converting
     * would report a duplicate that is not one — the costlier way to be wrong.
     * Digits and an exponent instead: 1.50, 1.5 and 15e-1 come out alike
     * because the column holds them as one number, and nothing else does.
     */
    private function decimalText(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)
            || preg_match('/^([+-]?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', trim((string) $value), $parts) !== 1) {
            return null;
        }

        $digits = ltrim($parts[2].($parts[3] ?? ''), '0');
        $exponent = (int) ($parts[4] ?? 0) - strlen($parts[3] ?? '');

        // Zero is zero at any exponent, and has no sign worth keeping.
        if ($digits === '') {
            return '0';
        }

        // Trailing zeros are not part of the VALUE — the column reads 1.50 and
        // 1.5 as one number — so they move into the exponent instead.
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);

        return ($parts[1] === '-' ? '-' : '').$trimmed.'E'.$exponent;
    }

    /**
     * Whether a value fits a numeric column's declared precision and scale.
     *
     * PostgreSQL rounds the fraction to the declared scale and then refuses
     * whatever no longer fits in the digits it has — and the rounding itself
     * can push a value over, so the digits are rounded here before they are
     * counted, and by hand: a float would answer for a different number.
     */
    private function fitsPrecision(mixed $value, int $precision, int $scale): bool
    {
        if (! is_scalar($value) || is_bool($value)
            || preg_match('/^[+-]?(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', trim((string) $value), $parts) !== 1) {
            return true;
        }

        $digits = ltrim($parts[1].($parts[2] ?? ''), '0');
        $exponent = (int) ($parts[3] ?? 0) - strlen($parts[2] ?? '');

        // How many digits have to come off the right to leave exactly the
        // declared scale. Negative means zeros go on instead.
        $drop = -$scale - $exponent;

        if ($drop < -$precision) {
            return false;
        }

        if ($drop < 0) {
            $digits .= str_repeat('0', (int) -$drop);
        } elseif ($drop > 0) {
            $keep = $drop >= strlen($digits) ? '' : substr($digits, 0, -((int) $drop));
            $next = $drop <= strlen($digits) ? $digits[strlen($digits) - (int) $drop] : '0';
            $digits = $next >= '5' ? $this->carried($keep) : $keep;
        }

        return strlen(ltrim($digits, '0')) <= $precision;
    }

    /** One added to a string of digits, carrying as far as it has to. */
    private function carried(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }

            $digits[$i] = '0';
        }

        return '1'.$digits;
    }

    private function normalised(string $column, mixed $value, array $schema): string
    {
        if (! is_scalar($value)) {
            return (string) json_encode($value);
        }

        if (isset($schema['uuid'][$column]) && ($uuid = $this->uuidText($value)) !== null) {
            return $uuid;
        }

        if (isset($schema['temporal'][$column]) && is_string($value)
            && ($moment = $this->temporalText(
                $value,
                $schema['temporal'][$column],
                $schema['precision'][$column] ?? 6,
            )) !== null) {
            return $moment;
        }

        if (isset($schema['integer'][$column]) && ($whole = $this->integerText($value, PHP_INT_MAX)) !== null) {
            return $whole;
        }

        if (array_key_exists($column, $schema['numeric']) && ($decimal = $this->decimalText($value)) !== null) {
            return $decimal;
        }

        return (string) $value;
    }

    /**
     * Whether a date or timestamp column would take this value.
     *
     * Deliberately generous: what a backup writes is the database's own
     * rendering, and the question here is only whether the text is a moment in
     * time at all. Insisting on one format would fail archives written by a
     * different driver — while "not-a-date", the thing that actually stops a
     * restore, fails either way. PostgreSQL's own special literals are spelled
     * out because they are valid there and meaningless to strtotime() — each
     * with the kind of column it belongs to, since "allballs" is midnight to a
     * time column and nonsense to a date one.
     */
    private function readsAsTime(string $value, string $kind = 'date', int $precision = 6): bool
    {
        $text = $this->endOfDay($this->beforeEra(trim($value)), $kind);

        if ($text === '') {
            return false;
        }

        // The top of a time column's own range, which the parser also refuses.
        if (str_starts_with($kind, 'time') && ! str_contains($kind, 'stamp')
            && preg_match('/^24:00(:00(\.0+)?)?$/', $text) === 1) {
            return true;
        }

        // Everything PostgreSQL takes here. A date column accepts "now" and
        // "today" as readily as a timestamp does, and reporting one of them as
        // a bad value would fail an archive that restores perfectly well —
        // which is the costlier way to be wrong.
        $literals = str_starts_with($kind, 'time') && ! str_contains($kind, 'stamp')
            ? ['allballs', 'now']
            : ['infinity', '-infinity', 'epoch', 'now', 'today', 'tomorrow', 'yesterday'];

        if (in_array(strtolower($text), $literals, true)) {
            return true;
        }

        // date_parse rather than strtotime, which quietly rolls 2026-02-31
        // forward to the third of March and calls it a date. The database does
        // not: it refuses the row, which is the whole question being asked.
        $parsed = date_parse($this->narrowYear($text));

        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            return false;
        }

        // And the parts the column actually needs. "12:34:56" parses without a
        // complaint and is not a date; "2026-01-01" is not a time. A parser
        // that reports no fault has not said the value belongs here.
        if (str_starts_with($kind, 'time') && ! str_contains($kind, 'stamp')) {
            return is_int($parsed['hour']) && is_int($parsed['minute']) && is_int($parsed['second']);
        }

        if (! is_int($parsed['year']) || ! is_int($parsed['month']) || ! is_int($parsed['day'])) {
            return false;
        }

        // And inside the calendar the database keeps.
        if (! $this->yearInRange($text, $kind) || $parsed['year'] === 0) {
            return false;
        }

        // And still inside it once the value is STORED. A timestamp(0) given
        // 294276-12-31 23:59:59.9 rounds to the first instant of the next year,
        // and an offset can carry a day over either end: the literal is in
        // range and the value the column would hold is not. Asked at BOTH
        // edges, and only there, since nowhere else can a carry of a day or a
        // second leave the range.
        $literal = $this->literalYear($text);

        if ($literal !== $this->yearCeiling($kind) && $literal !== self::EARLIEST_YEAR) {
            return true;
        }

        // A value at the edge that cannot be worked out is reported, not
        // waved through. Everywhere else "not understood" is left alone,
        // because being wrong there costs a missed duplicate at most — here
        // it is the difference between a row that restores and one that does
        // not, and the drill has no business certifying what it could not
        // compute. A named zone lands here: PostgreSQL reads CET as +01 and
        // carries the earliest instant out of the calendar, and nothing here
        // can say which of the several CETs was meant.
        $stored = $this->temporalText($value, $kind, $precision);

        return $stored !== null && $this->yearInRange($stored, $kind);
    }

    /**
     * Whether a uuid column would take this value.
     *
     * Hyphens optional and braces tolerated, because the database accepts both
     * spellings and the question is what it would store, not how it was
     * written.
     */
    private function readsAsUuid(mixed $value): bool
    {
        return $this->uuidText($value) !== null;
    }

    /**
     * The one value a uuid column would store, or null when it would refuse it.
     *
     * The database reads {A1B2...}, a1b2... and the unhyphenated form as ONE
     * uuid, so a key written twice in two of those spellings is a duplicate the
     * restore will meet — and would be two different strings to anything that
     * compared the text. Validating and canonicalising are the same question,
     * and answering it in one place is what keeps them from disagreeing.
     */
    private function uuidText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        // Both delimiters or neither. A lone brace is not a wrapper, and the
        // database refuses it — stripping it here would have this agree with a
        // restore that does not.
        if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
            $text = substr($text, 1, -1);
        }

        if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $text) !== 1) {
            return null;
        }

        return strtolower(str_replace('-', '', $text));
    }

    /**
     * Whether a boolean column would take this value.
     *
     * The spellings are the database's own — it accepts t/f, yes/no and on/off
     * as readily as true/false — so anything a dump could plausibly have
     * written passes, and a word that is not one of them does not.
     */
    private function readsAsBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_int($value)) {
            return $value === 0 || $value === 1;
        }

        return is_string($value) && in_array(
            strtolower(trim($value)),
            ['0', '1', 't', 'f', 'true', 'false', 'yes', 'no', 'on', 'off'],
            true,
        );
    }

    /**
     * A moment in the one spelling the database would store it as, or null when
     * it cannot be read whole.
     *
     * "2026-01-01" and "Jan 1 2026" are one date to the column and would be two
     * keys to anything comparing text. Anything not fully understood is left
     * exactly as it was: merging two values this cannot read completely would
     * report a duplicate that is not one, and a wrong alarm costs more here
     * than a missed one. An offset, where the value carries one, is part of the
     * answer — two instants written in different zones are NOT the same moment.
     */
    private function temporalText(string $value, string $kind, int $precision = 6): ?string
    {
        $text = $this->endOfDay($this->beforeEra(trim($value)), $kind);

        if (str_starts_with($kind, 'time') && ! str_contains($kind, 'stamp')
            && preg_match('/^24:00(:00(\.0+)?)?$/', $text) === 1) {
            return '24:00:00.000000'.($kind === 'timetz' ? 'Z' : '');
        }

        // The literals the database understands and the parser does not. Left
        // as themselves they would be a second key for a value the column
        // already holds: "epoch" and "1970-01-01" are one date there.
        if (($literal = $this->literalMoment($text, $kind)) !== null) {
            return $literal;
        }

        $probe = $this->narrowYear($text);
        $parsed = date_parse($probe);

        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0 || ! $this->yearInRange($text, $kind)) {
            return null;
        }

        // The year as the archive spells it, and the one the date library will
        // actually be asked about. They differ only where the real one is out
        // of its reach, and then the stand-in shares its leap rule — so the
        // days are the same and the real year goes back on afterwards.
        $year = $this->literalYear($text) ?? (is_int($parsed['year']) ? $parsed['year'] : null);
        $stand = $this->literalYear($probe) ?? (is_int($parsed['year']) ? $parsed['year'] : null);

        // A column that carries a zone is answered as the INSTANT it names:
        // the same moment written in two zones is one value there, and two
        // different moments are two. A column without one ignores the offset
        // altogether, so keeping it would invent a difference the database
        // does not see.
        if (str_contains($kind, 'tz')) {
            // Only where the value SAYS which offset it means. Without one,
            // PostgreSQL reads it in the database session's zone — which this
            // process does not share and cannot ask for, so any instant built
            // here would be a guess, and a guess is how a canonical form comes
            // to invent duplicates. A named zone is out for the same reason
            // once the year is beyond what the library holds: it would be read
            // with the rules that zone has today.
            if (preg_match('/\d:\d{2}(:\d{2})?(\.\d+)?\s*(Z|[+-]\d{1,2}(:?\d{2})?)$/i', $text) !== 1) {
                return null;
            }

            $moment = rescue(fn (): \DateTimeImmutable => new \DateTimeImmutable($probe), null, report: false);

            if (! $moment instanceof \DateTimeImmutable) {
                return null;
            }

            // DateTimeImmutable truncates past six digits exactly as
            // date_parse does, so the rounded fraction — not just the whole
            // second it may carry — has to be put back.
            $utc = $this->toPrecision(
                $this->withMicroseconds($moment, $this->microseconds($text))
                    ->setTimezone(new \DateTimeZone('UTC')),
                $precision,
            );

            if ($kind === 'timetz') {
                return $utc->format('H:i:s.u').'Z';
            }

            if ($year === null || $stand === null) {
                return null;
            }

            return $this->dateText(
                $this->shiftYear($year, (int) $utc->format('Y') - $stand),
                (int) $utc->format('n'),
                (int) $utc->format('j'),
            ).$utc->format(' H:i:s.u').'Z';
        }

        // Fractional seconds are part of the value, not decoration: a column
        // with that precision stores .1 and .2 apart, and folding them together
        // would report a duplicate that is not one.
        $micro = $this->microseconds($text);
        $carry = intdiv($micro, 1000000);

        $clock = is_int($parsed['hour']) && is_int($parsed['minute']) && is_int($parsed['second'])
            ? sprintf(
                '%02d:%02d:%02d.%06d',
                $parsed['hour'],
                $parsed['minute'],
                $parsed['second'],
                $micro % 1000000,
            )
            : null;

        if ($kind === 'time') {
            if ($clock === null) {
                return null;
            }

            // Any date will do for a column that keeps none; rounding at the
            // very end of the last second wraps, as the column's own range
            // leaves nowhere else to go.
            $moment = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s.u',
                "2000-01-01 {$clock}",
                new \DateTimeZone('UTC'),
            );

            if ($moment === false) {
                return null;
            }

            return $this->toPrecision($moment->modify("+{$carry} seconds"), $precision)->format('H:i:s.u');
        }

        if ($year === null || $stand === null
            || ! is_int($parsed['month']) || ! is_int($parsed['day'])) {
            return null;
        }

        // A DATE column keeps no clock at all: the same day written with a time
        // beside it and without one is one value there, and would be two keys
        // to anything that kept what it was given.
        if ($kind === 'date') {
            return $this->dateText($year, $parsed['month'], $parsed['day']);
        }

        // Built on the REAL day, not a placeholder one: rounding 23:59:59.9 to
        // a whole second lands on the next DAY, and a carry that moved only the
        // clock would put it back on the old one — reading the stored value as
        // midnight of the wrong day.
        //
        // A timestamp with no clock is midnight, exactly as the column stores
        // it, so the two spellings of midnight come out as one key.
        $moment = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            sprintf('%04d-%02d-%02d ', $stand, $parsed['month'], $parsed['day'])
                .($clock ?? '00:00:00.000000'),
            new \DateTimeZone('UTC'),
        );

        if ($moment === false) {
            return null;
        }

        $rounded = $this->toPrecision($moment->modify("+{$carry} seconds"), $precision);

        return $this->dateText(
            $this->shiftYear($year, (int) $rounded->format('Y') - $stand),
            (int) $rounded->format('n'),
            (int) $rounded->format('j'),
        ).$rounded->format(' H:i:s.u');
    }

    /**
     * Whether the year this value names is one the database can hold.
     *
     * Read from the TEXT, because the parser does not keep an oversized one:
     * date_parse('294277-01-01') reports the year 1977 and no complaint at all,
     * so the number that arrives has already stopped being the number written.
     * There is no year zero either — 1 BC is followed by 1 AD — and PHP is
     * content to count one anyway.
     */
    /**
     * The last year this kind of column reaches.
     *
     * A date reaches far further than a timestamp does — 5874897 against
     * 294276 — and holding both to the narrower one would fault a date the
     * column stores perfectly well.
     */
    private function yearCeiling(string $kind): int
    {
        return $kind === 'date' ? 5874897 : 294276;
    }

    private function yearInRange(string $text, string $kind): bool
    {
        if (preg_match('/^\s*(-?\d+)-\d{1,2}-\d{1,2}/', $text, $year) !== 1) {
            return true;
        }

        $ceiling = $this->yearCeiling($kind);

        // Compared as text first: a year of forty digits is not an integer this
        // machine can hold, and casting it would quietly make it one.
        if (strlen(ltrim($year[1], '-0')) > strlen((string) $ceiling)) {
            return false;
        }

        return (int) $year[1] !== 0 && (int) $year[1] >= self::EARLIEST_YEAR && (int) $year[1] <= $ceiling;
    }

    /**
     * The year as the archive spells it, or null when the value does not open
     * with one.
     *
     * The parser cannot be asked: it reports 1977 for 294277 without
     * complaining, which is a real date the column can hold — so trusting it
     * would have two genuinely different years share one key.
     */
    private function literalYear(string $text): ?int
    {
        return preg_match('/^\s*(-?\d+)-\d{1,2}-\d{1,2}/', trim($text), $year) === 1
            && strlen(ltrim($year[1], '-0')) <= 7
                ? (int) $year[1]
                : null;
    }

    /**
     * Whether a jsonb document holds a number the column could not store.
     *
     * Every number in a jsonb document is kept as a numeric, which reaches
     * 131072 digits before the point and 16383 after; a plain json column keeps
     * the source text and takes anything that parses at all.
     *
     * Read from the TEXT, because decoding is where the answer is lost: PHP
     * hands back INF for 1e200000 and 0.0 for 1e-200000, and neither of those
     * is distinguishable afterwards from a number the column stores.
     */
    private function holdsUnstorableNumber(string $json): bool
    {
        foreach ($this->numberLiterals($json) as $literal) {
            if (! $this->fitsNumeric($literal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The number literals in a JSON document, as they were written.
     *
     * Scanned rather than matched, so that digits inside a STRING are not read
     * as numbers: a phone number in a text field is not something the column
     * has to store as one. The document has already been through
     * json_validate(), so the token boundaries here can be trusted.
     *
     * @return list<string>
     */
    private function numberLiterals(string $json): array
    {
        $numbers = [];
        $length = strlen($json);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($char === chr(92)) {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char !== '-' && ($char < '0' || $char > '9')) {
                continue;
            }

            $start = $i;

            while ($i + 1 < $length && str_contains('0123456789+-.eE', $json[$i + 1])) {
                $i++;
            }

            $numbers[] = substr($json, $start, $i - $start + 1);
        }

        return $numbers;
    }

    /**
     * Whether one number literal is inside PostgreSQL's numeric range.
     *
     * Counted in digits, never converted: the exponent itself may be larger
     * than this machine can hold, and casting it would quietly make it small.
     */
    private function fitsNumeric(string $literal): bool
    {
        if (preg_match('/^-?(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $literal, $parts) !== 1) {
            return true;
        }

        $fraction = strlen($parts[2] ?? '');

        // Measured from the first SIGNIFICANT digit of the whole significand,
        // wherever the point happens to fall in it: 0.0001e131075 is 1e131071
        // and fits, and counting the zeros in front of that 1 as magnitude
        // would fail an archive the column restores.
        //
        // Only the leading zeros go. A numeric holds the scale it was given, so
        // the zeros written AFTER the point are digits it stores — dropping
        // those would accept a number past the limit for being a round one.
        $significant = ltrim($parts[1].($parts[2] ?? ''), '0');

        // Beyond what an int holds the sign is all that matters, and it is
        // already far past either limit.
        $exponent = (float) ($parts[3] ?? 0);

        // A zero is a zero at any exponent and has no magnitude to overflow.
        // Its SCALE is another matter: the column keeps the places it was
        // written with, so 0. followed by too many zeros is still too long.
        $magnitude = $significant === ''
            ? 0.0
            : strlen($significant) + $exponent - $fraction;

        return $magnitude <= self::NUMERIC_WHOLE_DIGITS
            && $fraction - $exponent <= self::NUMERIC_FRACTION_DIGITS;
    }

    /**
     * Whether a decoded JSON document carries a zero byte anywhere inside it —
     * in a value or in a key.
     *
     * Asked of the DECODED document rather than of its text, because the two
     * disagree: "\\u0000" written with an escaped backslash is the literal
     * characters and stores perfectly well, while "\u0000" is the character
     * jsonb refuses. Only decoding tells them apart.
     */
    private function holdsZeroByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if ((is_string($key) && str_contains($key, "\0")) || $this->holdsZeroByte($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same value with a year the parser can hold, for the parser's benefit
     * alone.
     *
     * date_parse does not keep a year outside four digits, and — the part that
     * matters here — it validates the calendar against whatever it kept
     * instead. It reads 294300 as 2000 and accepts a February the 29th that
     * year does not have; it reads 10012 as 2002 and refuses one it does. Both
     * are wrong answers to a question about a real column.
     *
     * Standing a leap year in for a leap year and a common one for a common one
     * asks about the calendar the value is actually written in. The year itself
     * is read from the original text everywhere it is needed.
     */
    private function narrowYear(string $text): string
    {
        $year = $this->literalYear($text);

        if ($year === null || ($year >= 1 && $year <= 9999)) {
            return $text;
        }

        return preg_replace(
            '/^(\s*)-?\d+/',
            '${1}'.($this->daysInMonth($year, 2) === 29 ? '2000' : '2001'),
            $text,
            1,
        ) ?? $text;
    }

    /**
     * A year before the era in the one spelling everything here understands.
     *
     * PostgreSQL writes 1 BC as "0001-01-01 BC" and reads that back without
     * complaint; PHP's parser sees BC as an unknown timezone and calls the
     * whole value invalid — so an archive holding one was reported as
     * unrestorable when it restores perfectly.
     *
     * The suffix becomes a leading minus, which is the form the year checks
     * here already speak, so the two spellings of the same date also come out
     * as one key rather than two.
     */
    /**
     * A date in the one spelling everything here compares.
     *
     * A year before the era keeps its minus, and the width is fixed, so two
     * ways of writing the same day come out as one string.
     */
    private function dateText(int $year, int $month, int $day): string
    {
        return sprintf('%s%04d-%02d-%02d', $year < 0 ? '-' : '', abs($year), $month, $day);
    }

    /**
     * A year moved by whole years, through a calendar that has no year zero.
     *
     * The day after the last of 1 BC is the first of 1 AD, and the arithmetic
     * that says so is the same wherever a year is stepped — the end-of-day
     * rewrite and a rounding that carries past new year alike.
     */
    private function shiftYear(int $year, int $by): int
    {
        $era = ($year < 0 ? $year + 1 : $year) + $by;

        return $era <= 0 ? $era - 1 : $era;
    }

    private function beforeEra(string $text): string
    {
        return preg_match('/^(\d.*?)\s+BC\s*$/i', $text, $parts) === 1
            ? '-'.$parts[1]
            : $text;
    }

    /**
     * The end-of-day spelling as the moment it names.
     *
     * PostgreSQL takes 24:00:00 and stores the following midnight; PHP's parser
     * calls it an invalid time. Rewritten before anything else looks at it, so
     * a value the restore accepts is not reported as a broken one.
     *
     * A time column is left alone: 24:00:00 is the top of its own range there
     * and stays exactly that.
     *
     * The year is read at whatever width it is written, and the day advanced by
     * arithmetic rather than through the date library: that library holds 294276
     * as 1976, so it would answer both the calendar question and the increment
     * for a different year entirely.
     */
    private function endOfDay(string $text, string $kind): string
    {
        if ($kind === 'time' || $kind === 'timetz'
            || preg_match('/^(-?\d{1,7})-(\d{1,2})-(\d{1,2})[ T]24:00(:00(\.0+)?)?(.*)$/', $text, $parts) !== 1) {
            return $text;
        }

        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];

        // The date this is about to advance must be a real one FIRST. February
        // the 31st normalises to the third of March on its way through a date
        // library, and a day added to that is a perfectly ordinary timestamp —
        // the calendar error erased by the very rewrite meant to help.
        if ($year === 0 || $month < 1 || $month > 12
            || $day < 1 || $day > $this->daysInMonth($year, $month)) {
            return $text;
        }

        if ($day < $this->daysInMonth($year, $month)) {
            $day++;
        } elseif ($month < 12) {
            $month++;
            $day = 1;
        } else {
            $year = $this->shiftYear($year, 1);
            $month = 1;
            $day = 1;
        }

        return $this->dateText($year, $month, $day).' 00:00:00'.$parts[6];
    }

    /**
     * How many days a month has in the proleptic Gregorian calendar.
     *
     * Worked out here rather than asked of the date library, which cannot hold
     * the years a date column reaches: it reads 294300 as 4300 and would answer
     * the leap question for that instead.
     */
    private function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            // A year before the era is written as its count, not as an
            // astronomical number: 1 BC is -1 here and 0 in the arithmetic the
            // leap rule is stated in, so 1 BC is a leap year and -1 is not.
            $era = $year < 0 ? $year + 1 : $year;

            return $era % 4 === 0 && ($era % 100 !== 0 || $era % 400 === 0) ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * A special literal as the value the column stores for it, or null when the
     * text is not one this kind of column accepts.
     *
     * "now" is deliberately absent: it is not a constant — it resolves when the
     * insert runs — so nothing here can say which stored value it equals.
     * Infinity keeps its own name, lowercased, because it has no calendar form
     * and must still compare equal to itself written another way.
     */
    private function literalMoment(string $text, string $kind): ?string
    {
        return match (strtolower($text)) {
            'epoch' => match ($kind) {
                'date' => '1970-01-01',
                'timestamp' => '1970-01-01 00:00:00.000000',
                'timestamptz' => '1970-01-01 00:00:00.000000Z',
                default => null,
            },
            'allballs' => match ($kind) {
                'time' => '00:00:00.000000',
                'timetz' => '00:00:00.000000Z',
                default => null,
            },
            'infinity' => str_contains($kind, 'time') || $kind === 'date' ? 'infinity' : null,
            '-infinity' => str_contains($kind, 'time') || $kind === 'date' ? '-infinity' : null,
            default => null,
        };
    }

    /**
     * The value's fraction in microseconds, which may round up to a whole
     * second — the caller carries it.
     *
     * Taken from the TEXT rather than from date_parse, which truncates past six
     * digits: .9999999 is a microsecond short of the next second to the parser
     * and exactly the next second to the database.
     *
     * And rounded from the DIGITS, never through a float. .5168455 is exactly
     * half a microsecond and becomes 516845.49999999994 the moment it is
     * multiplied as a binary fraction — rounding down where the database rounds
     * up. The seventh digit decides it here, which is what the database does.
     *
     * The fraction is looked for after the seconds on purpose: a date written
     * with dots carries digits after a full stop too, and they are not a
     * fraction of anything.
     */
    private function microseconds(string $text): int
    {
        if (preg_match('/:\d{1,2}\.(\d+)/', $text, $digits) !== 1) {
            return 0;
        }

        $decimals = str_pad(substr($digits[1], 0, 7), 7, '0');

        return (int) substr($decimals, 0, 6) + ((int) $decimals[6] >= 5 ? 1 : 0);
    }

    /**
     * The same moment with its fraction rounded to what the column keeps.
     *
     * Rounded, not truncated, because that is what the database does — and the
     * carry is done by the date library rather than by hand, since rounding up
     * at .999999 crosses into the next second, and from there into the next
     * minute, hour and day.
     */
    private function toPrecision(\DateTimeImmutable $moment, int $precision): \DateTimeImmutable
    {
        if ($precision >= 6) {
            return $moment;
        }

        $scale = 10 ** (6 - $precision);

        return $this->withMicroseconds($moment, (int) round((int) $moment->format('u') / $scale) * $scale);
    }

    /**
     * The same moment with this many microseconds, carrying a whole second (and
     * with it a minute, an hour, a day) when the count has rounded past one.
     */
    private function withMicroseconds(\DateTimeImmutable $moment, int $micro): \DateTimeImmutable
    {
        $carried = $moment->modify('+'.intdiv($micro, 1000000).' seconds');

        return $carried->setTime(
            (int) $carried->format('H'),
            (int) $carried->format('i'),
            (int) $carried->format('s'),
            $micro % 1000000,
        );
    }

    /** Whether an integer column would take this value. */
    private function fitsInteger(mixed $value, int $ceiling): bool
    {
        return $this->integerText($value, $ceiling) !== null;
    }

    /**
     * The largest number this integer column can hold.
     *
     * The width is only decidable together with the driver: PostgreSQL names
     * int2, int4 and int8 apart, while SQLite reports every integer column as
     * "integer" whatever it was declared as. Guessing 32 bits there would fail
     * legitimate values on the driver the tests run on, so an ambiguous name is
     * read as the widest — a missed overflow rather than a false alarm.
     */
    private function integerCeiling(string $type): int
    {
        if (preg_match('/int2|smallint/i', $type) === 1) {
            return 32767;
        }

        if (preg_match('/int8|bigint/i', $type) === 1) {
            return PHP_INT_MAX;
        }

        return preg_match('/int4|mediumint|^int(eger)?$/i', $type) === 1
            && DB::getDriverName() !== 'sqlite'
                ? 2147483647
                : PHP_INT_MAX;
    }

    /**
     * The one spelling an integer column would store this value as, or null
     * when it would refuse it altogether.
     *
     * Both questions are answered here on purpose. "01" and 1 are one value to
     * a bigint, so a duplicate written both ways has to compare equal — and a
     * separate validator that read "01" as malformed would raise a false alarm
     * over a row the restore takes without blinking. One function, and the two
     * cannot disagree.
     *
     * The range is the 64-bit one, and deliberately NOT the declared width: on
     * SQLite every integer column reports as "integer" whatever it was declared
     * as, so a smallint check would fail legitimate values on the driver the
     * tests themselves run on. A wrong alarm costs more here than a missed one.
     */
    private function integerText(mixed $value, int $ceiling = PHP_INT_MAX): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // Everything becomes decimal text and then takes the SAME path: the
        // range is checked once, at the end, for every kind of input. Checked
        // per branch it was checked for some of them — an integer straight out
        // of the JSON skipped it entirely, which is exactly the value a
        // too-wide number arrives as.
        if (is_float($value)) {
            if ($value !== floor($value) || abs($value) > PHP_INT_MAX) {
                return null;
            }

            $value = (string) (int) $value;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\s*[+-]?\d+\s*$/', $value) !== 1) {
            return null;
        }

        $text = trim($value);
        $sign = $text[0] === '-' ? '-' : '';
        $digits = ltrim(ltrim($text, '+-'), '0');
        $digits = $digits === '' ? '0' : $digits;

        // Compared as text, because the number may not fit in an int to begin
        // with — which is itself the answer. One more is allowed on the
        // negative side, as every two's-complement range has; spelled out at
        // the top of the range because PHP_INT_MAX + 1 is no longer an integer.
        $limit = match (true) {
            $sign !== '-' => (string) $ceiling,
            $ceiling === PHP_INT_MAX => '9223372036854775808',
            default => (string) ($ceiling + 1),
        };

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        return $digits === '0' ? '0' : $sign.$digits;
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
     * @return array{rows: int, damaged: int, unknown: list<string>, absent: list<string>, nulls: list<string>, mistyped: list<string>, unstorable: list<string>, repeated: list<string>, values: array<string, array<string, bool>>, unchecked: bool, mixed: bool, corrupt: bool}
     */
    private function readRows($stream, ?array $schema, array $watch = []): array
    {
        $rows = 0;
        $damaged = 0;
        $unknown = [];
        $absent = [];
        $nulls = [];
        $mistyped = [];
        $unstorable = [];
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

        $count = function (string $line) use (&$rows, &$damaged, &$unknown, &$absent, &$nulls, &$mistyped, &$unstorable, &$repeated, &$values, &$unchecked, &$seen, &$mixed, &$accepted, &$noNulls, $schema, $watch): void {
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

                // The DECODED value, not the marker around it: a b64 marker is
                // an array to every reading of the JSON and reaches the column
                // as the bytes inside it.
                $value = $this->decoded($value);

                // An empty value is not a badly typed one. Whether the column
                // may hold it is the NULL check's question, and answering it
                // here as well would put one fault in the report twice.
                if ($value === null) {
                    continue;
                }

                // Bytes no TEXT column can hold: a zero byte, which PostgreSQL
                // stores in no text value at all, and anything the connection's
                // encoding cannot read. Both pass every check below this one as
                // perfectly ordinary short strings, and the insert is where
                // they stop — the shape of fault this drill exists to catch.
                //
                // Asked of columns the schema says hold text, not of every
                // string that arrives: a bytea column holds either quite
                // happily, and its bytes travel as an ordinary JSON string
                // whenever they happen to be valid UTF-8.
                if (isset($schema['textual'][$column])
                    && is_string($value)
                    && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                    $unstorable[(string) $column] = true;

                    continue;
                }

                // Longer than the column is wide. Counted in characters, as
                // the database counts it, not in bytes.
                if (isset($schema['limits'][$column])
                    && is_string($value)
                    && mb_strlen($value) > $schema['limits'][$column]) {
                    $mistyped[(string) $column] = true;

                    continue;
                }

                if (isset($schema['uuid'][$column])) {
                    if (! $this->readsAsUuid($value)) {
                        $mistyped[(string) $column] = true;
                    }

                    continue;
                }

                if (isset($schema['boolean'][$column])) {
                    if (! $this->readsAsBoolean($value)) {
                        $mistyped[(string) $column] = true;
                    }

                    continue;
                }

                if (isset($schema['integer'][$column])) {
                    if (! $this->fitsInteger($value, $schema['integer'][$column])) {
                        $mistyped[(string) $column] = true;
                    }

                    continue;
                }

                if (array_key_exists((string) $column, $schema['numeric'])) {
                    if (is_string($value) && ! is_numeric($value)) {
                        $mistyped[(string) $column] = true;

                        continue;
                    }

                    // And inside the width the column declares. An unquoted
                    // 1000000 in a numeric(8,2) is a perfectly good number and
                    // still overflows the field — which the restore meets as an
                    // error, and every check before this one reads as fine.
                    $width = $schema['numeric'][(string) $column];

                    if ($width !== null && ! $this->fitsPrecision($value, $width['precision'], $width['scale'])) {
                        $mistyped[(string) $column] = true;
                    }

                    continue;
                }

                // A number is not a moment in time to a date column, whatever
                // it would mean elsewhere — the restore is handed the value as
                // it stands and the column refuses it.
                if (isset($schema['temporal'][$column])
                    && (! is_string($value) || ! $this->readsAsTime(
                        $value,
                        $schema['temporal'][$column],
                        $schema['precision'][$column] ?? 6,
                    ))) {
                    $mistyped[(string) $column] = true;

                    continue;
                }

                if (isset($schema['json'][$column]) && is_string($value)) {
                    if (! json_validate($value)) {
                        $mistyped[(string) $column] = true;

                        continue;
                    }

                    // Valid JSON is not the whole question for jsonb: it keeps
                    // the document as TEXT, and PostgreSQL has no text that can
                    // hold U+0000. The byte check above cannot see this one —
                    // inside the archive the escape is six characters, not a
                    // byte — and a plain json column takes it quite happily.
                    if ($schema['json'][$column] === 'jsonb'
                        && ($this->holdsZeroByte(json_decode($value, true))
                            || $this->holdsUnstorableNumber($value))) {
                        $unstorable[(string) $column] = true;
                    }
                }
            }

            foreach ($schema['unique'] as $key) {
                if ($this->keyBudget <= 0) {
                    $unchecked = true;

                    break;
                }

                $value = $this->keyValue($row, $key, $schema);

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

                $value = $this->keyValue($row, $columns, $schema);

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
                        'unstorable' => [],
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
            'unstorable' => array_keys($unstorable),
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
