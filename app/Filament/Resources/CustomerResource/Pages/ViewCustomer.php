<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\CustomerResource;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Billing\ManualChargeService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\CardTokenService;
use App\Services\Support\AgentReply;
use App\Services\Waha\WahaClient;
use App\Support\CardLink;
use App\Support\EmailBody;
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
            $this->contactCustomerAction(),
            $this->chargeAction(),
            $this->paymentLinkAction(),
            $this->cardLinkAction(),
            $this->syncCardAction(),
            Actions\EditAction::make()->label('עריכה'),
        ];
    }

    /**
     * Proactively reach out to the customer: open a support ticket AND send the
     * first message in one step, so the team can ask the customer something and
     * the reply threads back onto the same ticket. Uses the shared AgentReply so
     * it behaves exactly like any other outbound reply.
     */
    private function contactCustomerAction(): Actions\Action
    {
        return Actions\Action::make('contactCustomer')
            ->label('פנה ללקוח')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('info')
            ->form([
                Forms\Components\Radio::make('channel')
                    ->label('לשלוח דרך')
                    ->options(['whatsapp' => 'וואטסאפ', 'email' => 'מייל'])
                    ->default(fn (Customer $record): string => filled($record->whatsapp_jid) || filled($record->phone) ? 'whatsapp' : 'email')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('subject')
                    ->label('נושא (כותרת הפנייה / שורת הנושא במייל)')
                    ->default('פנייה מהצוות')->maxLength(120)->required(),
                Forms\Components\RichEditor::make('message')
                    ->label('ההודעה ללקוח')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link'])
                    ->required(),
            ])
            ->action(function (array $data, Customer $record, AgentReply $agentReply): void {
                $channel = $data['channel'] ?? 'whatsapp';
                $missing = $channel === 'email'
                    ? blank($record->email)
                    : (blank($record->whatsapp_jid) && blank($record->phone));

                if ($missing) {
                    Notification::make()->title('אין ללקוח פרטי '.($channel === 'email' ? 'מייל' : 'וואטסאפ'))->danger()->send();

                    return;
                }

                $html = trim((string) $data['message']);
                $body = EmailBody::toText(null, $html);

                if ($body === '') {
                    Notification::make()->title('אין תוכן לשליחה')->warning()->send();

                    return;
                }

                // For WhatsApp, store the customer's chat id as the thread ref so
                // their reply lands back on THIS ticket (IngestWhatsappMessageJob
                // threads by chat id) instead of opening a separate one.
                $threadRef = $channel === 'email'
                    ? null
                    : app(WahaClient::class)->normalizeChatId((string) ($record->whatsapp_jid ?? $record->phone));

                $ticket = Ticket::create([
                    'customer_id' => $record->id,
                    'channel' => $channel === 'email' ? TicketChannel::Email : TicketChannel::Whatsapp,
                    'subject' => filled($data['subject']) ? $data['subject'] : 'פנייה מהצוות',
                    'status' => TicketStatus::Open,
                    'external_thread_ref' => $threadRef,
                ]);

                $agentReply->send($ticket, $body, EmailBody::toSafeHtml($html));

                Notification::make()
                    ->title('ההודעה נשלחה ללקוח')
                    ->body("נפתחה פנייה #{$ticket->id} — תשובת הלקוח תיכנס לאותה שיחה.")
                    ->success()->send();
            });
    }

    /**
     * Reconcile a card the customer entered via the link but that never synced
     * (a lost completion webhook): fetch the last card-capture session from
     * Cardcom and, if it holds a token, save it and collect any debt now.
     */
    private function syncCardAction(): Actions\Action
    {
        return Actions\Action::make('syncCard')
            ->label('בדיקת כרטיס בקארדקום')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->modalHeading('בדיקת כרטיס מול קארדקום')
            ->modalDescription('נבדוק מול קארדקום אם הלקוח הזין כרטיס שטרם נשמר במערכת, ונסנכרן אותו (כולל גביית חוב פתוח אם יש).')
            ->fillForm(fn (Customer $record): array => ['low_profile_id' => $record->pending_card_lp_id])
            ->form([
                Forms\Components\TextInput::make('low_profile_id')
                    ->label('מזהה עסקה מקארדקום (LowProfileId)')
                    ->helperText('נטען אוטומטית אם יש בקשה ממתינה. אם הלקוח כבר מילא ואין בקשה — הדביקו כאן את מזהה ה-LowProfile מדוח/הודעת קארדקום.'),
            ])
            ->modalSubmitActionLabel('בדוק וסנכרן')
            ->action(function (array $data, Customer $record, CardcomClient $cardcom, CardTokenService $tokens): void {
                $lpId = trim((string) ($data['low_profile_id'] ?? '')) ?: $record->pending_card_lp_id;

                if (blank($lpId)) {
                    Notification::make()
                        ->title('חסר מזהה עסקה')
                        ->body('אין בקשת כרטיס ממתינה. שִלחו/פִּתחו ללקוח קישור להזנת כרטיס, או הדביקו כאן את מזהה ה-LowProfile מקארדקום.')
                        ->warning()->send();

                    return;
                }

                try {
                    $result = $cardcom->getLpResult((string) $lpId);
                } catch (\Throwable $e) {
                    Notification::make()->title('הבדיקה מול קארדקום נכשלה')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                    return;
                }

                // Guard against a paste/report mix-up: the token capture wrote this
                // customer's id into ReturnValue, so refuse to attach a card from a
                // session that belongs to a different customer (or a hosted charge).
                if ((int) ($result['ReturnValue'] ?? 0) !== $record->id) {
                    Notification::make()
                        ->title('העסקה אינה שייכת ללקוח זה')
                        ->body('מזהה ה-LowProfile בקארדקום שייך ללקוח אחר או לעסקה מסוג אחר — לא בוצע סנכרון.')
                        ->danger()->send();

                    return;
                }

                $token = $tokens->storeFromLpResult($record, $result);

                if ($token === null) {
                    Notification::make()
                        ->title('לא נמצא כרטיס חדש בקארדקום')
                        ->body('ייתכן שהלקוח עדיין לא סיים להזין את הכרטיס, או שההזנה נכשלה. נסו שוב מאוחר יותר.')
                        ->warning()->send();

                    return;
                }

                $record->update(['pending_card_lp_id' => null]);

                Notification::make()
                    ->title('הכרטיס סונכרן ✓')
                    ->body('נשמר כרטיס '.($token->card_last4 ? '****'.$token->card_last4 : '').'. אם היה חוב פתוח — נשלח לגבייה כעת.')
                    ->success()->persistent()->send();
            });
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
