<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource;
use App\Jobs\SendTicketReplyJob;
use App\Models\TicketMessage;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * Conversation view — the ticket as a chat (WhatsApp/email alike): message
 * bubbles per side, internal notes visually distinct, and a reply box that
 * routes the answer back to the customer's channel. Polls for new inbound
 * messages so a live WhatsApp exchange reads like a chat app.
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected static string $view = 'filament.tickets.chat';

    public string $replyBody = '';

    public string $replyChannel = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->replyChannel = $this->record->channel === TicketChannel::Whatsapp
            ? MessageChannel::Whatsapp->value
            : MessageChannel::Email->value;
    }

    /** @return Collection<int, TicketMessage> */
    public function getMessagesProperty(): Collection
    {
        return $this->record->messages()->orderBy('created_at')->get();
    }

    /** Send the reply box content to the customer (or store an internal note). */
    public function sendReply(): void
    {
        $body = trim($this->replyBody);

        if ($body === '') {
            Notification::make()->title('אין תוכן לשליחה')->warning()->send();

            return;
        }

        $channel = MessageChannel::tryFrom($this->replyChannel) ?? MessageChannel::InternalNote;

        $message = $this->record->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $channel,
            'body' => $body,
            'author' => MessageAuthor::Agent,
        ]);

        if ($channel !== MessageChannel::InternalNote) {
            SendTicketReplyJob::dispatch($message->id);
        }

        $this->replyBody = '';

        Notification::make()
            ->title($channel === MessageChannel::InternalNote ? 'ההערה נשמרה' : 'המענה נשלח ללקוח')
            ->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resolve')
                ->label('סמן כטופלה')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== TicketStatus::Resolved)
                ->requiresConfirmation()
                ->modalDescription('הלקוח יקבל אוטומטית עדכון שהטיפול הושלם, בערוץ שממנו פנה.')
                ->action(function (): void {
                    $this->record->update(['status' => TicketStatus::Resolved]);
                    Notification::make()->title('הפנייה סומנה כטופלה — הלקוח עודכן')->success()->send();
                }),
            Actions\EditAction::make()->label('עריכת פרטים'),
        ];
    }
}
