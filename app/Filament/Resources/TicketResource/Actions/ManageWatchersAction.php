<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Models\AuditLog;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * "מכותבים בפנייה" — manage the addresses copied on one ticket.
 *
 * A copied address receives every reply the customer receives and can answer
 * back into the same conversation. Scoped to this ticket alone: being copied on
 * a billing question is not consent to read everything that customer ever wrote.
 *
 * Adding someone to a conversation sends them correspondence that already
 * exists, so the modal says plainly what they will and will not see, and every
 * change is written to the audit log.
 */
class ManageWatchersAction
{
    public static function make(): Action
    {
        return Action::make('manageWatchers')
            ->label('מכותבים')
            ->icon('heroicon-o-at-symbol')
            ->color('gray')
            ->badge(fn (Ticket $record): ?string => ($count = $record->watchers()->count()) > 0 ? (string) $count : null)
            ->modalHeading('מכותבים בפנייה')
            ->modalDescription('מי שמכותב מקבל כל תשובה שנשלחת ללקוח ואת תשובות הלקוח, ויכול לענות ישירות לתוך הפנייה. ההתכתבות שקדמה להוספה אינה נשלחת אליו.')
            ->modalSubmitActionLabel('שמור')
            ->modalWidth('xl')
            ->fillForm(fn (Ticket $record): array => [
                'watchers' => $record->watchers()->orderBy('id')
                    ->get(['email', 'name'])
                    ->map(fn ($w): array => ['email' => $w->email, 'name' => $w->name])
                    ->all(),
            ])
            ->form(fn (Ticket $record): array => [
                Forms\Components\Repeater::make('watchers')
                    ->label('כתובות מכותבות')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('כתובת מייל')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('name')
                            ->label('שם (רשות)')
                            ->maxLength(255),
                    ])
                    ->columns(3)
                    ->addActionLabel('הוספת מכותב')
                    ->reorderable(false)
                    ->default([])
                    ->extraItemActions([self::promoteToContactAction($record)])
                    ->helperText('כל אחד יקבל את ההודעות הבאות בפנייה. להסרה — לחצו על סמל הפח. הכפתור עם האדם מוסיף את המכותב כאיש קשר קבוע בכרטיס הלקוח.'),
            ])
            ->action(function (array $data, Ticket $record): void {
                $before = $record->watchers()->pluck('email')->all();
                $kept = [];

                foreach ((array) ($data['watchers'] ?? []) as $row) {
                    $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

                    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        continue;
                    }

                    // The customer already receives everything; copying them to
                    // themselves would double every reply they get.
                    if ($email === mb_strtolower(trim((string) $record->customer?->email))) {
                        continue;
                    }

                    $record->watchers()->updateOrCreate(
                        ['email' => $email],
                        ['name' => filled($row['name'] ?? null) ? trim((string) $row['name']) : null, 'added_by' => auth()->user()?->email],
                    );

                    $kept[] = $email;
                }

                // Removing is the point of the same screen: an address left off
                // the list stops receiving this conversation immediately.
                $record->watchers()->whereNotIn('email', $kept)->delete();

                $added = array_values(array_diff($kept, $before));
                $removed = array_values(array_diff($before, $kept));

                if ($added !== [] || $removed !== []) {
                    AuditLog::record(
                        'updated',
                        "עדכון מכותבים בפנייה #{$record->id}",
                        $record,
                        ['added' => $added, 'removed' => $removed],
                    );
                }

                Notification::make()
                    ->title($kept === [] ? 'אין מכותבים בפנייה' : 'המכותבים עודכנו')
                    ->body($kept === []
                        ? 'הפנייה חזרה להתנהל מול הלקוח בלבד.'
                        : count($kept).' כתובות יקבלו את ההודעות הבאות בפנייה.')
                    ->success()->send();
            });
    }

    /**
     * Promote one row of the list to a permanent contact on the customer card.
     *
     * This is a deliberate widening of scope, not a shortcut: a watcher belongs
     * to one ticket, while a contact is matched on every future inbound message
     * and lands it on this customer. Worth one button — and worth saying out
     * loud in the confirmation, because "add contact" does not imply it.
     */
    private static function promoteToContactAction(Ticket $ticket): FormAction
    {
        return FormAction::make('promoteToContact')
            ->label('הוספה כאיש קשר בלקוח')
            ->icon('heroicon-m-user-plus')
            ->tooltip('הוספה כאיש קשר קבוע בכרטיס הלקוח')
            ->visible($ticket->customer_id !== null)
            ->requiresConfirmation()
            ->modalHeading('הוספה כאיש קשר בכרטיס הלקוח')
            ->modalDescription('מרגע זה כל הודעה מהכתובת הזאת — גם בפניות אחרות — תזוהה אוטומטית כשייכת ללקוח הזה. המכותב נשאר מכותב גם בפנייה הנוכחית.')
            ->modalSubmitActionLabel('הוסף כאיש קשר')
            ->action(function (array $arguments, Forms\Components\Repeater $component) use ($ticket): void {
                $row = $component->getRawState()[$arguments['item']] ?? [];
                $contact = $ticket->promoteWatcherToContact((string) ($row['email'] ?? ''), $row['name'] ?? null);

                if ($contact === null) {
                    Notification::make()->title('אין כתובת מייל תקינה בשורה הזאת')->warning()->send();

                    return;
                }

                if (! $contact->wasRecentlyCreated) {
                    Notification::make()
                        ->title('כבר קיים כאיש קשר')
                        ->body("{$contact->email} כבר רשום בכרטיס הלקוח.")
                        ->info()->send();

                    return;
                }

                AuditLog::record(
                    'created',
                    "מכותב בפנייה #{$ticket->id} נוסף כאיש קשר בלקוח {$ticket->customer->name}",
                    $contact,
                    ['email' => $contact->email],
                );

                Notification::make()
                    ->title('נוסף כאיש קשר')
                    ->body("{$contact->email} יזוהה מעכשיו אוטומטית כשייך ל{$ticket->customer->name}.")
                    ->success()->send();
            });
    }
}
