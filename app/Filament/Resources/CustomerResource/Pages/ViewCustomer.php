<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Billing\ManualChargeService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\URL;
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
            $this->cardLinkAction(),
            Actions\EditAction::make()->label('עריכה'),
        ];
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
            ])
            ->action(function (array $data, Customer $record, ManualChargeService $service): void {
                $totalAgorot = (int) round(((float) $data['amount']) * 100);

                if ($totalAgorot <= 0) {
                    Notification::make()->title('סכום לא תקין')->danger()->send();

                    return;
                }

                $description = filled($data['description']) ? $data['description'] : 'חיוב חד-פעמי';

                if ($service->hasActiveToken($record)) {
                    $service->chargeSavedToken($record, $totalAgorot, $description);
                    Notification::make()
                        ->title('החיוב נשלח לעיבוד')
                        ->body('הכרטיס השמור של '.$record->name.' יחויב בסך ₪'.number_format($totalAgorot / 100, 2).'. עקבו בעמוד "חיובים".')
                        ->success()->persistent()->send();

                    return;
                }

                // No saved card → open a hosted payment page to enter/send.
                try {
                    $result = $service->createHostedPage($record, $totalAgorot, $description);
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
                'link' => URL::temporarySignedRoute(
                    'billing.update-card',
                    now()->addHours((int) config('billing.card_update_link_ttl_hours')),
                    ['customer' => $record->id],
                ),
            ])
            ->form([
                Forms\Components\TextInput::make('link')->label('קישור')->readOnly()->columnSpanFull(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('סגור');
    }
}
