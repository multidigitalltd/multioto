<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Edit a customer's subscriptions directly from the customer card — no jumping
 * to a separate screen. Creating a full subscription still goes through the
 * onboarding wizard (it needs a billing period), so this manager is edit-first.
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'מנויים';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('plan_id')->label('תוכנית')->relationship('plan', 'name')->required(),
            Forms\Components\Select::make('status')->label('סטטוס')->options(SubscriptionStatus::class)->required(),
            Forms\Components\DateTimePicker::make('next_charge_at')->label('חיוב הבא'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('plan.name')->label('תוכנית'),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('next_charge_at')->label('חיוב הבא')->dateTime('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge(),
            ])
            ->actions([
                Tables\Actions\Action::make('chargeNow')
                    ->label('חייב עכשיו')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (Subscription $record): bool => $record->isChargeable())
                    ->requiresConfirmation()
                    ->action(function (Subscription $record): void {
                        $record->update(['next_charge_at' => now()]);
                        ChargeSubscriptionJob::dispatch($record->id);
                        Notification::make()->title('החיוב נשלח לביצוע')->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ]);
    }
}
