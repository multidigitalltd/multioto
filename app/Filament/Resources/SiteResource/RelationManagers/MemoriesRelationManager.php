<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The site's durable memory — key/value notes the agent and the team keep about
 * this exact site (quirks, past fixes, context). Never for secrets.
 */
class MemoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'memories';

    protected static ?string $title = 'זיכרון האתר';

    protected static ?string $icon = 'heroicon-o-light-bulb';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')
                ->label('נושא')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('value')
                ->label('תוכן')
                ->rows(4)
                ->maxLength(5000)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('נושא')->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('value')->label('תוכן')->limit(80)->wrap(),
                Tables\Columns\TextColumn::make('updated_by')->label('עודכן ע״י')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('עודכן')->since()->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('הוספת פתק')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['updated_by'] = auth()->user()?->name;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('עריכה')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['updated_by'] = auth()->user()?->name;

                        return $data;
                    }),
                Tables\Actions\DeleteAction::make()->label('מחיקה'),
            ])
            ->emptyStateHeading('אין עדיין זיכרון לאתר הזה');
    }
}
