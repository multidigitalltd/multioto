<?php

namespace App\Console\Commands;

use App\Models\NotificationTemplate;
use App\Services\Notifications\TemplateEngine;
use Illuminate\Console\Command;

/**
 * Materialize the built-in notification templates as editable rows, so the
 * panel lists them out of the box. Idempotent — rows the team already edited
 * are never overwritten.
 */
class SeedNotificationTemplates extends Command
{
    protected $signature = 'app:seed-templates';

    protected $description = 'Seed the default notification templates (never overwrites edited ones)';

    public function handle(): int
    {
        $created = 0;

        foreach (TemplateEngine::DEFAULTS as $key => $channels) {
            foreach ($channels as $channel => $template) {
                $created += (int) NotificationTemplate::firstOrCreate(
                    ['key' => $key, 'channel' => $channel],
                    ['subject' => $template['subject'], 'body' => $template['body'], 'enabled' => true],
                )->wasRecentlyCreated;
            }
        }

        $this->info("Notification templates seeded ({$created} new).");

        return self::SUCCESS;
    }
}
