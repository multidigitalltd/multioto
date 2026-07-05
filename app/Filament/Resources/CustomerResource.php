<?php

namespace App\Filament\Resources;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'לקוחות';

    protected static ?string $modelLabel = 'לקוח';

    protected static ?string $pluralModelLabel = 'לקוחות';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פרטי הלקוח')
                    ->description('שם ופרטי העסק')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם הלקוח / העסק')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('business_number')
                            ->label('ח.פ / עוסק')
                            ->maxLength(20),
                        Forms\Components\Select::make('business_type')
                            ->label('סוג עסק')
                            ->options(BusinessType::class)
                            ->required(),
                        Forms\Components\Toggle::make('vat_exempt')
                            ->label('פטור ממע״מ')
                            ->helperText('חשבוניות יונפקו ללא מע״מ')
                            ->inline(false),
                    ])->columns(2),

                Forms\Components\Section::make('פרטי התקשרות')
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('טלפון')
                            ->tel(),
                        Forms\Components\TextInput::make('email')
                            ->label('אימייל')
                            ->email(),
                        Forms\Components\TextInput::make('whatsapp_jid')
                            ->label('מזהה וואטסאפ')
                            ->helperText('אופציונלי — מזוהה אוטומטית מהודעות נכנסות')
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('סטטוס והערות')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(CustomerStatus::class)
                            ->default(CustomerStatus::Active)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('הערות פנימיות')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('שם הלקוח')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('טלפון')
                    ->searchable()
                    ->icon('heroicon-m-phone'),
                Tables\Columns\TextColumn::make('email')
                    ->label('אימייל')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('business_type')
                    ->label('סוג עסק')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('vat_exempt')
                    ->label('פטור ממע״מ')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (CustomerStatus $state): string => match ($state) {
                        CustomerStatus::Active => 'success',
                        CustomerStatus::Suspended => 'warning',
                        CustomerStatus::Churned => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(CustomerStatus::class),
                Tables\Filters\TernaryFilter::make('vat_exempt')
                    ->label('פטור ממע״מ'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין לקוחות עדיין')
            ->emptyStateDescription('הקימו לקוח חדש דרך "לקוח חדש" בתפריט.');
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
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
