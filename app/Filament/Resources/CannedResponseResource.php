<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CannedResponseResource\Pages;
use App\Models\CannedResponse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CannedResponseResource extends Resource
{
    protected static ?string $model = CannedResponse::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = 'תבניות מענה';

    protected static ?string $modelLabel = 'תבנית מענה';

    protected static ?string $pluralModelLabel = 'תבניות מענה';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('תבנית מענה')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('כותרת')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('body')
                            ->label('תוכן')
                            ->required()
                            ->rows(6)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('tags')
                            ->label('תגיות')
                            ->helperText('תגיות מופרדות בפסיקים')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('כותרת')
                    ->searchable()
                    ->weight('bold'),
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
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין תבניות מענה עדיין');
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
            'index' => Pages\ListCannedResponses::route('/'),
            'create' => Pages\CreateCannedResponse::route('/create'),
            'edit' => Pages\EditCannedResponse::route('/{record}/edit'),
        ];
    }
}
