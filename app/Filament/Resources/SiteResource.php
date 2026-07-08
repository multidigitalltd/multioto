<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'אתרים';

    protected static ?string $modelLabel = 'אתר';

    protected static ?string $pluralModelLabel = 'אתרים';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('האתר')
                    ->description('לקוח ודומיין')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('לקוח')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('domain')
                            ->label('דומיין')
                            ->required()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('ניטור')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_url')
                            ->label('כתובת לניטור')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('monitor_enabled')
                            ->label('ניטור פעיל')
                            ->inline(false)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('אחסון וסטטוס')
                    ->schema([
                        Forms\Components\TextInput::make('hosting_ref')
                            ->label('מזהה אחסון')
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(SiteStatus::class)
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->label('דומיין')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('monitor_url')
                    ->label('כתובת לניטור')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('monitor_enabled')
                    ->label('ניטור פעיל')
                    ->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (SiteStatus $state): string => match ($state) {
                        SiteStatus::Active => 'success',
                        SiteStatus::Suspended => 'danger',
                    }),
                Tables\Columns\TextColumn::make('hosting_ref')
                    ->label('מזהה אחסון')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('domain', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(SiteStatus::class),
                Tables\Filters\TernaryFilter::make('monitor_enabled')
                    ->label('ניטור פעיל'),
            ])
            ->actions([
                Tables\Actions\Action::make('suspend')
                    ->label('השהה')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->visible(fn (Site $record): bool => $record->status === SiteStatus::Active
                        && self::hostingActionable($record))
                    ->requiresConfirmation()
                    ->modalHeading('השהיית אתר')
                    ->modalDescription(fn (Site $record): string => "להשהות את {$record->domain}? האתר יעבור למצב תחזוקה אצל ספק האחסון.")
                    ->modalSubmitActionLabel('השהה')
                    ->action(function (Site $record): void {
                        SuspendSiteJob::dispatch($record->id);

                        Notification::make()
                            ->title('ההשהיה נשלחה לביצוע')
                            ->body('הסטטוס יתעדכן תוך רגעים.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('restore')
                    ->label('שחזר')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (Site $record): bool => $record->status === SiteStatus::Suspended
                        && self::hostingActionable($record))
                    ->requiresConfirmation()
                    ->modalHeading('שחזור אתר')
                    ->modalDescription(fn (Site $record): string => "לשחזר את {$record->domain} לפעילות מלאה?")
                    ->modalSubmitActionLabel('שחזר')
                    ->action(function (Site $record): void {
                        RestoreSiteJob::dispatch($record->id);

                        Notification::make()
                            ->title('השחזור נשלח לביצוע')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין אתרים עדיין')
            ->emptyStateDescription('הקימו אתר חדש דרך "אתר חדש" בתפריט.');
    }

    /**
     * Whether the suspend/restore quick actions can actually take effect. The
     * real FlyWP driver needs a linked site (hosting_ref); the 'log' driver just
     * records intent, so it's always actionable. Prevents enqueuing a job that
     * would fail while the UI reports success.
     */
    protected static function hostingActionable(Site $record): bool
    {
        if (config('billing.hosting.driver') === 'flywp') {
            return filled($record->hosting_ref);
        }

        return true;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
