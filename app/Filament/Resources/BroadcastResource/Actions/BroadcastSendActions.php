<?php

namespace App\Filament\Resources\BroadcastResource\Actions;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Jobs\SendBroadcastJob;
use App\Mail\BroadcastMail;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Services\Support\BroadcastAudience;
use App\Services\Support\BroadcastRenderer;
use App\Services\Waha\WahaClient;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

/**
 * The two buttons that actually send a broadcast, shared by the list row and the
 * edit screen so both behave identically.
 *
 * Sending is irreversible and reaches every customer at once, so it is never a
 * side effect of saving: it takes a deliberate press, a confirmation naming the
 * exact recipient count, and it is written to the audit log.
 *
 * @template TAction of \Filament\Actions\Action|\Filament\Tables\Actions\Action
 */
class BroadcastSendActions
{
    /** A broadcast that has gone out, or is going out, must not be sent again. */
    public static function isSendable(Broadcast $record): bool
    {
        return ! in_array($record->status, [BroadcastStatus::Sending, BroadcastStatus::Sent], true);
    }

    /**
     * Confirmation copy: how many customers, on which channel, and how many are
     * skipped for want of an address.
     */
    public static function confirmation(Broadcast $record): HtmlString
    {
        $counts = app(BroadcastAudience::class)
            ->summary($record->channel, $record->segment, marketing: (bool) $record->is_marketing);
        $channel = $record->channel->getLabel();

        if ($counts['reachable'] === 0) {
            return new HtmlString('אף לקוח בקהל היעד אינו ניתן להשגה ב'.$channel.' — אין למי לשלוח.');
        }

        $text = 'הדיוור יישלח ל-<strong>'.$counts['reachable'].'</strong> לקוחות ב'.$channel.'. פעולה זו אינה הפיכה.';

        if ($counts['unreachable'] > 0) {
            $text .= '<br>'.$counts['unreachable'].' לקוחות בקהל ידולגו — אין להם כתובת בערוץ הזה.';
        }

        if ($counts['opted_out'] > 0) {
            $text .= '<br>'.$counts['opted_out'].' לקוחות ביקשו להסיר אותם מדיוור פרסומי ולא יקבלו.';
        }

        // החלטה שלנו, ולכן היא נאמרת לפני השליחה ולא אחריה — עם הדרך לבטל
        // אותה לדיוור הזה.
        if (($counts['never_opens'] ?? 0) > 0) {
            $text .= '<br>'.$counts['never_opens'].' לקוחות ידולגו כי לא פתחו אף אחת מההודעות האחרונות שנמסרו להם. '
                .'(אפשר לכלול אותם בכל זאת — בעריכת הדיוור, בקטע "קהל יעד".)';
        }

        if ($record->is_marketing) {
            $text .= '<br><em>ההודעה תישלח כפרסומת: לשורת הנושא תתווסף המילה "(פרסומת)", ובתחתית ההודעה פרטי העסק וקישור הסרה.</em>';
        }

        if ($record->channel === BroadcastChannel::Whatsapp) {
            $minutes = (int) ceil($counts['reachable'] * (int) config('billing.waha.broadcast_throttle_seconds') / 60);
            $text .= '<br>בקצב השליחה המושהה של וואטסאפ זה ייקח כ-'.$minutes.' דקות.';

            if ($counts['reachable'] > ($max = SendBroadcastJob::maxWhatsappRecipients())) {
                $text .= '<br><strong>זה יותר מדי לשליחה אחת ('.$max.' לכל היותר) — השליחה תיחסם. '
                    .'צמצמו את הקהל או שלחו באימייל.</strong>';
            }
        }

        return new HtmlString($text);
    }

