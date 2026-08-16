<?php

namespace App\Jobs;

use App\Models\PluginProduct;
use App\Models\SystemLog;
use App\Services\Licensing\GithubReleases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pull new releases from a plugin's development repository.
 *
 * Runs on a schedule and on demand from the product screen. Never distributes
 * what it finds: a version arriving here is a version available to publish, and
 * publishing it to every customer's shop stays a decision somebody makes.
 */
class SyncPluginReleasesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?int $productId = null) {}

    public function handle(GithubReleases $github): void
    {
        $products = PluginProduct::query()
            ->when($this->productId !== null, fn ($query) => $query->whereKey($this->productId))
            ->whereNotNull('github_repo')
            ->where('github_repo', '!=', '')
            ->get();

        foreach ($products as $product) {
            $result = $github->sync($product);

            // Only worth a log line when something actually changed or broke.
            // A sweep that finds nothing new is the normal case and would drown
            // the log it is supposed to be readable in.
            if ($result['imported'] !== []) {
                SystemLog::record('info', 'licensing',
                    "נקלטו גרסאות חדשות ל{$product->name}: ".implode(', ', $result['imported']).' (טרם מופצות).',
                    ['plugin_product_id' => $product->id]);
            }

            if (! $result['ok'] || $result['skipped'] !== []) {
                SystemLog::record('warning', 'licensing',
                    "סנכרון הגרסאות של {$product->name}: ".$result['message'],
                    ['plugin_product_id' => $product->id, 'skipped' => $result['skipped']]);
            }
        }
    }
}
