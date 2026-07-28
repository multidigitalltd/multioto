<?php

namespace App\Filament\Resources\BroadcastResource\Actions;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Jobs\SendBroadcastJob;
use App\Mail\BroadcastMail;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Services\Support\BroadcastAudience;
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
        $counts = app(BroadcastAudience::class)->summary($record->channel, $record->segment);
        $channel = $record->channel->getLabel();

        if ($counts['reachable'] === 0) {
            return new HtmlString('אף לקוח בקהל היעד אינו ניתן להשגה ב'.$channel.' — אין למי לשלוח.');
        }

        $text = 'הדיוור יישלח ל-<strong>'.$counts['reachable'].'</strong> לקוחות ב'.$channel.'. פעולה זו אינה הפיכה.';

        if ($counts['unreachable'] > 0) {
            $text .= '<br>'.$counts['unreachable'].' לקוחות בקהל ידולגו — אין להם כתובת בערוץ הזה.';
        }

        if ($record->channel === BroadcastChannel::Whatsapp) {
            $minutes = (int) ceil($counts['reachable'] * (int) config('billing.waha.broadcast_throttle_seconds') / 60);
            $text .= '<br>בקצב השליחה המושהה של וואטסאפ זה ייקח כ-'.$minutes.' דקות.';
        }

        return new HtmlString($text);
    }

    /** Queue the real send. Returns the Filament notification to show. */
    public static function send(Broadcast $record): Notification
    {
        $counts = app(BroadcastAudience::class)->summary($record->channel, $record->segment);

        if ($counts['reachable'] === 0) {
            return Notification::make()
                ->danger()
                ->title('לא נשלח')
                ->body('אין אף לקוח שניתן להשיג בערוץ שנבחר.');
        }

        // The job claims the row itself, so a scheduled duplicate cannot double
        // send; marking it scheduled here just makes the intent visible at once.
        $record->update(['status' => BroadcastStatus::Scheduled, 'scheduled_at' => now()]);

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

        try {
            if ($record->channel === BroadcastChannel::Email) {
                if (blank($user?->email)) {
                    return Notification::make()->danger()->title('אין כתובת אימייל למשתמש שלך');
                }

                Mail::to($user->email)->send(new BroadcastMail('[בדיקה] '.$record->subject, $record->body));

                return Notification::make()->success()->title('נשלחה בדיקה')->body('נשלח אל '.$user->email);
            }

            if (blank($user?->phone)) {
                return Notification::make()->danger()->title('אין מספר טלפון למשתמש שלך')
                    ->body('הוסיפו מספר בפרופיל כדי לשלוח בדיקת וואטסאפ.');
            }

            app(WahaClient::class)->sendMessage($user->phone, $record->body);

            return Notification::make()->success()->title('נשלחה בדיקה')->body('נשלח אל '.$user->phone);
        } catch (\Throwable $e) {
            report($e);

            return Notification::make()->danger()->title('בדיקת השליחה נכשלה')
                ->body('פרטי השגיאה נרשמו ביומן המערכת.');
        }
    }
}
