<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps an inbound attachment (a file a customer sent on WhatsApp or email) on a
 * private disk, and says how dangerous it is.
 *
 * ## Everything is kept, and labelled
 *
 * This used to be an allow-list that refused anything unfamiliar, and it threw
 * away real work: every legacy .doc a bookkeeper sends, every forwarded email,
 * every archive of logs. The team's answer to that is the right one — a support
 * inbox exists to receive what customers actually send, and the person looking
 * at the ticket is better placed to judge a file than a list written months ago.
 *
 * So nothing is refused for its type. What replaces the refusal is a verdict:
 * every file carries a risk level and, when it is not plainly safe, a sentence
 * saying why, shown beside it before anybody clicks. An executable is kept and
 * marked as an executable.
 *
 * ## What still protects us, because labelling is not a control
 *
 * Two things, and they are the reason accepting everything is safe to do:
 *
 *  1. **Nothing is ever written under an extension our own server would run.**
 *     The stored extension comes from our map, or from a sanitised copy of the
 *     sender's, and anything web-interpretable (.php, .html, .svg, .js…) lands
 *     as ".bin". The sender's real filename survives as display metadata and is
 *     what the download is named, so the team loses nothing.
 *  2. **Nothing risky is ever served inline.** SupportAttachmentController
 *     renders only a fixed list of non-scripting media in the browser; every
 *     other file is forced to download as application/octet-stream with
 *     `nosniff`. That is what stops a stored .svg or .html becoming script on
 *     the panel's own origin — which, now that we keep them, is the one way
 *     accepting everything could have hurt us.
 *
 * The only refusals left are a file that arrived empty and a file over the size
 * cap, and both are recorded on the message rather than dropped.
 */
class AttachmentStore
{
    /** Ordinary: a document, a picture, a recording. No warning shown. */
    public const RISK_SAFE = 'safe';

    /** An archive — we cannot see what is inside it. */
    public const RISK_ARCHIVE = 'archive';

    /** An Office file that can carry macros. */
    public const RISK_MACRO = 'macro';

    /** Runs in a browser: HTML, SVG, scripts. */
    public const RISK_ACTIVE = 'active';

    /** A program. The one a person must not double-click to "have a look". */
    public const RISK_EXECUTABLE = 'executable';

    /**
     * Sniffs that name a container rather than a format.
     *
     * Each is what libmagic says when it recognised the wrapper and nothing
     * about the contents: OLE2 holds .doc/.xls/.ppt/.msg, a zip holds every
     * OOXML and OpenDocument file, and octet-stream means it gave up. None of
     * them is a reason to disbelieve the sender's declared type.
     */
    private const GENERIC_SNIFFS = [
        'application/octet-stream',
        'application/x-ole-storage',
        'application/CDFV2',
        'application/CDFV2-corrupt',
        'application/vnd.ms-office',
        'application/zip',
        'application/x-zip-compressed',
    ];

