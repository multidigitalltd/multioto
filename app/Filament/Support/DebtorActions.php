<?php

namespace App\Filament\Support;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * The three "act on a debtor" table actions — charge now, send a card-update
 * link, open the customer card — shared by the Collections page, the Debtors
 * dashboard widget and the Subscriptions table, so the behaviour (and the
 * charge's duplicate-safety) lives in exactly one place.
 */
class DebtorActions
{
    /** Charge the subscription immediately (idempotent job — safe to press). */
    public static function chargeNow(): Action
    {
        return Action::make('chargeNow')
            ->label('חייב עכשיו')
            ->icon('heroicon-o-bolt')
            ->color('warning')
            ->visible(fn (Subscription $record): bool => $record->isChargeable())
            ->requiresConfirmation()
            ->modalHeading('חיוב מיידי')
            ->modalDescription(fn (Subscription $record): string => 'לחייב את '.$record->customer->name.' בסך '.Money::ils($record->totalChargeAgorot()).' עכשיו? החיוב ירוץ ברקע עם כל הגנות הכפילות הרגילות.')
            ->modalSubmitActionLabel('חייב עכשיו')
            ->action(function (Subscription $record): void {
                // Collect now without moving the billing anchor forward, so a late
                // payer is billed for the delayed period (no free days).
                $record->markDueNow();
                ChargeSubscriptionJob::dispatch($record->id);
                Notification::make()->title('החיוב נשלח לביצוע')->body('התוצאה תופיע במסך "חיובים".')->success()->send();
            });
    }

    /** Send the customer a secure card-update link (WhatsApp + email). */
    public static function sendCardLink(): Action
    {
        return Action::make('sendCardLink')
            ->label('קישור לכרטיס')
            ->icon('heroicon-o-credit-card')
            ->visible(fn (Subscription $record): bool => $record->status !== SubscriptionStatus::Canceled
                && filled($record->customer->phone ?? $record->customer->email))
            ->requiresConfirmation()
            ->modalHeading('שליחת קישור להזנת כרטיס')
            ->modalDescription(fn (Subscription $record): string => 'לשלוח ל-'.$record->customer->name.' קישור מאובטח להזנת/עדכון כרטיס אשראי (וואטסאפ + מייל)?')
            ->modalSubmitActionLabel('שלח')
            ->action(function (Subscription $record, CardCaptureLinkSender $sender): void {
                $record->loadMissing(['customer', 'plan']);
                CustomerResource::notifyLinkResult($sender->send($record));
            });
    }

    /** Open the customer's 360° card. */
    public static function viewCustomer(): Action
    {
        return Action::make('viewCustomer')
            ->label('כרטיס לקוח')
            ->icon('heroicon-o-user')
            ->color('gray')
            ->url(fn (Subscription $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id]));
    }
}
