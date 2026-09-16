<?php

namespace App\Jobs;

use App\Models\SiteAgentRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Close offers nobody answered, and delete the pictures they were holding.
 *
 * An unanswered offer is harmless on its own — the confirmation query already
 * refuses to act on an expired one. What is not harmless is the image sitting
 * beside it: a customer's photograph, on our disk, for a change they never
 * agreed to. It stays only while the question is open.
 */
class PruneSiteAgentRequestsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        SiteAgentRequest::query()
            ->where('state', SiteAgentRequest::AWAITING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $path = (string) data_get($request->plan, 'image_path', '');

                    if ($path !== '') {
                        Storage::disk('local')->delete($path);
                    }

                    $request->update(['state' => SiteAgentRequest::EXPIRED]);
                }
            });
    }
}
