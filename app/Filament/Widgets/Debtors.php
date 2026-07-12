<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Filament\Support\DebtorActions;
use App\Models\Subscription;
use App\Support\Money;
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
        return Subscription::query()->inArrears()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Subscription::query()->inArrears()->with(['customer', 'plan']))
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->weight('bold'),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge()
                    ->color(fn (SubscriptionStatus $state): string => $state === SubscriptionStatus::Suspended ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('סכום')
                    ->getStateUsing(fn (Subscription $record): string => Money::ils($record->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')->label('ניסיון הבא')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('dunning_stage', 'desc')
            ->actions([
                DebtorActions::chargeNow(),
                DebtorActions::sendCardLink(),
                DebtorActions::viewCustomer(),
            ])
            ->paginated([5]);
    }
}
