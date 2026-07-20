<?php

namespace App\Filament\Resources\CustomerResource\Concerns;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\CardTokenService;
use App\Support\CardLink;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The customer card-capture actions, shared between the customer 360° page and
 * the edit screen so a new card can be added from either place with the exact
 * same secure flow (Cardcom hosted page — we never touch card numbers).
 */
trait InteractsWithCustomerCards
{
    /** Show the signed card-capture link to copy/open, with an option to void it. */
    protected function cardLinkAction(): Actions\Action
    {
        return Actions\Action::make('cardLink')
            ->label('קישור לכרטיס')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->modalHeading('קישור מאובטח להזנת כרטיס')
            ->modalDescription('העתיקו ושִלחו ללקוח, או פִּתחו בעצמכם. הכרטיס מוזן בעמוד המאובטח של קארדקום.')
            ->modalContent(fn (Customer $record) => view('forms.copyable-link', ['link' => CardLink::for($record->id)]))
            ->extraModalFooterActions([
                // Void every card link already sent to this customer: the old
                // links stop working and a fresh one must be generated.
                Actions\Action::make('revokeCardLink')
                    ->label('בטל קישורים קיימים')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalHeading('ביטול קישורי כרטיס')
                    ->modalDescription('כל קישור להזנת כרטיס שכבר נשלח ללקוח יפסיק לעבוד ויציג "אינו פעיל". יצירת קישור חדש תיצור קישור פעיל.')
                    ->action(function (Customer $record): void {
                        $record->revokeCardLinks();
                        Notification::make()->title('הקישורים בוטלו — קישורים קודמים אינם פעילים עוד')->success()->send();
                    }),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('סגור');
    }

    /**
     * Reconcile a card the customer entered via the link but that never synced
     * (a lost completion webhook): fetch the last card-capture session from
     * Cardcom and, if it holds a token, save it and collect any debt now.
     */
    protected function syncCardAction(): Actions\Action
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
}
