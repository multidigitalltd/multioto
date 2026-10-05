<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\SiteAgentSubscriber;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\Notifications\TeamNotifier;
use App\Services\SiteAgent\InboundChannelHealth;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * ניטור הערוץ הנכנס של בוט ניהול האתר.
 *
 * כל שאר הניטור במערכת שואל "האם האתר עונה". הבדיקה הזאת שואלת את מה שאף אחד
 * לא שאל: **האם הודעה של לקוח בכלל מגיעה אלינו.**
 *
 * זה חור שלם, כי הכישלון כאן שקט לחלוטין. הלקוח כותב, ולא קורה כלום: אין שגיאה,
 * אין תקלה, אין שורה באף יומן. מבחינת המערכת פשוט לא נשלחה הודעה. ההבדל בין
 * "מטא לא שלחה" לבין "קיבלנו ודחינו" אינו נראה בשום מסך — ושניהם מסתיימים
 * בלקוח משלם שהמוצר שלו שותק.
 *
 * שני מצבים מדווחים, ורק הם — כי רק הם חד-משמעיים:
 *
 *  - **מסירות נדחות.** משהו מגיע ואנחנו מסרבים לו. יש רק סיבה אחת: סוד
 *    האפליקציה שאצלנו אינו זהה לזה שבמטא. שדה אחד, תיקון של דקה. זה המצב
 *    הדחוף — מטא מנסה, ואנחנו סוגרים את הדלת.
 *  - **מעולם לא הגיע דבר** בזמן שהשירות מופעל ויש לו מנויים. זה אינו "יום
 *    שקט": זה ערוץ שמעולם לא עבד, והלקוח הראשון שינסה לא יקבל כלום.
 *
 * שקט אחרי שכן הגיעו הודעות אינו מדווח, בכוונה. לקוחות אינם כותבים כל שעה,
 * והתראה על יום שקט היא התראה שלומדים להתעלם ממנה — ואז גם האמיתית נבלעת.
 *
 * מה המצב בפועל נקרא מ-InboundChannelHealth, אותו מקום שממנו קורא גם המסך
 * בפאנל: התראה שמקשרת למסך שאומר משהו אחר היא התראה שמפסיקים לבדוק.
 */
class CheckSiteAgentChannelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The rejection counter the inbound controller writes to. */
    public const CHANNEL = InboundChannelHealth::CHANNEL;

    public function handle(TeamNotifier $team, InboundChannelHealth $health): void
    {
        if (! config('siteagent.channel_watch.enabled', true)) {
            return;
        }

        // Write down what the channel has carried before judging it: the audit
        // rows this reads are pruned, and a fact nobody recorded while it was
        // visible becomes a channel that "never worked".
        $health->observe();

        $state = $health->read();
        $rejected = $state['rejected'];

        // 'unready' — switched off or half-configured — is not reported: the
        // readiness panel already says what is missing, and repeating it as an
        // hourly alert is how a team learns to skip these. 'ok' is not reported
        // either, and neither is a quiet season on a channel that has worked.
        if ($state['verdict'] === 'rejected' && $rejected !== null) {
            $this->report(
                $team,
                'rejected',
                '🔒 בוט ניהול האתר: הודעות מגיעות ואנחנו דוחים אותן',
                sprintf(
                    "מטא שלחה הודעה והיא נדחתה (אימות חתימה נכשל). הדחייה האחרונה: %s.\n\n".
                    'המשמעות אחת: סוד האפליקציה (App secret) שמוגדר בפאנל אינו זהה לזה שבאפליקציה שבמטא. '.
                    "כל מסירה נחתמת בו, ומסירה שאי אפשר לאמת נדחית — כי משלוח שאי אפשר לאמת הוא משלוח מכל אחד.\n\n".
                    'לתיקון: App settings ← Basic ← App secret במטא, והדבקה בהגדרות בוט ניהול אתר.',
                    $rejected->format('d/m/Y H:i'),
                ),
                'danger',
            );

            return;
        }

        if ($state['verdict'] !== 'silent') {
            return;
        }

        // Never anything, in either direction. Only worth reporting once there
        // is somebody whose message would have arrived.
        if (! $this->hasSubscribers()) {
            return;
        }

        $this->report(
            $team,
            'silent',
            '📵 בוט ניהול האתר: מעולם לא התקבלה הודעה',
            'השירות מופעל ומוגדר, יש לו מנויים — ואף הודעה נכנסת לא הגיעה מעולם. גם לא אחת שנדחתה, '.
            "כלומר שום דבר לא הגיע עד הדלת והבעיה אינה בהגדרות שבפאנל.\n\n".
            "מה לבדוק אצל מטא:\n".
            "• האם האפליקציה פורסמה — אפליקציה שלא פורסמה אינה מקבלת הודעות אמיתיות כלל\n".
            "• האם השדה messages מסומן Subscribed בכתובת ה-Webhook\n".
            '• האם הכתובת שמוגדרת שם היא '.route('webhooks.site-agent'),
            'warning',
        );
    }

    /**
     * Is there anybody whose message would have arrived?
     *
     * A number that was sent a code counts even before it is verified — in fact
     * especially then, because answering that code is the first inbound message
     * the product ever expects, and a customer stuck on it is exactly who this
     * watch exists for.
     */
    private function hasSubscribers(): bool
    {
        return rescue(
            fn (): bool => SiteAgentSubscriber::query()->whereNull('revoked_at')->exists(),
            false,
            report: false,
        );
    }

    /**
     * Say it once a day, not once an hour.
     *
     * Both states persist until somebody fixes them, so an hourly alert would
     * repeat the same sentence twenty times before anyone reads the first —
     * and a team that learns to skip this alert is worse off than one that
     * never had it.
     */
    private function report(TeamNotifier $team, string $state, string $title, string $body, string $colour): void
    {
        $hours = max(1, (int) config('siteagent.channel_watch.cooldown_hours', 24));
        $key = "siteagent.channel-watch.{$state}";

        $fresh = rescue(
            fn (): bool => Cache::add($key, now()->toIso8601String(), now()->addHours($hours)),
            true,
            report: false,
        );

        // The log line is written every run regardless of the alert cooldown:
        // the alert is for attention, the log is for "since when", and the
        // second question is the one asked after the fact.
        SystemLog::record($state === 'rejected' ? 'error' : 'warning', 'site-agent', $title, ['state' => $state]);

        if (! $fresh) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/').'/admin/settings/manage-site-agent';

        $team->alert($title, $body, $url);

        $admins = User::where('role', UserRole::Admin)->get();

        if ($admins->isNotEmpty()) {
            Notification::make()
                ->title($title)
                ->body($state === 'rejected'
                    ? 'סוד האפליקציה בפאנל אינו תואם לזה שבמטא — כל הודעה נכנסת נדחית.'
                    : 'שום הודעה לא הגיעה מעולם. הבעיה אצל מטא, לא בהגדרות.')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color($colour)
                ->actions([Action::make('settings')->label('הגדרות הבוט')->url($url)])
                ->sendToDatabase($admins);
        }
    }
}