    /** Queue the real send. Returns the Filament notification to show. */
    public static function send(Broadcast $record): Notification
    {
        $counts = app(BroadcastAudience::class)
            ->summary($record->channel, $record->segment, marketing: (bool) $record->is_marketing);

        if ($counts['reachable'] === 0) {
            return Notification::make()
                ->danger()
                ->title('לא נשלח')
                ->body('אין אף לקוח שניתן להשיג בערוץ שנבחר.');
        }

        // Caught here too, not only in the job, so the operator hears about it
        // while the segment is still in front of them.
        if ($record->channel === BroadcastChannel::Whatsapp
            && $counts['reachable'] > ($max = SendBroadcastJob::maxWhatsappRecipients())) {
            return Notification::make()
                ->danger()
                ->title('קהל היעד גדול מדי לוואטסאפ')
                ->body("{$counts['reachable']} נמענים, והמקסימום לשליחה אחת הוא {$max} — בגלל ההשהיה המכוונת בין הודעות. "
                    .'צמצמו את הקהל, פצלו לכמה דיוורים, או שלחו באימייל.');
        }

        // A scheduled broadcast can fall due while this confirmation modal sits
        // open: the scheduler dispatches a job, that job claims the row as
        // Sending, and an unconditional update here would hand it back as
        // Scheduled for a second job to claim — every customer hearing from us
        // twice, and on throttled WhatsApp for hours. Only claim a row that is
        // still waiting; losing the race means the send is already under way.
        $claimed = Broadcast::whereKey($record->getKey())
            ->whereIn('status', [BroadcastStatus::Draft, BroadcastStatus::Scheduled])
            ->update(['status' => BroadcastStatus::Scheduled, 'scheduled_at' => now()]);

        if ($claimed === 0) {
            return Notification::make()
                ->warning()
                ->title('הדיוור כבר בשליחה')
                ->body('השליחה התחילה ברקע בינתיים — לא נשלח שוב כדי שאף לקוח לא יקבל אותו פעמיים.');
        }

        SendBroadcastJob::dispatch($record->id);

        AuditLog::record('created',
            "שליחת דיוור \"{$record->subject}\" ב{$record->channel->getLabel()} ל-{$counts['reachable']} לקוחות", $record);

        return Notification::make()
            ->success()
            ->title('הדיוור נשלח לתור')
            ->body($counts['reachable'].' לקוחות יקבלו אותו. אפשר לעקוב אחרי המונה בטבלה.');
    }

    /**
     * Send the broadcast to the signed-in team member only, so the wording and
     * the layout can be checked against a real inbox before customers see it.
     */
    public static function sendTest(Broadcast $record): Notification
    {
        $user = auth()->user();

        // Render against a real customer from the segment, so the test shows the
        // placeholders and the footer exactly as a customer would see them —
        // sending the raw text would hide the one thing worth checking.
        $sample = app(BroadcastAudience::class)
            ->reachable($record->channel, $record->segment, marketing: (bool) $record->is_marketing)
            ->with(['sites:id,customer_id,domain', 'subscriptions.plan:id,name'])
            ->first();

        if ($sample === null) {
            return Notification::make()->danger()->title('אין לקוח בקהל היעד')
                ->body('בלי לקוח לדוגמה אי אפשר להראות איך ההודעה תיראה בפועל.');
        }

        $renderer = app(BroadcastRenderer::class);
        $subject = '[בדיקה] '.$renderer->subject($record, $sample);
        $body = $renderer->body($record, $sample);

        try {
            if ($record->channel === BroadcastChannel::Email) {
                if (blank($user?->email)) {
                    return Notification::make()->danger()->title('אין כתובת אימייל למשתמש שלך');
                }

                Mail::to($user->email)->send(new BroadcastMail(
                    $subject, $body,
                    $renderer->emailFooter($record, $sample, preview: true),
                    $renderer->bodyHtml($record, $sample),
                ));

                return Notification::make()->success()->title('הבדיקה נשלחה לתור')
                    ->body('תגיע אל '.$user->email.' — מוצגת כפי שיראה אותה הלקוח '.$sample->name.'.');
            }

            if (blank($user?->phone)) {
                return Notification::make()->danger()->title('אין מספר טלפון למשתמש שלך')
                    ->body('הוסיפו מספר בפרופיל כדי לשלוח בדיקת וואטסאפ.');
            }

            app(WahaClient::class)->sendMessage($user->phone, $body);

            return Notification::make()->success()->title('נשלחה בדיקה')
                ->body('נשלח אל '.$user->phone.' — מוצגת כפי שיראה אותה הלקוח '.$sample->name.'.');
        } catch (\Throwable $e) {
            report($e);

            return Notification::make()->danger()->title('בדיקת השליחה נכשלה')
                ->body('פרטי השגיאה נרשמו ביומן המערכת.');
        }
    }
}
