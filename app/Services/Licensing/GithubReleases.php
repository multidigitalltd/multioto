<?php

namespace App\Services\Licensing;

use App\Models\PluginProduct;
use App\Models\PluginRelease;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turn releases published on the development repository into releases here.
 *
 * Shipping a version becomes tagging one, and nobody drags a zip into a form —
 * which is the point, because the zip that gets dragged in is the zip somebody
 * built on their laptop at eleven at night.
 *
 * What is taken from a GitHub release, in order:
 *
 *  1. **An attached zip.** This is the right answer: a build produced by CI,
 *     structured exactly as WordPress will install it. When several are
 *     attached, the one whose name matches the plugin's slug wins.
 *
 *  2. **The source archive, repacked** — only when the product explicitly asks
 *     for it. GitHub's own source zip nests everything under `repo-1.2.3/`,
 *     which WordPress would install as a folder by that name and then fail to
 *     update ever again, so it is unpacked and rebuilt under the slug. It also
 *     contains everything else in the repository, and that is why this is off
 *     unless somebody turned it on.
 *
 * A release that offers neither is not imported and says why. Importing
 * something wrong here means installing it on every customer's shop.
 */
class GithubReleases
{
    private const API = 'https://api.github.com';

    /** Releases read per sync. More than this is history nobody is going to ship. */
    private const LOOK_BACK = 10;

    /**
     * Bring the product's releases up to date.
     *
     * The newest imported release is published unless the product says
     * otherwise — that is the point of connecting the repository at all. This is
     * OUR plugin and our own release, deliberately tagged; it is not the same
     * decision as pushing somebody else's plugin update onto a customer's
     * managed site, which stays manual.
     *
     * @return array{ok: bool, imported: list<string>, skipped: array<string, string>, message: string}
     */
    public function sync(PluginProduct $product): array
    {
        $repo = trim((string) $product->github_repo, " \t/");

        if ($repo === '') {
            return $this->failed($product, 'לא הוגדר מאגר GitHub לתוסף הזה.');
        }

        try {
            $releases = $this->request($product)
                ->get(self::API."/repos/{$repo}/releases", ['per_page' => self::LOOK_BACK])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            return $this->failed($product, 'לא ניתן לקרוא את הגרסאות מ-GitHub: '.Str::limit($e->getMessage(), 160));
        }

        $imported = [];
        $skipped = [];

        foreach ((array) $releases as $release) {
            // Drafts are not published, and a pre-release is published on
            // purpose as "not for everyone" — importing either would put a
            // build in front of customers that its author did not intend to.
            if (($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }

            $version = $this->version((string) ($release['tag_name'] ?? ''));

            if ($version === null) {
                continue;
            }

            if ($product->releases()->where('version', $version)->exists()) {
                continue;
            }

            try {
                $path = $this->fetchZip($product, (array) $release, $version);
            } catch (\Throwable $e) {
                $skipped[$version] = Str::limit($e->getMessage(), 200);

                continue;
            }

            PluginRelease::create([
                'plugin_product_id' => $product->id,
                'version' => $version,
                'zip_path' => $path,
                'changelog' => $this->changelog((string) ($release['body'] ?? '')),
                // Distributed on arrival, unless this product opted out. Only
                // the newest of a batch: importing three old releases at once
                // must not leave customers being offered the oldest.
                'is_current' => false,
                'released_at' => ($release['published_at'] ?? null) ? Carbon::parse($release['published_at']) : now(),
                'source' => 'github',
                'source_ref' => (string) ($release['tag_name'] ?? ''),
            ]);

            $imported[] = $version;
        }

        // Publishing happens after the loop, on the highest version imported —
        // GitHub returns newest first, but nothing here should depend on that.
        $published = null;

        if ($product->auto_publish && $imported !== []) {
            $published = $this->newest($imported);

            $product->releases()->where('version', $published)->first()?->update(['is_current' => true]);
        }

        $message = match (true) {
            $imported !== [] => count($imported).' גרסאות חדשות נקלטו: '.implode(', ', $imported).'. '
                .($published !== null
                    ? "גרסה {$published} מופצת מעכשיו — כל חנות עם רישיון בתוקף תראה את העדכון תוך שש שעות."
                    : 'הפצה אוטומטית כבויה לתוסף הזה — לחצו "הפץ גרסה זו" על מה שרוצים לשלוח ללקוחות.'),
            $skipped !== [] => 'לא נקלטה אף גרסה חדשה.',
            default => 'הכל מסונכרן — אין גרסאות חדשות ב-GitHub.',
        };

        $product->forceFill([
            'github_synced_at' => now(),
            'github_error' => $skipped !== [] ? Str::limit(implode(' · ', $skipped), 250) : null,
        ])->save();

        return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => $message];
    }

    /**
     * Get the zip for one release and store it. Throws with a sentence the
     * operator can act on — "no asset" and "the download failed" call for
     * different things.
     */
    private function fetchZip(PluginProduct $product, array $release, string $version): string
    {
        $asset = $this->pickAsset($product, (array) ($release['assets'] ?? []));

        if ($asset === null && ! $product->pack_from_source) {
            throw new \RuntimeException(
                "לגרסה {$version} לא צורף קובץ ZIP ב-GitHub. צרפו קובץ בנוי לשחרור (למשל ב-GitHub Action), "
                    .'או הפעילו "ארוז מקוד המקור" בהגדרות התוסף — ואז ייארז כל מה שיש במאגר.'
            );
        }

        $bytes = $asset !== null
            ? $this->downloadAsset($product, $asset)
            : $this->packSource($product, (string) ($release['zipball_url'] ?? ''), $version);

        $path = trim((string) config('licensing.path'), '/')."/{$product->slug}/{$version}.zip";

        Storage::disk((string) config('licensing.disk'))->put($path, $bytes);

        return $path;
    }

