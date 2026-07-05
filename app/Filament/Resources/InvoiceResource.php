<?php

namespace App\Filament\Resources;

use App\Enums\DocumentType;
use App\Enums\VatCategory;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'חשבוניות';

    protected static ?string $modelLabel = 'חשבונית';

    protected static ?string $pluralModelLabel = 'חשבוניות';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('charge_id')
                    ->relationship('charge', 'id')
                    ->required(),
                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->required(),
                Forms\Components\TextInput::make('linet_document_id')
                    ->required(),
                Forms\Components\Select::make('document_type')
                    ->options(DocumentType::class)
                    ->required(),
                Forms\Components\TextInput::make('allocation_number'),
                Forms\Components\Select::make('vat_category')
                    ->options(VatCategory::class)
                    ->required(),
                Forms\Components\TextInput::make('amount_agorot')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('vat_agorot')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_agorot')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('pdf_url'),
                Forms\Components\DateTimePicker::make('issued_at')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('charge.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('linet_document_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('allocation_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vat_category')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vat_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pdf_url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
