<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Waha\InboundDiagnosis;
use App\Services\Waha\WahaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Diagnose — and optionally repair — the inbound WhatsApp path from the shell.
 *
 * The same two actions the integrations screen offers, available when the panel
 * is not where you are: a WAHA container that came back up without its webhook
 * config is fixed over SSH in the same minute it is noticed, and it can be put
 * in a deploy script so a restart stops meaning a week of missed enquiries.
 */
class WahaInboundCommand extends Command
{
    protected $signature = 'waha:inbound {--enable : Register our webhook on the WAHA session (repairs a lost registration)}';

    protected $description = 'Diagnose the inbound WhatsApp path, and re-register the webhook with --enable';

    public function handle(InboundDiagnosis $diagnosis, WahaClient $waha): int
    {
        if ($this->option('enable')) {
            if (! $this->register($waha)) {
                return self::FAILURE;
            }
        }

        $result = $diagnosis->run();

        $this->newLine();
        $this->line($result['title']);
        $this->line($result['detail']);
        $this->newLine();
        $this->line('מצב: '.$result['state']);

        // A definite fault is a non-zero exit, so this can gate a deploy check
        // or a cron without anyone reading the Hebrew.
        return in_array($result['state'], InboundDiagnosis::FAULTS, true) ? self::FAILURE : self::SUCCESS;
    }

    /** Point the WAHA session at our webhook, generating the secret if needed. */
    private function register(WahaClient $waha): bool
    {
        $secret = (string) config('billing.waha.webhook_secret');

        if ($secret === '') {
            $secret = Str::random(40);
            Setting::put('waha.webhook_secret', $secret);
            config(['billing.waha.webhook_secret' => $secret]);
            $this->line('נוצר סוד webhook חדש ונשמר.');
        }

        try {
            // The URL carries the secret; never print it.
            $waha->configureInboundWebhook(route('webhooks.waha').'?secret='.$secret);
        } catch (Throwable $e) {
            $this->error('הרישום נכשל: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 200));

            return false;
        }

        $this->info('ההאזנה נרשמה מול WAHA ✓');

        return true;
    }
}
