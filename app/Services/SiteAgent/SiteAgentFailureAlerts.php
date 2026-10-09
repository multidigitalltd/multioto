<?php

namespace App\Services\SiteAgent;

use App\Enums\UserRole;
use App\Filament\Resources\SiteAgentMessageResource;
use App\Jobs\SendSiteAgentFailureAlertJob;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentSubscriber;
use App\Models\User;
use App\Support\Changelog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/** A bounded, immutable incident snapshot, taken only after WhatsApp accepted the fallback. */
class SiteAgentFailureAlerts
{
    /** Optional diagnostics must never prevent the actual owner request. */
    public function captureHistoryBoundary(SiteAgentSubscriber $subscriber): int
    {
        try {
            return (int) SiteAgentMessage::query()
                ->where('site_agent_subscriber_id', $subscriber->id)
                ->where('site_id', $subscriber->site_id)->max('id');
        } catch (\Throwable $error) {
            Log::warning('SiteAgent: failure alert history unavailable', [
                'subscriber_id' => $subscriber->id,
                'site_id' => $subscriber->site_id,
                'error_class' => $error::class,
            ]);

            return 0;
        }
    }

    public function dispatch(int $webhookEventId, SiteAgentSubscriber $subscriber, string $request, string $reply, int $historyThroughId): void
    {
        $reason = $this->reason($reply);
        if ($reason === null) {
            return;
        }

        try {
            $configured = trim((string) config('siteagent.alerts.failure_email', ''));
            $recipients = $configured !== ''
                ? [$configured]
                : User::query()->where('role', UserRole::Admin)->pluck('email')->all();
            $recipients = array_values(array_unique(array_filter($recipients, fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
            if ($recipients === []) {
                return;
            }

            // The upper ID was captured BEFORE handling this request. Later
            // requests, including ones processed while WhatsApp was sending,
            // cannot enter this snapshot. Append the exact current pair below.
            $messages = $historyThroughId > 0
                ? SiteAgentMessage::query()
                    ->where('site_agent_subscriber_id', $subscriber->id)
                    ->where('site_id', $subscriber->site_id)
                    ->where('id', '<=', $historyThroughId)
                    ->whereIn('role', [SiteAgentMessage::USER, SiteAgentMessage::ASSISTANT])
                    ->latest('id')->limit(39)->get(['id', 'role', 'body', 'created_at'])
                : collect();
            $lines = [];
            $length = 0;
            $truncated = $messages->count() > 38;
            foreach ($messages->take(38) as $message) {
                $line = $message->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s')
                    .' · '.($message->role === SiteAgentMessage::USER ? 'בעל האתר' : 'הבוט')
                    ."\n".$this->safeText((string) $message->body, 1000);
                if ($length + mb_strlen($line) > 12000) {
                    $truncated = true;
                    break;
                }
                $length += mb_strlen($line);
                $lines[] = $line;
            }
            $lines = array_reverse($lines);
            if ($truncated) {
                array_unshift($lines, '[היסטוריה קודמת קוצרה; עד 40 הודעות נכללות בהתראה.]');
            }
            $time = now()->timezone(config('app.timezone'))->format('d/m/Y H:i:s');
            $lines[] = $time." · בקשת בעל האתר הנוכחית\n".$this->safeText($request, 4000);
            $lines[] = $time." · תשובת הבוט שנשלחה\n".$this->safeText($reply, 4000);
            $subscriber->loadMissing('site');
            $url = SiteAgentMessageResource::getUrl('index', [
                'tableFilters' => ['site_agent_subscriber_id' => ['value' => $subscriber->id]],
            ], panel: 'admin');
            $body = implode("\n", [
                'הבוט שלח תשובת כשל בהבנת הבקשה. נדרשת בדיקת השיחה.',
                'אתר: '.$this->safeText((string) $subscriber->site?->domain, 300),
                'שם: '.$this->safeText((string) $subscriber->name, 300),
                'טלפון: '.$this->safeText((string) $subscriber->phone, 100),
                'מועד: '.$time.' ('.config('app.timezone').')',
                'סיבה: '.$reason,
                'ספק ומודל: '.$this->safeText((string) config('billing.ai.provider').' / '.(string) config('billing.ai.model'), 200),
                'גרסת תוכנה: '.Changelog::currentVersion(),
                '',
                'תמליל לפי סדר השיחה (ערכים מזוהים כסודות וקישורים מוסרים):',
                implode("\n\n", $lines),
                '',
                'לשיחה בממשק הניהול (נדרשת התחברות כמנהל): '.$url,
            ]);

            $job = new SendSiteAgentFailureAlertJob($webhookEventId, $recipients, $body);
            // Even an installation using sync for other work must enqueue this
            // independently: a mail retry must never rerun the owner's request.
            $connection = (string) config('queue.default', 'database');
            $job->onConnection(in_array($connection, ['sync', 'null'], true) ? 'database' : $connection);
            Bus::dispatch($job);
        } catch (\Throwable $error) {
            Log::warning('SiteAgent: failure alert could not be queued', [
                'webhook_event_id' => $webhookEventId,
                'subscriber_id' => $subscriber->id,
                'error_class' => $error::class,
            ]);
        }
    }

    private function reason(string $reply): ?string
    {
        return match (true) {
            str_starts_with($reply, 'לא הצלחתי להבין בוודאות מה לשנות') => 'לא זוהה שינוי לביצוע',
            $reply === 'לא הבנתי מה לשנות. כתבו לי מה תרצו לעדכן באתר.' => 'בקשה ללא תוכן ברור',
            $reply === SiteAgentAssistant::NO_VERIFIED_PROPOSAL => 'לא נוצרה הצעה מאומתת',
            $reply === SiteAgentConversation::NO_PENDING_PROPOSAL => 'התקבל אישור ללא הצעה תקפה',
            $reply === 'קיבלתי את התמונה, אבל לא הצלחתי להבין לאן לשים אותה.' => 'לא זוהה יעד לתמונה',
            default => null,
        };
    }

    /** Best-effort removal of labelled credentials and links, not a secret detector. */
    private function safeText(string $text, int $limit): string
    {
        $text = preg_replace('~https?://\S+~iu', '[קישור הוסר]', $text) ?? '';
        $text = preg_replace('/((?:password|passwd|secret|token|api[ _-]?key|authorization|סיסמ[אה]|טוקן|מפתח\s*API)\s*["\']?\s*[:=]\s*)[^\r\n]+/iu', '$1[הוסר]', $text) ?? '';
        $text = preg_replace('/\bBearer\s+\S+|\b(?:sk-[A-Za-z0-9_-]{12,}|AIza[A-Za-z0-9_-]{20,})/u', '[הוסר]', $text) ?? '';

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit)."\n[הודעה קוצרה]" : $text;
    }
}
