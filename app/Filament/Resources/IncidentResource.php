<?php

namespace App\Filament\Resources;

use App\Enums\IncidentStatus;
use App\Filament\Resources\IncidentResource\Pages;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'תקלות';

    protected static ?string $modelLabel = 'תקלה';

    protected static ?string $pluralModelLabel = 'תקלות';

    protected static ?string $navigationGroup = 'ניטור';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('תקלה')
                    ->schema([
                        Forms\Components\Select::make('site_id')
                            ->label('אתר')
                            ->relationship('site', 'domain')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(IncidentStatus::class)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('זמנים')
                    ->schema([
                        Forms\Components\DateTimePicker::make('started_at')
                            ->label('התחיל בתאריך')
                            ->required(),
                        Forms\Components\DateTimePicker::make('resolved_at')
                            ->label('טופל בתאריך'),
                    ])->columns(2),

                Forms\Components\Section::make('קשרים')
                    ->schema([
                        Forms\Components\Select::make('broadcast_id')
                            ->label('דיוור מקושר')
                            ->relationship('broadcast', 'subject')
                            ->searchable()
                            ->preload(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (IncidentStatus $state): string => match ($state) {
                        IncidentStatus::Open => 'danger',
                        IncidentStatus::Resolved => 'success',
                    }),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('התחיל')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('טופל')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('עודכן')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(IncidentStatus::class),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין תקלות עדיין');
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
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
        ];
    }
}
