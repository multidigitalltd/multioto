<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource;
use App\Jobs\DraftReplyJob;
use App\Jobs\SendTicketReplyJob;
use App\Models\TicketMessage;
use App\Services\Support\AttachmentStore;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Conversation view — the ticket as a chat (WhatsApp/email alike): message
 * bubbles per side, internal notes visually distinct, and a reply box that
 * routes the answer back to the customer's channel. Polls for new inbound
 * messages so a live WhatsApp exchange reads like a chat app.
 */
class ViewTicket extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = TicketResource::class;

    protected static string $view = 'filament.tickets.chat';

    public string $replyBody = '';

    public string $replyChannel = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $replyFiles = [];

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
        $files = array_filter((array) $this->replyFiles);

        if ($body === '' && $files === []) {
            Notification::make()->title('אין תוכן לשליחה')->warning()->send();

            return;
        }

        // Uploaded files must be real files within the size cap (re-checked by
        // AttachmentStore against their sniffed MIME).
        $maxKb = (int) round((int) config('billing.support.attachments.max_bytes', 10485760) / 1024);
        $this->validate(['replyFiles.*' => "file|max:{$maxKb}"]);

        $channel = MessageChannel::tryFrom($this->replyChannel) ?? MessageChannel::InternalNote;

        $message = $this->record->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $channel,
            'body' => $body !== '' ? $body : '[קובץ מצורף]',
            'author' => MessageAuthor::Agent,
        ]);

        $stored = $this->storeReplyFiles($files);

        if ($stored !== []) {
            $message->update(['attachments' => $stored]);
        }

        if ($channel !== MessageChannel::InternalNote) {
            // The ball is now with the customer: move an active ticket to the
            // intermediate "ממתין ללקוח" status and stamp the first response time.
            // Terminal states (resolved/closed) are left as the agent set them.
            $updates = [];
            if (in_array($this->record->status, [TicketStatus::Open, TicketStatus::Pending, TicketStatus::OnHold], true)) {
                $updates['status'] = TicketStatus::Pending;
            }
            if ($this->record->first_response_at === null) {
                $updates['first_response_at'] = now();
            }
            if ($updates !== []) {
                $this->record->update($updates);
            }

            SendTicketReplyJob::dispatch($message->id);
        }

        $this->replyBody = '';
        $this->replyFiles = [];

        Notification::make()
            ->title($channel === MessageChannel::InternalNote ? 'ההערה נשמרה' : 'המענה נשלח ללקוח')
            ->success()->send();
    }

    /**
     * Validate + store the agent's uploaded reply files (rejected files are
     * skipped), returning their metadata for the message.
     *
     * @param  array<int, TemporaryUploadedFile>  $files
     * @return array<int, array{name: string, mime: string, size: int, path: string, disk: string}>
     */
    protected function storeReplyFiles(array $files): array
    {
        $store = app(AttachmentStore::class);
        $stored = [];

        foreach ($files as $file) {
            $meta = $store->store($this->record->id, $file->getClientOriginalName(), $file->get(), $file->getMimeType());

            if ($meta !== null) {
                $stored[] = $meta;
            }
        }

        return $stored;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Ask the AI to draft a reply for THIS conversation now (drafts are
            // otherwise generated only when a new customer message arrives, so
            // an already-open ticket has none). The draft lands as an internal
            // note for your approval — never sent. Also a quick "is the agent
            // working?" check: a failure points you at the connection test.
            Actions\Action::make('draftReply')
                ->label('הכן טיוטת AI')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->visible(fn (): bool => (bool) config('billing.ai.enabled'))
                ->action(function (): void {
                    $before = $this->record->messages()->where('author', MessageAuthor::Ai)->count();
                    DraftReplyJob::dispatchSync($this->record->id);
                    $produced = $this->record->messages()->where('author', MessageAuthor::Ai)->count() > $before;

                    Notification::make()
                        ->title($produced ? 'הוכנה טיוטה' : 'לא הוכנה טיוטה')
                        ->body($produced
                            ? 'הטיוטה נוספה כהערה פנימית בשיחה וממתינה לאישורך.'
                            : 'ייתכן שההודעה האחרונה אינה מהלקוח, או שהחיבור לספק ה-AI נכשל — בדקו ב"סוכן AI ← בדיקת חיבור".')
                        ->{$produced ? 'success' : 'warning'}()
                        ->send();
                }),
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
