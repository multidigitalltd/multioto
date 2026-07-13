<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Customer;
use App\Services\Billing\ManualChargeService;
use App\Support\CardLink;
use App\Support\Money;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

/**
 * Customer 360° page — everything about a customer in one place, with quick
 * actions (charge, card link, edit) so the team doesn't hop between screens.
 * Subscriptions and sites are edited inline via the relation managers below.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->chargeAction(),
            $this->paymentLinkAction(),
            $this->cardLinkAction(),
            Actions\EditAction::make()->label('עריכה'),
        ];
    }

    /** Create a hosted payment page for an amount and send the link to the customer. */
    private function paymentLinkAction(): Actions\Action
    {
        return Actions\Action::make('paymentLink')
            ->label('שליחת קישור תשלום')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label('סכום לתשלום (₪, כולל מע״מ)')
                    ->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')->required(),
                Forms\Components\TextInput::make('description')
                    ->label('עבור (יופיע ללקוח ובחשבונית)')->default('תשלום')->maxLength(120)->required(),
                Forms\Components\Radio::make('channel')
                    ->label('לשלוח דרך')
                    ->options(['whatsapp' => 'וואטסאפ', 'email' => 'מייל'])
                    ->default('whatsapp')
                    ->required(),
            ])
            ->action(function (array $data, Customer $record): void {
                $totalAgorot = (int) round(((float) $data['amount']) * 100);

                if ($totalAgorot <= 0) {
                    Notification::make()->title('סכום לא תקין')->danger()->send();

                    return;
                }

                $channel = $data['channel'] ?? 'whatsapp';
                $missing = $channel === 'email' ? blank($record->email) : (blank($record->whatsapp_jid) && blank($record->phone));

                if ($missing) {
                    Notification::make()->title('אין ללקוח פרטי '.($channel === 'email' ? 'מייל' : 'וואטסאפ'))->danger()->send();

                    return;
                }

                SendPaymentLinkJob::dispatch($record->id, $totalAgorot, filled($data['description']) ? $data['description'] : 'תשלום', $channel);

                Notification::make()
                    ->title('קישור התשלום נשלח')
                    ->body('הקישור נוצר ונשלח ל'.$record->name.' ב'.($channel === 'email' ? 'מייל' : 'וואטסאפ').'. עם התשלום ייווצר חיוב ותונפק חשבונית אוטומטית.')
                    ->success()->send();
            });
    }

    /** One-off charge for this customer — saved card now, or a hosted page link. */
    private function chargeAction(): Actions\Action
    {
        return Actions\Action::make('newCharge')
            ->label('חיוב חדש')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label('סכום לחיוב (₪, כולל מע״מ)')
                    ->numeric()->prefix('₪')->step('0.01')->minValue(0.1)->inputMode('decimal')->required(),
                Forms\Components\TextInput::make('description')
                    ->label('תיאור (יופיע בחשבונית)')->default('חיוב חד-פעמי')->maxLength(120)->required(),
                Forms\Components\Textarea::make('invoice_notes')
                    ->label('הערות לחשבונית (אופציונלי)')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Customer $record, ManualChargeService $service): void {
                $totalAgorot = (int) round(((float) $data['amount']) * 100);

                if ($totalAgorot <= 0) {
                    Notification::make()->title('סכום לא תקין')->danger()->send();

                    return;
                }

                $description = filled($data['description']) ? $data['description'] : 'חיוב חד-פעמי';
                $notes = filled($data['invoice_notes'] ?? null) ? trim((string) $data['invoice_notes']) : null;

                if ($service->hasActiveToken($record)) {
                    $service->chargeSavedToken($record, $totalAgorot, $description, $notes);
                    Notification::make()
                        ->title('החיוב נשלח לעיבוד')
                        ->body('הכרטיס השמור של '.$record->name.' יחויב בסך '.Money::ils($totalAgorot).'. עקבו בעמוד "חיובים".')
                        ->success()->persistent()->send();

                    return;
                }

                // No saved card → open a hosted payment page to enter/send.
                try {
                    $result = $service->createHostedPage($record, $totalAgorot, $description, $notes);
                } catch (\Throwable $e) {
                    Notification::make()->title('פתיחת עמוד התשלום נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('ללקוח אין כרטיס שמור — נוצר עמוד תשלום')
                    ->body('פִּתחו את עמוד התשלום להזנת כרטיס, או העתיקו ושִלחו את הקישור ללקוח: '.$result['url'])
                    ->success()->persistent()
                    ->actions([
                        NotificationAction::make('open')->label('פתח עמוד תשלום')->url($result['url'], shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    /** Show the signed card-capture link to copy/open. */
    private function cardLinkAction(): Actions\Action
    {
        return Actions\Action::make('cardLink')
            ->label('קישור לכרטיס')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->modalHeading('קישור מאובטח להזנת כרטיס')
            ->modalDescription('העתיקו ושִלחו ללקוח, או פִּתחו בעצמכם. הכרטיס מוזן בעמוד המאובטח של קארדקום.')
            ->fillForm(fn (Customer $record): array => [
                'link' => CardLink::for($record->id),
            ])
            ->form([CustomerResource::cardLinkField()])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('סגור');
    }
}
