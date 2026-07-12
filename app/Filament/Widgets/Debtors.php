<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard: subscriptions in arrears (past-due / suspended) with the same
 * one-click collect / send-card-link actions as the Collections screen, so the
 * owner can act on money owed straight from the home page.
 */
class Debtors extends BaseWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'גבייה — חייבים';

    public static function canView(): bool
    {
        return Subscription::whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Subscription::query()
                    ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])
                    ->with(['customer', 'plan'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->weight('bold'),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge()
                    ->color(fn (SubscriptionStatus $state): string => $state === SubscriptionStatus::Suspended ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('סכום')
                    ->getStateUsing(fn (Subscription $record): string => '₪'.number_format($record->totalChargeAgorot() / 100, 2)),
                Tables\Columns\TextColumn::make('next_charge_at')->label('ניסיון הבא')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('dunning_stage', 'desc')
            ->actions([
                Tables\Actions\Action::make('chargeNow')
                    ->label('חייב עכשיו')->icon('heroicon-o-bolt')->color('warning')
                    ->visible(fn (Subscription $record): bool => $record->isChargeable())
                    ->requiresConfirmation()
                    ->action(function (Subscription $record): void {
                        $record->update(['next_charge_at' => now()]);
                        ChargeSubscriptionJob::dispatch($record->id);
                        Notification::make()->title('החיוב נשלח לביצוע')->success()->send();
                    }),
                Tables\Actions\Action::make('sendCardLink')
                    ->label('קישור לכרטיס')->icon('heroicon-o-credit-card')
                    ->visible(fn (Subscription $record): bool => filled($record->customer->phone ?? $record->customer->email))
                    ->requiresConfirmation()
                    ->action(function (Subscription $record, CardCaptureLinkSender $sender): void {
                        $record->loadMissing(['customer', 'plan']);
                        CustomerResource::notifyLinkResult($sender->send($record));
                    }),
                Tables\Actions\Action::make('viewCustomer')
                    ->label('כרטיס')->icon('heroicon-o-user')->color('gray')
                    ->url(fn (Subscription $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id])),
            ])
            ->paginated([5]);
    }
}
