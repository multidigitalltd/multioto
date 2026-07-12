<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * גבייה — ריכוז כל הלקוחות שלא שילמו: מנויים בפיגור (past_due) או מושהים
 * (suspended), עם השלב בדאנינג, מתי הניסיון הבא, וכפתורי פעולה מהירים (חיוב
 * מיידי / שליחת קישור לעדכון כרטיס). מקום אחד לראות למי צריך "לרדוף".
 */
class Collections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'גבייה (חייבים)';

    protected static ?string $title = 'גבייה — לקוחות שלא שילמו';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.collections';

    /** Show a red count badge in the nav when there are debtors. */
    public static function getNavigationBadge(): ?string
    {
        $count = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
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
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('plan.name')->label('תוכנית'),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge()
                    ->color(fn (SubscriptionStatus $state): string => $state === SubscriptionStatus::Suspended ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('סכום')
                    ->getStateUsing(fn (Subscription $record): string => Money::ils($record->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')->label('ניסיון הבא')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
            ])
            ->defaultSort('dunning_stage', 'desc')
            ->actions([
                Tables\Actions\Action::make('chargeNow')
                    ->label('חייב עכשיו')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (Subscription $record): bool => $record->isChargeable())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Subscription $record): string => 'לחייב את '.$record->customer->name.' בסך '.Money::ils($record->totalChargeAgorot()).' עכשיו?')
                    ->action(function (Subscription $record): void {
                        $record->update(['next_charge_at' => now()]);
                        ChargeSubscriptionJob::dispatch($record->id);
                        Notification::make()->title('החיוב נשלח לביצוע')->body('התוצאה תופיע במסך "חיובים".')->success()->send();
                    }),

                Tables\Actions\Action::make('sendCardLink')
                    ->label('קישור לכרטיס')
                    ->icon('heroicon-o-credit-card')
                    ->visible(fn (Subscription $record): bool => filled($record->customer->phone ?? $record->customer->email))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Subscription $record): string => 'לשלוח ל-'.$record->customer->name.' קישור לעדכון כרטיס (וואטסאפ + מייל)?')
                    ->action(function (Subscription $record, CardCaptureLinkSender $sender): void {
                        $record->loadMissing(['customer', 'plan']);
                        CustomerResource::notifyLinkResult($sender->send($record));
                    }),

                Tables\Actions\Action::make('viewCustomer')
                    ->label('כרטיס לקוח')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->url(fn (Subscription $record): string => CustomerResource::getUrl('view', ['record' => $record->customer_id])),
            ])
            ->emptyStateHeading('אין חייבים 🎉')
            ->emptyStateDescription('כל המנויים משולמים ובתוקף.');
    }
}
