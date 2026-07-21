<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Manage a customer's contact people — each with a role and optional site scope
 * — from the customer card. Their email / phone / WhatsApp identifiers let an
 * inbound message from any of them resolve to this customer.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'אנשי קשר';

    // Keep it interactive on the customer's view page too (Filament makes
    // relation managers read-only on ViewRecord pages by default).
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('שם')->required()->maxLength(255),
            Forms\Components\TextInput::make('role')->label('תפקיד')
                ->maxLength(255)
                ->helperText('למשל: בעלים, מנהל/ת, איש קשר טכני, כספים/הנהח״ש.'),
            Forms\Components\TextInput::make('email')->label('אימייל')->email()->maxLength(255),
            Forms\Components\TextInput::make('phone')->label('טלפון')->tel()->maxLength(50)
                ->helperText('בפורמט בינ״ל (למשל +9725…) כדי שהתאמת פניות נכנסות תעבוד.'),
            Forms\Components\Select::make('site_id')->label('אתר (אופציונלי)')
                ->relationship('site', 'domain')
                // Only this customer's sites are relevant.
                ->options(fn (): array => $this->getOwnerRecord()->sites()->orderBy('domain')->pluck('domain', 'id')->all())
                ->searchable()->preload()
                ->helperText('שייכו את איש הקשר לאתר מסוים של הלקוח, או השאירו ריק לרמת הלקוח.'),
            Forms\Components\Toggle::make('is_primary')->label('איש קשר ראשי'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('שם')->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('תפקיד')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('email')->label('אימייל')->placeholder('—')->copyable(),
                Tables\Columns\TextColumn::make('phone')->label('טלפון')->placeholder('—')->copyable(),
                Tables\Columns\TextColumn::make('site.domain')->label('אתר')->placeholder('כל הלקוח')->toggleable(),
                Tables\Columns\IconColumn::make('is_primary')->label('ראשי')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('איש קשר חדש'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\DeleteAction::make()->label('מחיקה'),
            ]);
    }
}
