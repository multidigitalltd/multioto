<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Print (as JSON) the changelog releases that exist in an INCOMING build but not
 * in the currently-running one — i.e. the highlights of a pending update.
 *
 * The changelog ships inside each build, so a running (older) build can't know
 * what a newer version adds. The host deploy watcher fetches the incoming
 * `config/changelog.json` from git and feeds it here; the result is embedded in
 * `available.json` so the panel can show "why upgrade" BEFORE installing.
 *
 * The incoming file is parsed as JSON and NEVER executed — it comes from an
 * unreviewed branch (checked a minute before any admin approves the update), so
 * treating it as data, not code, is essential.
 *
 * Deploy-context only (invoked by the host watcher, never from an HTTP request).
 */
class ChangelogDiffCommand extends Command
{
    protected $signature = 'app:changelog-diff {incoming : Path to the incoming config/changelog.json}';

    protected $description = 'Output (JSON) the changelog releases new in an incoming build vs the running one';

    public function handle(): int
    {
        $path = (string) $this->argument('incoming');

        if (! is_file($path)) {
            $this->line('[]');

            return self::SUCCESS;
        }

        $currentVersions = collect((array) config('changelog.releases'))
            ->pluck('version')
            ->filter()
            ->all();

        $new = collect($this->incomingReleases($path))
            ->filter(fn ($r): bool => is_array($r)
                && filled($r['version'] ?? null)
                && ! in_array($r['version'], $currentVersions, true))
            ->map(fn (array $r): array => [
                'version' => (string) $r['version'],
                'title' => (string) ($r['title'] ?? ''),
                'date' => (string) ($r['date'] ?? ''),
                'highlights' => array_values(array_map('strval', (array) ($r['highlights'] ?? []))),
            ])
            ->values()
            ->all();

        $this->line((string) json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * The releases list from the incoming changelog file, decoded as JSON. The
     * file comes from an unreviewed branch, so it is parsed as pure data and
     * NEVER executed — a compromised or accidental payload can only produce
     * invalid JSON (→ empty list), never run code.
     *
     * @return array<int, mixed>
     */
    private function incomingReleases(string $path): array
    {
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