    /**
     * The attached zip to use. With more than one, the name that mentions the
     * slug wins — a release often carries a zip plus checksums plus a source
     * archive somebody added by hand.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, mixed>|null
     */
    private function pickAsset(PluginProduct $product, array $assets): ?array
    {
        $zips = array_values(array_filter($assets, fn (array $a): bool => Str::endsWith(Str::lower((string) ($a['name'] ?? '')), '.zip')));

        if ($zips === []) {
            return null;
        }

        foreach ($zips as $asset) {
            if (Str::contains(Str::lower((string) $asset['name']), Str::lower($product->slug))) {
                return $asset;
            }
        }

        return $zips[0];
    }

    private function downloadAsset(PluginProduct $product, array $asset): string
    {
        // The API url with an octet-stream Accept works for private repos too;
        // browser_download_url does not.
        $url = (string) ($asset['url'] ?? $asset['browser_download_url'] ?? '');

        if ($url === '') {
            throw new \RuntimeException('לקובץ המצורף ב-GitHub אין כתובת הורדה.');
        }

        return $this->request($product)
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->timeout(120)
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * Repack GitHub's source archive so WordPress installs it correctly.
     *
     * The archive nests everything under `repo-<sha>/`. Installed as-is, the
     * plugin lands in a directory by that name — a different directory on every
     * release — and WordPress loses track of it: the update it offers next time
     * installs a SECOND copy beside the first, both active, both broken. So the
     * contents are lifted out and rebuilt under the slug.
     */
    private function packSource(PluginProduct $product, string $zipballUrl, string $version): string
    {
        if ($zipballUrl === '') {
            throw new \RuntimeException('אין ארכיון קוד זמין לגרסה הזו.');
        }

        $source = $this->request($product)->timeout(120)->get($zipballUrl)->throw()->body();

        $in = tempnam(sys_get_temp_dir(), 'md-src');
        $out = tempnam(sys_get_temp_dir(), 'md-pkg');
        file_put_contents($in, $source);

        try {
            $archive = new \ZipArchive;

            if ($archive->open($in) !== true) {
                throw new \RuntimeException('ארכיון הקוד מ-GitHub אינו נקרא.');
            }

            $prefix = $this->rootDirectory($archive);
            $packed = new \ZipArchive;

            if ($packed->open($out, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('לא ניתן ליצור את קובץ ההפצה.');
            }

            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = (string) $archive->getNameIndex($i);
                $relative = $prefix !== '' ? Str::after($name, $prefix) : $name;

                if ($relative === '' || Str::endsWith($name, '/')) {
                    continue;
                }

                // Repository furniture nobody's shop needs, and which only makes
                // the download bigger and the plugin folder confusing.
                if (Str::startsWith($relative, ['.git', '.github/'])) {
                    continue;
                }

                $packed->addFromString($product->slug.'/'.$relative, (string) $archive->getFromIndex($i));
            }

            $archive->close();
            $packed->close();

            $bytes = (string) file_get_contents($out);

            if ($bytes === '') {
                throw new \RuntimeException("אריזת הקוד לגרסה {$version} יצרה קובץ ריק.");
            }

            return $bytes;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** The single top-level directory GitHub wraps the source in. */
    private function rootDirectory(\ZipArchive $archive): string
    {
        $first = (string) $archive->getNameIndex(0);
        $slash = strpos($first, '/');

        return $slash === false ? '' : substr($first, 0, $slash + 1);
    }

    /**
     * The highest of the imported versions, compared as versions and not as
     * text — "1.10.0" is newer than "1.9.0" and sorts before it as a string.
     *
     * @param  list<string>  $versions
     */
    private function newest(array $versions): string
    {
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($versions);
    }

    /** `v1.2.3` and `1.2.3` are the same version; anything else is not one. */
    private function version(string $tag): ?string
    {
        $tag = ltrim(trim($tag), 'vV');

        return preg_match('/^\d+(\.\d+){1,3}$/', $tag) === 1 ? $tag : null;
    }

    /**
     * The release notes, as the little HTML WordPress renders in "view details".
     * Markdown bullet points are what people actually write there, so they are
     * converted; everything else is escaped, because this text ends up on
     * customers' admin screens.
     */
    private function changelog(string $body): string
    {
        $lines = collect(preg_split('/\R/', trim($body)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter();

        if ($lines->isEmpty()) {
            return '';
        }

        $items = $lines->map(fn (string $line): string => '<li>'.e(ltrim($line, "-*• \t")).'</li>')->implode('');

        return '<ul>'.$items.'</ul>';
    }

    private function request(PluginProduct $product): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Multioto',
            ])
            ->timeout(30);

        $token = trim((string) $product->github_token);

        return $token !== '' ? $request->withToken($token) : $request;
    }

    /** @return array{ok: bool, imported: list<string>, skipped: array<string, string>, message: string} */
    private function failed(PluginProduct $product, string $message): array
    {
        $product->forceFill(['github_error' => Str::limit($message, 250), 'github_synced_at' => now()])->save();

        return ['ok' => false, 'imported' => [], 'skipped' => [], 'message' => $message];
    }
}
