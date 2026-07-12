<?php

namespace App\Filament\Resources;

use App\Enums\ChargeStatus;
use App\Filament\Resources\ChargeResource\Pages;
use App\Filament\Support\MoneyField;
use App\Models\Charge;
use App\Services\Cardcom\ChargeReconciler;
use App\Services\Linet\InvoiceIssuer;
use App\Services\Linet\LinetClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ChargeResource extends Resource
{
    protected static ?string $model = Charge::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'חיובים';

    protected static ?string $modelLabel = 'חיוב';

    protected static ?string $pluralModelLabel = 'חיובים';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('מנוי וסכום')
                    ->schema([
                        Forms\Components\Select::make('subscription_id')
                            ->label('מנוי')
                            ->relationship('subscription', 'id')
                            ->searchable()
                            ->helperText('ריק עבור חיוב ידני/חד-פעמי.'),
                        MoneyField::make('amount_agorot', 'סכום (₪)')
                            ->required(),
                        MoneyField::make('vat_agorot', 'מע״מ (₪)')
                            ->required()
                            ->default(0),
                        MoneyField::make('total_agorot', 'סה״כ (₪)')
                            ->required(),
                        Forms\Components\TextInput::make('currency')
                            ->label('מטבע')
                            ->required(),
                        Forms\Components\Textarea::make('invoice_notes')
                            ->label('הערות לחשבונית')
                            ->helperText('טקסט חופשי שיודפס בשורת החשבונית. ניתן לעריכה עד להנפקת החשבונית.')
                            ->rows(2)->maxLength(500)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('תוצאת חיוב')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('סטטוס')
                            ->options(ChargeStatus::class)
                            ->required(),
                        Forms\Components\TextInput::make('attempt_number')
                            ->label('מספר ניסיון')
                            ->required()
                            ->numeric()
                            ->default(1),
                        Forms\Components\TextInput::make('cardcom_transaction_id')
                            ->label('מזהה עסקה (קארדקום)'),
                        Forms\Components\TextInput::make('cardcom_response_code')
                            ->label('קוד תגובה'),
                        Forms\Components\TextInput::make('failure_reason')
                            ->label('סיבת כישלון'),
                        Forms\Components\DateTimePicker::make('charged_at')
                            ->label('חויב בתאריך'),
                    ])->columns(2),

                Forms\Components\Section::make('תקופה')
                    ->schema([
                        Forms\Components\DatePicker::make('period_start')
                            ->label('תחילת תקופה')
                            ->required(),
                        Forms\Components\DatePicker::make('period_end')
                            ->label('סוף תקופה')
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('לקוח')
                    // Subscription charges reach the customer via the subscription;
                    // manual/one-off charges link the customer directly.
                    ->getStateUsing(fn (Charge $record): ?string => $record->subscription?->customer?->name ?? $record->customer?->name)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->label('סה״כ')
                    ->money('ILS', divideBy: 100)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    ->color(fn (ChargeStatus $state): string => match ($state) {
                        ChargeStatus::Succeeded => 'success',
                        ChargeStatus::Pending => 'warning',
                        ChargeStatus::Failed => 'danger',
                    }),
                Tables\Columns\TextColumn::make('attempt_number')
                    ->label('ניסיון')
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_start')
                    ->label('תחילת תקופה')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('charged_at')
                    ->label('חויב')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cardcom_transaction_id')
                    ->label('מזהה עסקה (קארדקום)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('cardcom_response_code')
                    ->label('קוד תגובה')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('failure_reason')
                    ->label('סיבת כישלון')
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
            ->defaultSort('created_at', 'desc')
            // Auto-refresh: a charge that Cardcom confirms (webhook/reconcile)
            // flips to "הצליח" on its own, the reconcile button hides, and once
            // the invoice is issued the "הנפק חשבונית" button disappears while
            // the PDF link appears — no manual page refresh needed. Combined with
            // the per-charge issuance lock, an operator can't double-invoice by
            // clicking during the async window.
            ->poll('10s')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('סטטוס')
                    ->options(ChargeStatus::class),
            ])
            ->actions([
                // Recover a charge stuck on "ממתין": ask Cardcom directly whether
                // it went through, and finalise + invoice if so.
                Tables\Actions\Action::make('reconcile')
                    ->label('בדוק מול קארדקום')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    // Manual/one-off charges only: the reconciler looks these up
                    // by low-profile id or the manual-{id} external id.
                    // Subscription charges use a different external id and recover
                    // via the dunning machine, so the button is hidden for them.
                    ->visible(fn (Charge $record): bool => $record->status === ChargeStatus::Pending && $record->subscription_id === null)
                    ->action(function (Charge $record, ChargeReconciler $reconciler): void {
                        try {
                            $status = $reconciler->reconcile($record->fresh());
                        } catch (\Throwable $e) {
                            Notification::make()->title('בדיקת הסטטוס נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                            return;
                        }

                        match ($status) {
                            'succeeded' => Notification::make()->title('החיוב אושר בקארדקום ✓')->body('הסטטוס עודכן ל"הצליח" והחשבונית מונפקת בלינט.')->success()->send(),
                            'failed' => Notification::make()->title('החיוב סומן ככשל')->warning()->send(),
                            default => Notification::make()->title('קארדקום עדיין לא מדווחת על חיוב')->body('ייתכן שהתשלום לא הושלם. נסו שוב בעוד כמה רגעים.')->warning()->send(),
                        };
                    }),

                // Issue (or retry) the Linet invoice for a succeeded charge and
                // show the exact Linet error if it fails — no silent queue retry.
                Tables\Actions\Action::make('issueInvoice')
                    ->label('הנפק חשבונית')
                    ->icon('heroicon-o-document-plus')
                    ->color('success')
                    ->visible(fn (Charge $record): bool => $record->status === ChargeStatus::Succeeded && $record->invoice()->doesntExist())
                    ->action(function (Charge $record, InvoiceIssuer $issuer): void {
                        $result = $issuer->issue($record->fresh());

                        if ($result['ok']) {
                            Notification::make()->title('החשבונית הונפקה בלינט ✓')->success()->send();
                        } else {
                            Notification::make()
                                ->title('הנפקת החשבונית נכשלה')
                                ->body($result['error'].' — בדקו את קודי לינט (סוג מסמך / מע״מ / אמצעי תשלום) בהגדרות → מפתחות → לינט.')
                                ->danger()->persistent()->send();
                        }
                    }),

                // The Linet invoice lives on the charge — open the original PDF
                // straight from here (backfilling the link from Linet when it
                // wasn't captured at issue time).
                Tables\Actions\Action::make('invoicePdf')
                    ->label('חשבונית PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (Charge $record): bool => $record->invoice()->exists())
                    ->action(function (Charge $record, LinetClient $linet) {
                        $invoice = $record->invoice;

                        if (blank($invoice->pdf_url) && filled($invoice->linet_document_id)) {
                            try {
                                $invoice->update(['pdf_url' => $linet->documentPdfUrl($invoice->linet_document_id)]);
                            } catch (\Throwable $e) {
                                Notification::make()->title('שליפת קישור ה-PDF מלינט נכשלה')
                                    ->body(Str::limit($e->getMessage(), 150))->danger()->send();

                                return;
                            }
                        }

                        if (blank($invoice->pdf_url)) {
                            Notification::make()->title('לינט לא החזירה קישור למסמך הזה')->warning()->send();

                            return;
                        }

                        return redirect()->away($invoice->pdf_url);
                    }),

                Tables\Actions\EditAction::make()->label('עריכה'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('מחיקה'),
                ]),
            ])
            ->emptyStateHeading('אין חיובים עדיין')
            ->emptyStateDescription('חיובים נוצרים אוטומטית על ידי מנוע החיוב.');
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
            'index' => Pages\ListCharges::route('/'),
            'create' => Pages\CreateCharge::route('/create'),
            'edit' => Pages\EditCharge::route('/{record}/edit'),
        ];
    }
}
