<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Enums\TokenStatus;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Services\Cardcom\CardTokenService;
use App\Support\CardLink;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's saved cards, and the two things a person actually needs to do
 * with them: say which one is the live card, and take one off the file.
 *
 * Until this existed the list was read-only, so a customer who replaced an
 * expired card had no way back if the wiring went wrong — the panel showed the
 * new card sitting there marked "פעיל" while every charge went to the old one,
 * and the only repair was a database edit.
 *
 * No card number is shown or stored anywhere here: brand, last four digits and
 * the printed expiry are everything we hold (the token itself is hidden on the
 * model). Capturing a card still happens only on Cardcom's hosted page.
 */
class PaymentTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentTokens';

    protected static ?string $title = 'כרטיסי אשראי';

    protected static ?string $icon = 'heroicon-o-credit-card';

    /** Cards are money: the finance module gates this tab exactly as it gates subscriptions. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->canAccessModule('finance') ?? false;
    }

    // Relation managers go read-only on a ViewRecord page by default, and the
    // customer 360° page is where this list is used.
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('card_last4')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('card_brand')->label('סוג')->placeholder('—'),
                Tables\Columns\TextColumn::make('card_last4')->label('4 ספרות אחרונות')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '****'.$state : '—'),
                // The expiry is read from what was captured, not from the status
                // column — nothing restamps a card the day it expires, so the
                // status alone would keep calling a dead card active.
                Tables\Columns\TextColumn::make('expiry')->label('תוקף')
                    ->state(fn (PaymentToken $record): string => $record->expiryLabel() ?? '—')
                    ->badge()
                    ->color(fn (PaymentToken $record): string => $record->hasExpired() ? 'danger' : 'gray')
                    ->description(fn (PaymentToken $record): ?string => $record->hasExpired() ? 'פג תוקף — חיוב בכרטיס הזה יידחה' : null),
                Tables\Columns\TextColumn::make('status')->label('סטטוס')->badge(),
                Tables\Columns\IconColumn::make('is_default')->label('כרטיס פעיל')
                    ->state(fn (PaymentToken $record): bool => $this->isDefault($record))
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')->trueColor('success')
                    ->falseIcon('heroicon-o-minus-small')->falseColor('gray'),
                Tables\Columns\TextColumn::make('created_at')->label('נשמר')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addCard')
                    ->label('הוספת כרטיס')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    // Cardcom's hosted page — a card number never reaches us.
                    ->url(fn (): string => CardLink::for($this->getOwnerRecord()->id), shouldOpenInNewTab: true),
            ])
            ->actions([
                Tables\Actions\Action::make('makeDefault')
                    ->label('הפוך לפעיל')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // A removed card has no token left to charge, so it is not
                    // offered as a choice — the way back is entering the card
                    // again, not flipping a status.
                    ->visible(fn (PaymentToken $record): bool => filled($record->cardcom_token)
                        && (! $this->isDefault($record) || $record->status !== TokenStatus::Active))
                    ->requiresConfirmation()
                    ->modalHeading('הפיכת הכרטיס לכרטיס הפעיל')
                    // Quote the actual card and the actual consequences rather
                    // than "האם אתם בטוחים?" — this decides which card gets
                    // charged next month.
                    ->modalDescription(fn (PaymentToken $record): string => sprintf(
                        '%s יהפוך לכרטיס הפעיל של הלקוח. כל המנויים הפעילים יחויבו מעכשיו בכרטיס הזה, וכרטיסים אחרים יסומנו כ"הוחלף".%s',
                        $record->label(),
                        $record->hasExpired()
                            ? ' ⚠️ הכרטיס מסומן כפג תוקף — אם התוקף נכון, החיוב יידחה.'
                            : '',
                    ))
                    ->modalSubmitActionLabel('הפוך לפעיל')
                    ->action(function (PaymentToken $record, CardTokenService $tokens): void {
                        // collectNow: false — choosing which saved card is the
                        // live one is a bookkeeping decision. It must not, on
                        // its own, end a trial or take money; the scheduler
                        // collects on its own terms once a live card is wired.
                        $tokens->makeDefault($this->owner(), $record, collectNow: false);

                        Notification::make()
                            ->title('הכרטיס הפעיל עודכן')
                            ->body($record->label().' — כל המנויים הפעילים הופנו לכרטיס הזה.')
                            ->success()->send();
                    }),
                Tables\Actions\Action::make('detach')
                    ->label('הסרת כרטיס')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (PaymentToken $record): bool => $record->status !== TokenStatus::Removed)
                    ->requiresConfirmation()
                    ->modalHeading('הסרת כרטיס מהתיק')
                    ->modalDescription(fn (PaymentToken $record): string => sprintf(
                        '%s יוסר מהמנויים שמצביעים עליו והטוקן יימחק — לא ניתן לחייב בו יותר ולא ניתן לשחזר אותו (להחזרתו יש להזין את הכרטיס מחדש). ההיסטוריה והחיובים שבוצעו בו נשמרים.%s',
                        $record->label(),
                        $this->lastUsableCard($record)
                            ? ' ⚠️ זה הכרטיס האחרון של הלקוח — לא יישאר כרטיס לגבייה, והמנויים יופיעו במסך "ממתין לכרטיס".'
                            : '',
                    ))
                    ->modalSubmitActionLabel('הסר כרטיס')
                    ->action(function (PaymentToken $record, CardTokenService $tokens): void {
                        $tokens->detach($this->owner(), $record);

                        Notification::make()
                            ->title('הכרטיס הוסר')
                            ->body($record->label().' — לא יחויב יותר.')
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('אין כרטיס שמור')
            ->emptyStateDescription('הוסיפו כרטיס בכפתור למעלה — הכרטיס מוזן בעמוד המאובטח של קארדקום, ואף פרט ממנו אינו נשמר אצלנו.');
    }

    private function owner(): Customer
    {
        /** @var Customer $customer */
        $customer = $this->getOwnerRecord();

        return $customer;
    }

    private function isDefault(PaymentToken $token): bool
    {
        return (int) $this->owner()->default_token_id === (int) $token->id;
    }

    /** Would removing this card leave the customer with nothing to charge? */
    private function lastUsableCard(PaymentToken $token): bool
    {
        return ! $this->owner()->paymentTokens()
            ->whereKeyNot($token->id)
            ->where('status', TokenStatus::Active)
            ->exists();
    }
}
