<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Illuminate\Console\Command;

/**
 * Operational fallback for the settings screen: set (or inspect) an
 * integration setting from the console. Values are stored encrypted exactly
 * like the UI does, and the key must be on the SettingsServiceProvider::MAP
 * allow-list — nothing outside it is touchable. Secret values are never
 * echoed back; inspection prints filled/empty only.
 *
 *   php artisan settings:set linet.doctype 9
 *   php artisan settings:set --show            # list keys + filled/empty
 */
class SettingsSetCommand extends Command
{
    protected $signature = 'settings:set {key?} {value?} {--show : List all setting keys and whether each is filled}';

    protected $description = 'Set an integration setting (encrypted), or --show to list what is stored';

    public function handle(): int
    {
        if ($this->option('show')) {
            $stored = Setting::map();

            foreach (array_keys(SettingsServiceProvider::MAP) as $key) {
                $this->line(sprintf('%-32s %s', $key, filled($stored[$key] ?? null) ? 'filled' : 'empty'));
            }

            return self::SUCCESS;
        }

        $key = (string) $this->argument('key');
        $value = $this->argument('value');

        if ($key === '' || $value === null) {
            $this->error('Usage: settings:set <key> <value>   (or --show)');

            return self::INVALID;
        }

        if (! array_key_exists($key, SettingsServiceProvider::MAP)) {
            $this->error("Unknown setting '{$key}'. Allowed keys:");
            $this->line(implode(PHP_EOL, array_keys(SettingsServiceProvider::MAP)));

            return self::INVALID;
        }

        Setting::put($key, trim((string) $value));
        $this->info("{$key} saved (encrypted).");

        return self::SUCCESS;
    }
}