    /**
     * Extensions this system must never write, whatever the file is.
     *
     * Not about the customer's machine — about ours. A ".php" on a disk that is
     * one misconfiguration away from the webroot is a remote shell; a ".html" is
     * stored XSS the day somebody serves the directory. The file is still kept,
     * under ".bin", and still downloads under its real name.
     */
    private const UNSAFE_ON_DISK = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'js', 'mjs', 'cjs',
        'htaccess', 'htpasswd', 'ini', 'conf',
        'exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'vbs', 'vbe', 'wsf', 'ps1', 'psm1',
        'jar', 'msi', 'apk', 'app', 'dmg', 'deb', 'rpm', 'dll', 'so', 'dylib',
    ];

    /** Sniffed types that are a program. */
    private const EXECUTABLE_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/vnd.microsoft.portable-executable',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-elf', 'application/x-msi', 'application/vnd.android.package-archive',
        'application/java-archive', 'application/x-apple-diskimage',
    ];

    /** Sniffed types that execute in a browser or a shell. */
    private const ACTIVE_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-php', 'application/x-php', 'text/x-shellscript', 'text/x-python',
        'text/x-perl', 'text/x-ruby', 'application/x-msdos-program',
    ];

    /** Sniffed types whose contents we cannot see. */
    private const ARCHIVE_MIMES = [
        'application/zip', 'application/x-zip-compressed', 'application/x-7z-compressed',
        'application/x-rar', 'application/vnd.rar', 'application/gzip', 'application/x-gzip',
        'application/x-tar', 'application/x-bzip2', 'application/x-xz', 'application/vnd.ms-cab-compressed',
    ];

    /** Extensions that mean "this program runs", whatever the bytes sniffed as. */
    private const EXECUTABLE_EXTENSIONS = [
        'exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'msi', 'apk', 'app', 'dmg',
        'deb', 'rpm', 'dll', 'so', 'jar', 'vbs', 'vbe', 'wsf', 'ps1', 'sh', 'bash',
    ];

    /** Office extensions that carry macros. */
    private const MACRO_EXTENSIONS = ['docm', 'xlsm', 'pptm', 'xlsb', 'dotm', 'xltm', 'potm', 'doc', 'xls', 'ppt'];

    /**
     * Containers that hold a macro-capable Office file.
     *
     * A generic sniff is not evidence of a FORMAT, but it is evidence of a
     * container — and this particular container is the one legacy Office macros
     * live in, whatever the sender chose to call the file.
     */
    private const MACRO_CONTAINERS = [
        'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt',
        'application/vnd.ms-office',
    ];

    /**
     * Store an attachment, or refuse it with a reason.
     *
     * @param  string|null  $reason  set to why it was refused, for the caller to
     *                               record — a refusal nobody can see is the
     *                               same as a file quietly disappearing
     * @return array{name: string, mime: string, size: int, path: string, disk: string, risk: string, warning: ?string}|null
     */
    public function store(int $ticketId, string $filename, string $contents, ?string $declaredMime = null, ?string &$reason = null): ?array
    {
        $verdict = $this->inspect($filename, $contents, $declaredMime);
        $reason = $verdict['reason'];

        return $verdict['ok'] ? $this->write($ticketId, $verdict, $contents) : null;
    }

    /**
     * Decide what this file is, how risky it is, and whether it can be kept at
     * all — without writing anything.
     *
     * Separate from the write so a caller can know the verdict BEFORE the ticket
     * exists: the storage path needs a ticket id, and a refusal notice has to be
     * part of the message the team is alerted about.
     *
     * @return array{ok: bool, name: string, mime: ?string, extension: ?string, size: int, risk: string, warning: ?string, reason: ?string}
     */
    public function inspect(string $filename, string $contents, ?string $declaredMime = null): array
    {
        $max = (int) config('billing.support.attachments.max_bytes');
        $size = strlen($contents);

        if ($size === 0) {
            return $this->refusal($filename, $size, 'הקובץ הגיע ריק.');
        }

        // The one limit left, and it is about our own disk rather than about
        // trust. Said with both numbers so the team can tell the customer what
        // would fit.
        if ($size > $max) {
            return $this->refusal($filename, $size, sprintf('הקובץ גדול מהמותר (%s, המקסימום %s).',
                $this->humanSize($size), $this->humanSize($max)));
        }

        // Sniffed separately and kept, not folded into the resolved type: the
        // resolved one is for naming the file and the sniff is evidence about
        // what is in it, and those two part ways exactly when somebody renamed
        // something. classify() needs both.
        $sniffed = $this->sniff($contents);
        $mime = $this->resolveMime($sniffed, $declaredMime);
        $extension = $this->safeExtension($mime, $filename);
        [$risk, $warning] = $this->classify($mime, $sniffed, $filename);

        return [
            'ok' => true,
            // The sender's own name — kept, because it is what the team will ask
            // them about and what the download is called. Its extension is
            // corrected only when we positively identified the type and know a
            // different one for it: PNG bytes called "invoice.php" are a PNG, and
            // showing ".php" there would invent a danger that is not in the file.
            // A type we could NOT identify keeps the sender's extension exactly,
            // so a ".exe" is never softened into something reassuring.
            'name' => $this->displayName($filename, $this->nameExtension($mime, $extension)),
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'risk' => $risk,
            'warning' => $warning,
            'reason' => null,
        ];
    }

    /**
     * Write bytes that inspect() approved.
     *
     * @param  array{name: string, mime: ?string, extension: ?string, size: int, risk: string, warning: ?string}  $verdict
     * @return array{name: string, mime: string, size: int, path: string, disk: string, risk: string, warning: ?string}
     */
    public function write(int $ticketId, array $verdict, string $contents): array
    {
        $disk = (string) config('billing.support.attachments.disk');
        $path = sprintf('attachments/%d/%s.%s', $ticketId, (string) Str::uuid(), $verdict['extension']);

        Storage::disk($disk)->put($path, $contents);

        return [
            'name' => $verdict['name'],
            'mime' => (string) $verdict['mime'],
            'size' => (int) $verdict['size'],
            'path' => $path,
            'disk' => $disk,
            'risk' => (string) $verdict['risk'],
            'warning' => $verdict['warning'],
        ];
    }

    /**
     * What to keep on the message for a file we could not store.
     *
     * Carries no path on purpose: there is no file. The attachment route already
     * 404s on a path-less row, and the conversation view renders it as a name
     * with the reason rather than a link.
     *
     * @param  array{name: string, size: int, reason: ?string}  $verdict
     * @return array{name: string, size: int, rejected: string}
     */
    public function refusalRecord(array $verdict): array
    {
        return [
            'name' => $verdict['name'],
            'size' => (int) $verdict['size'],
            'rejected' => (string) ($verdict['reason'] ?? 'הקובץ לא נשמר.'),
        ];
    }

    /**
     * What this file is.
     *
     * The bytes are sniffed, and that is what we normally trust. But a sniff
     * answering "application/octet-stream" or "application/x-ole-storage" has
     * identified a container, not a format — and letting such an answer override
     * the sender's declared type is what used to lose every legacy .doc and turn
     * every .docx into a ".zip". An uninformative sniff is not evidence, so the
     * declared type is used instead.
     *
     * An INFORMATIVE sniff always wins, including over a declaration that
     * disagrees. Not to refuse the file any more — nothing is refused for its
     * type — but because the risk label has to describe the bytes. A program
     * declared "application/pdf" must be labelled a program.
     */
    protected function resolveMime(?string $sniffed, ?string $declaredMime): string
    {
        $declared = $this->normaliseMime($declaredMime);

        if ($sniffed !== null && ! in_array($sniffed, self::GENERIC_SNIFFS, true)) {
            return $sniffed;
        }

        return $declared ?? $sniffed ?? 'application/octet-stream';
    }

    /**
     * How dangerous this is, in one word and one sentence.
     *
     * Read from the bytes AND from the sender's extension, and the worse of the
     * two wins. The extension is untrustworthy for deciding what a file IS, but
     * it is perfectly good evidence for deciding to be careful: a ".exe" whose
     * bytes sniffed as an anonymous blob is still something nobody should open.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function classify(string $mime, ?string $sniffed, string $filename): array
    {
        $extension = $this->senderExtension($filename);
        // Both types, because they can disagree and the disagreement is the
        // interesting case. A zip named "invoice.pdf" and declared as a PDF
        // reads as an ordinary document from the name and the declaration, and
        // only the sniff knows it is an archive — dropping it here would lose
        // the warning for exactly the files somebody renamed on purpose.
        $types = array_filter([$mime, $sniffed]);

        $risks = [];

        if ($this->anyIn($types, self::EXECUTABLE_MIMES) || in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
            $risks[] = [self::RISK_EXECUTABLE,
                'קובץ הפעלה. אל תפתחו אותו — הורידו רק אם אתם יודעים בוודאות מה זה ומי שלח.'];
        }

        if ($this->anyIn($types, self::ACTIVE_MIMES) || in_array($extension, ['html', 'htm', 'svg', 'js', 'vbs', 'hta'], true)) {
            $risks[] = [self::RISK_ACTIVE,
                'מכיל קוד שמופעל בדפדפן. נפתח רק כהורדה, ולא מוצג כאן.'];
        }

        // The OLE2 container IS the thing that carries legacy Office macros, so
        // a sniff that names it is macro evidence even when the filename says
        // something else entirely.
        if (in_array($extension, self::MACRO_EXTENSIONS, true) || $this->anyIn($types, self::MACRO_CONTAINERS)) {
            $risks[] = [self::RISK_MACRO,
                'קובץ Office שעשוי להכיל מאקרו. פתחו בתצוגה מוגנת ואל תאשרו הפעלת תוכן.'];
        }

        if ($this->anyIn($types, self::ARCHIVE_MIMES)) {
            $risks[] = [self::RISK_ARCHIVE,
                'ארכיון — לא בדקנו מה בתוכו. סרקו לפני פתיחה.'];
        }

        // Severity order, not order of discovery: a file that is both an archive
        // and an executable is an executable, and that is the sentence to show.
        foreach ([self::RISK_EXECUTABLE, self::RISK_ACTIVE, self::RISK_MACRO, self::RISK_ARCHIVE] as $level) {
            foreach ($risks as $risk) {
                if ($risk[0] === $level) {
                    return $risk;
                }
            }
        }

        return [self::RISK_SAFE, null];
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $haystack
     */
    private function anyIn(array $types, array $haystack): bool
    {
        return array_intersect($types, $haystack) !== [];
    }

    /**
     * The extension to write on disk — ours, never the sender's judgement.
     *
     * Known types keep their proper extension (a .pdf stays a .pdf, a .docx
     * stays a .docx) because it is useful and harmless. Everything else takes a
     * sanitised copy of the sender's, and anything our own server could
     * interpret becomes ".bin". The real filename is kept as display metadata,
     * so this costs the team nothing.
     */
    protected function safeExtension(string $mime, string $filename): string
    {
        $map = (array) config('billing.support.attachments.allowed_mimes', []);
        $original = $this->senderExtension($filename);

        // Many text formats (csv, tsv, ics, vcf, log…) all sniff as text/plain,
        // so a customer's ".csv" would otherwise land as ".txt". Widened only
        // for this family and only to a fixed list, so a "shell.php" full of
        // plain text is still written as ".txt".
        if ($mime === 'text/plain') {
            return in_array($original, ['txt', 'csv', 'tsv', 'log', 'md', 'vcf', 'ics'], true)
                ? $original
                : 'txt';
        }

        // A type we recognise keeps its proper extension.
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        // One we do not: a sanitised copy of the sender's, unless our own server
        // could interpret it.
        $extension = $original !== '' ? $original : 'bin';

        return in_array($extension, self::UNSAFE_ON_DISK, true) ? 'bin' : $extension;
    }

    /** The sender's extension, reduced to something safe to compare and reuse. */
    protected function senderExtension(string $filename): string
    {
        $extension = (string) preg_replace('/[^a-z0-9]/', '',
            strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)));

        return strlen($extension) <= 8 ? $extension : '';
    }

    /** Real MIME from the file contents (null when finfo is unavailable). */
    protected function sniff(string $contents): ?string
    {
        if (! class_exists(\finfo::class)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /**
     * A declared Content-Type reduced to the type itself.
     *
     * Headers arrive as `text/csv; charset=utf-8`, and comparing that against a
     * map matches nothing — so the declared type would be ignored for exactly
     * the files that depend on it.
     */
    protected function normaliseMime(?string $declared): ?string
    {
        if (! is_string($declared)) {
            return null;
        }

        $type = trim(Str::before($declared, ';'));

        return $type !== '' ? $type : null;
    }

    /**
     * A safe display name: the sender's filename stripped of any path, kept
     * short, with its own extension intact. Display only — never the stored
     * path, so an "invoice.php" here is a label and nothing more.
     */
    protected function displayName(string $filename, ?string $enforcedExtension = null): string
    {
        $clean = Str::of(basename(str_replace('\\', '/', $filename)))
            ->replaceMatches('/[^\p{L}\p{N}._ -]/u', '')
            ->trim()
            ->limit(120, '')
            ->value();

        if ($clean === '' || $clean === '.') {
            $clean = 'קובץ';
        }

        if ($enforcedExtension === null) {
            return $clean;
        }

        $base = Str::of($clean)->beforeLast('.')->trim()->value();

        return ($base !== '' ? $base : 'קובץ').'.'.$enforcedExtension;
    }

    /**
     * The extension the DISPLAY name should carry, or null to keep the sender's.
     *
     * Only for a type we identified and have a name for. Everything else keeps
     * what the sender wrote, which is the honest answer and the cautious one.
     */
    protected function nameExtension(string $mime, string $storedExtension): ?string
    {
        if ($mime === 'text/plain') {
            return $storedExtension;
        }

        return ((array) config('billing.support.attachments.allowed_mimes', []))[$mime] ?? null;
    }

    /** @return array{ok: bool, name: string, mime: ?string, extension: ?string, size: int, risk: string, warning: ?string, reason: string} */
    private function refusal(string $filename, int $size, string $reason): array
    {
        return [
            'ok' => false,
            'name' => $this->displayName($filename),
            'mime' => null,
            'extension' => null,
            'size' => $size,
            'risk' => self::RISK_SAFE,
            'warning' => null,
            'reason' => $reason,
        ];
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
