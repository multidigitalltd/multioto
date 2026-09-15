<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Put a plugin release onto the disk sites download it from.
 *
 * The download endpoint already falls back to the copy committed in the repo,
 * so a plain deploy is enough for sites to self-update. This command is for the
 * case that fallback does not cover: a plugin disk on S3 or a shared volume,
 * where serving the file from the app container is slower, or not possible at
 * all once the repo copy is pruned from a slim image.
 *
 * It copies FROM the repo release TO the configured disk, so the two can never
 * disagree about what version 1.5.0 is — publishing a file somebody assembled
 * by hand is how a site ends up updating to a build nobody can reproduce.
 *
 * The copy goes to a staging name first and is moved into place only once it is
 * whole: sites check in for updates continuously, and a zip downloaded halfway
 * through a write installs as a broken plugin on a live customer site.
 */
class PublishAgentPluginCommand extends Command
{
    protected $signature = 'agent:publish-plugin
        {version? : Which release to publish (default: the version the panel currently offers)}
        {--force : Overwrite a file already on the disk}';

    protected $description = 'Copy the committed agent-plugin release onto the plugin download disk';

    public function handle(): int
    {
        $version = trim((string) ($this->argument('version') ?: config('agent.plugin.current_version')));

        if ($version === '' || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $this->error("גרסה לא תקינה: \"{$version}\". פורמט נדרש: 1.5.0");

            return self::FAILURE;
        }

        $source = base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip");

        if (! is_file($source)) {
            $this->error("קובץ הגרסה אינו קיים: {$source}");
            $this->line('לבנייה: bash wordpress-plugin/build.sh');

            return self::FAILURE;
        }

        $diskName = (string) config('agent.plugin.disk');
        $target = trim((string) config('agent.plugin.path'), '/')."/{$version}.zip";
        $disk = Storage::disk($diskName);

        if ($disk->exists($target) && ! $this->option('force')) {
            // Overwriting silently would replace a build sites have already
            // downloaded with different bytes under the same version number.
            $this->warn("הקובץ כבר קיים ב-{$diskName}:{$target} — לא נגעתי בו.");
            $this->line('להחלפה: --force');

            return self::SUCCESS;
        }

        $stream = fopen($source, 'rb');

        if ($stream === false) {
            $this->error('לא ניתן לקרוא את קובץ הגרסה.');

            return self::FAILURE;
        }

        // Written beside the target, never onto it. The download endpoint serves
        // whatever sits at the target the moment it exists, so writing there
        // directly means a site that checks in mid-copy downloads half a zip —
        // and with --force the valid package it would have received is already
        // truncated. The temp name can never be requested: a version is
        // validated to digits and dots, so no signed URL can point at it.
        $staging = trim((string) config('agent.plugin.path'), '/').'/.publish-'.$version.'-'.bin2hex(random_bytes(6)).'.tmp';

        try {
            // Streamed rather than read into memory: the release is tens of
            // megabytes and this may run on a small container.
            $written = $disk->put($staging, $stream);
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            $disk->delete($staging);
            $this->error("הכתיבה ל-{$diskName}:{$target} נכשלה — יש לבדוק הרשאות כתיבה.");

            return self::FAILURE;
        }

        // Only now does the target change, in one step: sites see either the
        // previous release or the complete new one, never a partial file.
        try {
            $moved = $disk->move($staging, $target);
        } catch (\Throwable) {
            $moved = false;
        }

        if ($moved === false) {
            $disk->delete($staging);
            $this->error("ההעברה ל-{$diskName}:{$target} נכשלה — הקובץ הקודם לא נפגע.");

            return self::FAILURE;
        }

        $this->info("✓ גרסה {$version} פורסמה ל-{$diskName}:{$target}");

        if ($version !== (string) config('agent.plugin.current_version')) {
            // Publishing a version the panel does not offer changes nothing:
            // sites are told to update to current_version and will never ask
            // for this file.
            $this->warn('שימו לב: הפאנל מציע כרגע גרסה '.config('agent.plugin.current_version')
                .', כך שאתרים לא יבקשו את הקובץ הזה. יש לעדכן AGENT_PLUGIN_VERSION.');
        }

        return self::SUCCESS;
    }
}
