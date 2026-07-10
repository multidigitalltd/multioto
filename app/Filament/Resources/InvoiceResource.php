<?php

namespace App\Filament\Resources;

use App\Enums\DocumentType;
use App\Enums\VatCategory;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Support\MoneyField;
use App\Models\Invoice;
use App\Services\Linet\LinetClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'חשבוניות';

    protected static ?string $modelLabel = 'חשבונית';

    protected static ?string $pluralModelLabel = 'חשבוניות';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?int $navigationSort = 2;

    // Invoices are reached from their charge (חשבונית PDF action) and from the
    // customer card — a separate tab duplicated the same data, so it's hidden.
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('מסמך')
                    ->schema([
                        Forms\Components\Select::make('charge_id')
                            ->label('חיוב')
                            ->relationship('charge', 'id')
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('customer_id')
                            ->label('לקוח')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->required(),
                        Forms\Components\TextInput::make('linet_document_id')
                            ->label('מספר מסמך לינט')
                            ->required(),
                        Forms\Components\Select::make('document_type')
                            ->label('סוג מסמך')
                            ->options(DocumentType::class)
                            ->required(),
                        Forms\Components\TextInput::make('allocation_number')
                            ->label('מספר הקצאה'),
                        Forms\Components\Select::make('vat_category')
                            ->label('קטגוריית מע״מ')
                            ->options(VatCategory::class)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('סכומים')
                    ->schema([
                        MoneyField::make('amount_agorot', 'סכום (₪)')
                            ->required(),
                        MoneyField::make('vat_agorot', 'מע״מ (₪)')
                            ->required()
                            ->default(0),
                        MoneyField::make('total_agorot', 'סה״כ (₪)')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('קובץ')
                    ->schema([
                        Forms\Components\TextInput::make('pdf_url')
                            ->label('קובץ PDF'),
                        Forms\Components\DateTimePicker::make('issued_at')
                            ->label('הונפק בתאריך')
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
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('linet_document_id')
                    ->label('מספר מסמך')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->label('סוג מסמך')
                    ->badge(),
                Tables\Columns\TextColumn::make('vat_category')
                    ->label('קטגוריית מע״מ')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->label('סה״כ')
                    ->money('ILS', divideBy: 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('הונפק')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pdf_url')
                    ->label('PDF')
                    ->formatStateUsing(fn () => 'פתיחה')
                    ->url(fn ($record) => $record->pdf_url)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->toggleable(),
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
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->label('סוג מסמך')
                    ->options(DocumentType::class),
                Tables\Filters\SelectFilter::make('vat_category')
                    ->label('קטגוריית מע״מ')
                    ->options(VatCategory::class),
            ])
            ->actions([
                Tables\Actions\Action::make('downloadPdf')
                    ->label('הורדת PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (Invoice $record): bool => filled($record->linet_document_id))
                    ->action(function (Invoice $record, LinetClient $linet) {
                        // Backfill the download link from Linet when it wasn't
                        // captured at issue time, then open it.
                        if (blank($record->pdf_url)) {
                            try {
                                $record->update(['pdf_url' => $linet->documentPdfUrl($record->linet_document_id)]);
                            } catch (\Throwable $e) {
                                Notification::make()->title('שליפת קישור ה-PDF מלינט נכשלה')
                                    ->body(Str::limit($e->getMessage(), 150))->danger()->send();

                                return;
                            }
                        }

                        if (blank($record->pdf_url)) {
                            Notification::make()->title('לינט לא החזירה קישור למסמך הזה')->warning()->send();

                            return;
                        }

                        return redirect()->away($record->pdf_url);
                    }),
                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין חשבוניות עדיין')
            ->emptyStateDescription('חשבוניות מונפקות אוטומטית לאחר חיוב מוצלח.');
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
