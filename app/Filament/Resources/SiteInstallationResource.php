<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Models\AuditLog;
use App\Models\SiteInstallation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * התקנות שאנחנו עושים ללקוח — the queue, and the access they handed over.
 *
 * A customer who ticked "install it for me" has paid and is waiting. Without a
 * list they wait in somebody's inbox, and the product's first impression is a
 * week of silence after a successful payment.
 *
 * The access itself is handled the way a credential has to be: never on the
 * table, revealed by a deliberate action that is recorded, and wiped the moment
 * the install is marked done. What is on screen is what somebody needs in order
 * to pick up the work — whose site, how old, and whether the access is here.
 */
class SiteInstallationResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = SiteInstallation::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'התקנות לקוחות';

    protected static ?string $modelLabel = 'התקנה';

    protected static ?string $pluralModelLabel = 'התקנות לקוחות';

    /** Work waiting, on the navigation itself. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::query()->where('state', SiteInstallation::READY)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('domain')
                ->label('אתר')
                ->content(fn (?SiteInstallation $record): string => $record?->domain ?? '—'),
            Forms\Components\Placeholder::make('customer')
                ->label('לקוח')
                ->content(fn (?SiteInstallation $record): string => $record?->customer?->name ?? '—'),
            Forms\Components\Select::make('state')
                ->label('מצב')
                ->options(SiteInstallation::STATE_LABELS)
                ->required(),
            Forms\Components\Textarea::make('access_note')
                ->label('הערה מהלקוח')
                ->rows(3)
                ->maxLength(500)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer:id,name', 'installer:id,name']))
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->label('אתר')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('state')
                    ->label('מצב')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SiteInstallation::STATE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SiteInstallation::READY => 'warning',
                        SiteInstallation::INSTALLED => 'success',
                        SiteInstallation::FAILED => 'danger',
                        default => 'gray',
                    }),
                // Whether the credential is here, WITHOUT showing it. The three
                // states are different jobs: chase the customer, do the install,
                // or nothing — and a blank column would read as the first in all
                // three cases.
                Tables\Columns\TextColumn::make('access_secret')
                    ->label('גישה')
                    ->state(fn (SiteInstallation $record): string => match (true) {
                        $record->hasAccess() && $record->accessExpired() => 'התקבלה — פג תוקף',
                        $record->hasAccess() => 'התקבלה',
                        $record->access_cleared_at !== null => 'נמחקה',
                        default => 'טרם התקבלה',
                    })
                    ->color(fn (SiteInstallation $record): string => match (true) {
                        $record->hasAccess() && $record->accessExpired() => 'danger',
                        $record->hasAccess() => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('ממתין מאז')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('installer.name')
                    ->label('הותקן על ידי')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('open')
                    ->label('רק מה שממתין')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->open()),
                Tables\Filters\SelectFilter::make('state')
                    ->label('מצב')
                    ->options(SiteInstallation::STATE_LABELS),
            ])
            ->actions([
                Tables\Actions\Action::make('reveal')
                    ->label('הצגת הגישה')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (SiteInstallation $record): bool => $record->hasAccess())
                    // Asked for out loud. This is somebody's WordPress admin
                    // access, and a one-click reveal is a value that ends up on
                    // a shared screen without anybody deciding to put it there.
                    ->requiresConfirmation()
                    ->modalHeading('הצגת פרטי הגישה של הלקוח')
                    ->modalDescription('הצפייה נרשמת ביומן. אל תעתיקו את הפרטים לשום מקום — הם נמחקים אצלנו בתום ההתקנה.')
                    ->modalSubmitActionLabel('הצג')
                    ->modalContent(fn (SiteInstallation $record) => view(
                        'filament.modals.site-installation-access',
                        ['installation' => $record],
                    ))
                    ->action(function (SiteInstallation $record): void {
                        // The credential itself is deliberately NOT in the
                        // payload: an audit trail that copies the secret is a
                        // second, permanent place it lives.
                        AuditLog::record(
                            'access_revealed',
                            "צפייה בפרטי גישה לאתר {$record->domain}",
                            $record,
                            ['method' => $record->access_method],
                        );
                    }),

                Tables\Actions\Action::make('installed')
                    ->label('הותקן')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SiteInstallation $record): bool => ! $record->isClosed())
                    ->requiresConfirmation()
                    ->modalHeading('סימון ההתקנה כהושלמה')
                    ->modalDescription('פרטי הגישה של הלקוח יימחקו מיד ולא ניתן יהיה לשחזר אותם.')
                    ->modalSubmitActionLabel('הותקן — מחקו את הגישה')
                    ->action(function (SiteInstallation $record): void {
                        $record->forceFill([
                            'state' => SiteInstallation::INSTALLED,
                            'installed_at' => now(),
                            'installed_by' => auth()->id(),
                        ])->save();

                        // The whole point of the column: it is empty again the
                        // moment it stops being needed.
                        $record->clearAccess();

                        Notification::make()->title('ההתקנה סומנה כהושלמה')
                            ->body('פרטי הגישה נמחקו.')->success()->send();
                    }),

                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->emptyStateHeading('אין התקנות ממתינות')
            ->emptyStateDescription('כשלקוח יבקש שנתקין עבורו, זה יופיע כאן.');
    }

    public static function getPages(): array
    {
        return [
            'index' => SiteInstallationResource\Pages\ListSiteInstallations::route('/'),
            'edit' => SiteInstallationResource\Pages\EditSiteInstallation::route('/{record}/edit'),
        ];
    }
}
