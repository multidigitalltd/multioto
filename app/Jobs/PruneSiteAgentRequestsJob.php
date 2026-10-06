<?php

namespace App\Jobs;

use App\Models\SiteAgentMessage;
use App\Models\SiteAgentRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Two different clean-ups on the same table, on two different clocks.
 *
 * **Within the hour** — close offers nobody answered, and delete the pictures
 * they were holding. An unanswered offer is harmless on its own; the
 * confirmation query already refuses to act on an expired one. What is not
 * harmless is the image sitting beside it: a customer's photograph, on our
 * disk, for a change they never agreed to. It stays only while the question is
 * open.
 *
 * **After the retention window** — delete the request itself. Until this
 * existed, the TEXT of every instruction a customer ever sent over WhatsApp
 * stayed in the table for good: what they asked for, what we offered back, the
 * preview of their own content. The picture was handled; the words were not,
 * and a privacy policy cannot promise a window that nothing enforces.
 *
 * **After a few days** — the assistant's transcript. It exists only so the
 * next message is understood in context, and its answers quote the site's own
 * customers: names, phones, what they ordered. Kept far shorter than the
 * requests, for exactly that reason.
 */
class PruneSiteAgentRequestsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $this->expireUnanswered();
        $this->failAbandoned();
        $this->forgetOld();
        $this->forgetTranscript();
    }

    /** The conversation memory, past the few days it is kept for. */
    private function forgetTranscript(): void
    {
        $days = max(1, (int) config('siteagent.assistant.transcript_days', 7));

        SiteAgentMessage::query()
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(500, fn ($messages) => SiteAgentMessage::query()->whereKey($messages->modelKeys())->delete());
    }

    /** Offers whose time ran out: close them, and drop the held picture. */
    private function expireUnanswered(): void
    {
        SiteAgentRequest::query()
            ->where('state', SiteAgentRequest::AWAITING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $this->deleteHeldImage($request);

                    $request->update(['state' => SiteAgentRequest::EXPIRED]);
                }
            });
    }

    /**
     * A change claimed for execution whose worker died (a timeout, a deploy)
     * before it finished: nothing else would ever move it out of APPLYING. Well
     * past the longest a job may run, it is closed as failed and its held
     * picture removed. Whether it reached the site is unknown — the reason
     * says so, for whoever looks at it in the journal.
     */
    private function failAbandoned(): void
    {
        SiteAgentRequest::query()
            ->where('state', SiteAgentRequest::APPLYING)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $this->deleteHeldImage($request);

                    $request->update([
                        'state' => SiteAgentRequest::FAILED,
                        'failure_reason' => 'הביצוע נקטע באמצע (העבודה הופסקה). לא ידוע אם השינוי הגיע לאתר — כדאי לבדוק.',
                    ]);
                }
            });
    }

    /**
     * Requests older than the retention window — gone, text and all.
     *
     * By state, not only by age, would be the wrong cut: a row left AWAITING for
     * six months is not an open question anybody is still waiting on, it is a
     * row that was never closed. Age is the honest test, and it is the one the
     * privacy policy states.
     *
     * The picture is normally gone long before this (settle() removes it the
     * moment the question closes, and expireUnanswered() above covers the rest),
     * but it is attempted again here rather than assumed: the row is about to
     * stop existing, and with it the only record of which file belonged to it.
     */
    private function forgetOld(): void
    {
        $days = (int) config('billing.system.site_agent_request_retention_days', 180);

        if ($days <= 0) {
            return; // Retention switched off — keep everything, deliberately.
        }

        SiteAgentRequest::query()
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $this->deleteHeldImage($request);

                    $request->delete();
                }
            });
    }

    /** The customer's uploaded file held for this request, if one is still there. */
    private function deleteHeldImage(SiteAgentRequest $request): void
    {
        $path = (string) data_get($request->plan, 'image_path', '');

        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
