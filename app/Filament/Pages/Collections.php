<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Support\DebtorActions;
use App\Models\Subscription;
use App\Support\Money;
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
    use RespectsModuleAccess;

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
            ->where(fn ($query) => $query->inArrears()->orWhere(fn ($q) => $q->awaitingCardOverdue()))
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
            // Debtors AND the subscriptions nobody is going to collect: a
            // card customer with no card on file, past their charge date. They
            // never turn past-due — no charge is ever attempted — so without
            // this they owe money while every screen says all is well.
            ->query(Subscription::query()
                ->where(fn ($query) => $query->inArrears()->orWhere(fn ($q) => $q->awaitingCardOverdue()))
                ->with(['customer', 'plan']))
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('plan_name')->label('תוכנית')
                    ->state(fn (Subscription $record): string => $record->planName()),
                // "ממתין לכרטיס" is not a subscription status — it is the
                // absence of one. Showing the raw status here would say
                // "פעיל" next to a customer who owes us money.
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge()
                    ->state(fn (Subscription $record): string => $record->token_id === null
                        ? 'ממתין לכרטיס'
                        : $record->status->getLabel())
                    ->color(fn (Subscription $record): string => match (true) {
                        $record->token_id === null => 'danger',
                        $record->status === SubscriptionStatus::Suspended => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('dunning_stage')->label('שלב דאנינג')->badge()->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('סכום')
                    ->getStateUsing(fn (Subscription $record): string => Money::ils($record->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')
                    ->label('ניסיון הבא')->dateTime('d/m/Y H:i')->placeholder('—')->sortable()
                    ->description(fn (Subscription $record): ?string => $record->token_id === null
                        ? 'לא ייגבה — אין כרטיס שמור'
                        : null),
            ])
            ->defaultSort('dunning_stage', 'desc')
            ->actions([
                DebtorActions::chargeNow(),
                DebtorActions::sendCardLink(),
                DebtorActions::viewCustomer(),
            ])
            ->emptyStateHeading('אין חייבים 🎉')
            ->emptyStateDescription('כל המנויים משולמים ובתוקף, ולכל לקוח שאמור לשלם בכרטיס יש כרטיס שמור.');
    }
}
