<?php

namespace App\Filament\Resources\CustomerResource\Actions;

use App\Models\Customer;
use App\Services\Customers\CustomerMerger;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * "מיזוג כרטיס כפול לכאן" — absorb a duplicate customer card into the one on
 * screen.
 *
 * The direction is fixed by where you are standing: the card you are looking at
 * is the one that stays. This is the whole safety design of the screen — a
 * merge form with two customer pickers puts the survivor and the duplicate one
 * dropdown apart, and getting them the wrong way round deletes the wrong card.
 *
 * Before anything moves, the modal states what will move and how much of it.
 * Admin-only: absorbing a customer's history is not the same as editing a field.
 */
class MergeCustomerAction
{
    public static function make(): Action
    {
        return Action::make('mergeCustomer')
            ->label('מיזוג כרטיס כפול לכאן')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading('מיזוג כרטיס כפול')
            ->modalDescription('הכרטיס שאתם צופים בו הוא זה שנשאר. כל מה שקשור לכרטיס הכפול יעבור לכאן, והכרטיס הכפול יימחק.')
            ->modalSubmitActionLabel('מזג')
            ->modalWidth('xl')
            ->form(fn (Customer $record): array => [
                Forms\Components\Select::make('duplicate_id')
                    ->label('הכרטיס הכפול (זה שייעלם)')
                    ->options(fn (): array => Customer::query()
                        ->whereKeyNot($record->getKey())
                        ->orderBy('name')
                        ->get(['id', 'name', 'email', 'phone'])
                        ->mapWithKeys(fn (Customer $c): array => [$c->id => self::optionLabel($c)])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText('חפשו לפי שם, מייל או טלפון.'),
                Forms\Components\Placeholder::make('preview')
                    ->label('מה יעבור לכרטיס הזה')
                    ->visible(fn (Forms\Get $get): bool => filled($get('duplicate_id')))
                    ->content(fn (Forms\Get $get): HtmlString => self::preview((int) $get('duplicate_id'))),
                Forms\Components\Checkbox::make('understood')
                    ->label('הבנתי שהכרטיס הכפול יימחק ושאי אפשר לבטל את המיזוג')
                    ->accepted()
                    ->required()
                    ->visible(fn (Forms\Get $get): bool => filled($get('duplicate_id'))),
            ])
            ->action(function (array $data, Customer $record, CustomerMerger $merger): void {
                $duplicate = Customer::find($data['duplicate_id']);

                if ($duplicate === null) {
                    Notification::make()->title('הכרטיס הכפול לא נמצא')->danger()->send();

                    return;
                }

                $name = $duplicate->name;

                try {
                    $moved = $merger->merge($duplicate, $record);
                } catch (RuntimeException $e) {
                    // Nothing was merged: the service rolls the whole thing back
                    // rather than leave a customer split across two cards.
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("הכרטיס של {$name} מוזג לכאן")
                    ->body(array_sum($moved) === 0
                        ? 'לא היו רשומות להעביר — הכרטיס הכפול היה ריק.'
                        : 'הועברו '.array_sum($moved).' רשומות. המיזוג נרשם ביומן הפעולות.')
                    ->success()->send();
            });
    }

    /** "שם (מייל / טלפון) #12" — enough to tell two same-named cards apart. */
    private static function optionLabel(Customer $customer): string
    {
        $identity = collect([$customer->email, $customer->phone])->filter()->implode(' · ');

        return $customer->name.($identity !== '' ? " ({$identity})" : '')." #{$customer->id}";
    }

    /**
     * What the merge would move. Shown before the button is pressed — a count
     * that looks wrong is how you catch, in time, that the direction is reversed.
     */
    private static function preview(int $duplicateId): HtmlString
    {
        $duplicate = Customer::find($duplicateId);

        if ($duplicate === null) {
            return new HtmlString('');
        }

        $labels = [
            'sites' => 'אתרים', 'subscriptions' => 'מנויים', 'charges' => 'חיובים',
            'invoices' => 'חשבוניות', 'payment_tokens' => 'כרטיסי אשראי', 'tickets' => 'פניות',
            'contacts' => 'אנשי קשר', 'tasks' => 'משימות', 'pending_actions' => 'פעולות ממתינות',
            'notification_logs' => 'הודעות שנשלחו', 'agent_commands' => 'פקודות סוכן',
        ];

        $counts = app(CustomerMerger::class)->preview($duplicate);

        if ($counts === []) {
            return new HtmlString('<span class="text-sm">הכרטיס הכפול ריק — רק פרטי הקשר החסרים יושלמו.</span>');
        }

        $lines = collect($counts)
            ->map(fn (int $count, string $table): string => e(($labels[$table] ?? $table).": {$count}"))
            ->implode('</li><li>');

        return new HtmlString('<ul class="list-disc pr-5 text-sm"><li>'.$lines.'</li></ul>');
    }
}
