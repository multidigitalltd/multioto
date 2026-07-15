<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Filament\Widgets\AgentCommandWidget;
use App\Models\Customer;
use App\Services\Support\AgentReply;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->contactCustomerAction(),
            Actions\CreateAction::make(),
        ];
    }

    /** The compact agent command bar, above the tickets list. */
    protected function getHeaderWidgets(): array
    {
        return [
            AgentCommandWidget::class,
        ];
    }

    /**
     * Proactively open a conversation with any customer straight from the tickets
     * list — pick the customer, choose a channel, write the message, and it opens
     * a ticket AND sends. Same one-step flow as the "פנה ללקוח" button on the
     * customer card, so the team doesn't have to open the card first.
     */
    private function contactCustomerAction(): Actions\Action
    {
        return Actions\Action::make('contactCustomer')
            ->label('פנה ללקוח')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('info')
            ->form([
                Forms\Components\Select::make('customer_id')
                    ->label('לקוח')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Radio::make('channel')
                    ->label('לשלוח דרך')
                    ->options(['whatsapp' => 'וואטסאפ', 'email' => 'מייל'])
                    ->default('whatsapp')
                    ->required(),
                Forms\Components\TextInput::make('subject')
                    ->label('נושא (כותרת הפנייה / שורת הנושא במייל)')
                    ->default('פנייה מהצוות')->maxLength(120)->required(),
                Forms\Components\RichEditor::make('message')
                    ->label('ההודעה ללקוח')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link'])
                    ->required(),
            ])
            ->action(function (array $data, AgentReply $agentReply): void {
                $customer = Customer::find($data['customer_id']);

                if (! $customer) {
                    Notification::make()->title('הלקוח לא נמצא')->danger()->send();

                    return;
                }

                try {
                    $ticket = $agentReply->openConversation(
                        $customer,
                        $data['channel'] ?? 'whatsapp',
                        (string) ($data['subject'] ?? ''),
                        (string) $data['message'],
                    );
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('ההודעה נשלחה ללקוח')
                    ->body("נפתחה פנייה #{$ticket->id} — תשובת הלקוח תיכנס לאותה שיחה.")
                    ->success()->send();
            });
    }
}
