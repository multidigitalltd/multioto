<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Enums\ActionStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TaskStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource;
use App\Jobs\DraftReplyJob;
use App\Jobs\InvestigateTicketJob;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\SendTicketReplyJob;
use App\Models\CannedResponse;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Task;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Support\AttachmentStore;
use App\Support\EmailBody;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    /** Rich-editor state (HTML). Kept in a Filament form so agents get a real editor. */
    public ?array $replyData = ['body' => null];

    public string $replyChannel = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $replyFiles = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->replyChannel = $this->record->channel === TicketChannel::Whatsapp
            ? MessageChannel::Whatsapp->value
            : MessageChannel::Email->value;

        $this->replyForm->fill();
    }

    /**
     * The reply editor — a real WYSIWYG with a deliberately small toolbar
     * (bold/italic/lists/link). Its HTML is stored for the email/panel view and
     * converted to WhatsApp markup on the way out, so one reply reads right on
     * either channel.
     */
    public function replyForm(Form $form): Form
    {
        return $form
            ->schema([
                // Insert a saved response template into the editor. Picking one
                // appends its text (placeholders filled) to whatever's already
                // typed, then resets so the same template can be re-picked.
                Select::make('canned')
                    ->hiddenLabel()
                    ->placeholder('הוספת תבנית מענה…')
                    ->options(fn (): array => CannedResponse::orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (): bool => CannedResponse::exists())
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        if (! $state || ! ($canned = CannedResponse::find($state))) {
                            return;
                        }

                        $html = '<p>'.nl2br(e($this->applyPlaceholders((string) $canned->body))).'</p>';
                        $existing = trim((string) ($get('body') ?? ''));
                        $set('body', $existing !== '' ? $existing.$html : $html);
                        $set('canned', null);
                    }),
                RichEditor::make('body')
                    ->hiddenLabel()
                    ->placeholder('כתבו מענה ללקוח…')
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
            ])
            ->statePath('replyData');
    }

    /** Fill the common template placeholders from this ticket's context. */
    private function applyPlaceholders(string $body): string
    {
        return strtr($body, [
            '{{customer_name}}' => $this->record->customer?->name ?? 'לקוח יקר',
            '{{ticket_id}}' => (string) $this->record->id,
            '{{ticket_subject}}' => (string) $this->record->subject,
            '{{business_name}}' => config('mail.from.name') ?: config('app.name'),
        ]);
    }

    protected function getForms(): array
    {
        return array_merge(parent::getForms(), ['replyForm']);
    }

    /**
     * The conversation timeline — WITHOUT any AI-authored internal note. Those
     * are the bot's own working notes (reply drafts, classification, site
     * findings), not part of the customer↔team exchange, and reading them inline
     * as chat bubbles is confusing. Drafts move to the "המלצת הסוכן" panel and
     * the rest to a compact "מידע מהסוכן" section.
     *
     * @return Collection<int, TicketMessage>
     */
    public function getMessagesProperty(): Collection
    {
        return $this->record->messages()
            ->orderBy('created_at')
            ->get()
            ->reject(fn (TicketMessage $m): bool => $this->isAiInternalNote($m))
            ->values();
    }

    /**
     * The AI's pending reply drafts — shown in a dedicated "המלצת הסוכן" panel,
     * separated from the conversation. Newest first.
     *
     * @return Collection<int, TicketMessage>
     */
    public function getAiDraftsProperty(): Collection
    {
        return $this->record->messages()
            ->where('author', MessageAuthor::Ai)
            ->where('channel', MessageChannel::InternalNote)
            ->where('body', 'like', '%טיוטת תשובה%')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The AI's other internal notes — classification, site findings — shown as
     * compact context in "מידע מהסוכן", out of the conversation. Newest first.
     *
     * @return Collection<int, TicketMessage>
     */
    public function getAiNotesProperty(): Collection
    {
        return $this->record->messages()
            ->where('author', MessageAuthor::Ai)
            ->where('channel', MessageChannel::InternalNote)
            ->where('body', 'not like', '%טיוטת תשובה%')
            ->orderByDesc('created_at')
            ->get();
    }

    /** Any AI-authored internal note — a bot working note, not a real message. */
    private function isAiInternalNote(TicketMessage $message): bool
    {
        return $message->author === MessageAuthor::Ai
            && $message->channel === MessageChannel::InternalNote;
    }

    /** Just the proposed reply text of a draft, without the "🤖 טיוטה…" preamble. */
    public function draftText(TicketMessage $draft): string
    {
        return trim(Str::of((string) $draft->body)->contains("\n\n")
            ? Str::after((string) $draft->body, "\n\n")
            : (string) $draft->body);
    }

    /** Discard an AI reply draft the agent doesn't want. */
    public function dismissDraft(int $messageId): void
    {
        $deleted = $this->record->messages()
            ->where('author', MessageAuthor::Ai)
            ->where('channel', MessageChannel::InternalNote)
            ->where('body', 'like', '%טיוטת תשובה%')
            ->whereKey($messageId)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        // The draft also has a pending ticket_reply approval (from DraftReplyJob)
        // that could otherwise still be approved from the panel/WhatsApp and sent.
        // Reject it too, exactly as sending a manual reply does — otherwise "דחה"
        // wouldn't actually prevent the draft from reaching the customer.
        PendingAction::where('ticket_id', $this->record->id)
            ->where('type', 'ticket_reply')
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => 'בוטלה — הטיוטה נדחתה מהשיחה.']);

        Notification::make()->title('ההמלצה נדחתה')->success()->send();
    }

    /** Send the reply box content to the customer (or store an internal note). */
    public function sendReply(): void
    {
        // The editor holds HTML; keep the plain text as the canonical body (used
        // for search / WhatsApp base) and the sanitized HTML for the rich view.
        $html = trim((string) ($this->replyForm->getState()['body'] ?? ''));
        $body = EmailBody::toText(null, $html);
        $bodyHtml = EmailBody::toSafeHtml($html);
        $files = array_filter((array) $this->replyFiles);

        if ($body === '' && $files === []) {
            Notification::make()->title('אין תוכן לשליחה')->warning()->send();

            return;
        }

        // Uploaded files must be real files within the size cap (re-checked by
        // AttachmentStore against their sniffed MIME).
        $maxKb = (int) round((int) config('billing.support.attachments.max_bytes', 10485760) / 1024);
        $this->validate(['replyFiles.*' => "file|max:{$maxKb}"]);

        // Store files first so we can tell the agent up front if any were rejected
        // (unsupported type) — otherwise a dropped file sends silently.
        $stored = $this->storeReplyFiles($files);
        $rejected = count($files) - count($stored);

        if ($body === '' && $stored === []) {
            Notification::make()
                ->title('לא ניתן לשלוח')
                ->body($files !== [] ? 'הקבצים שנבחרו אינם נתמכים — נסו פורמט אחר.' : 'אין תוכן לשליחה.')
                ->warning()->send();

            return;
        }

        $channel = MessageChannel::tryFrom($this->replyChannel) ?? MessageChannel::InternalNote;

        $message = $this->record->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $channel,
            'body' => $body !== '' ? $body : '[קובץ מצורף]',
            'body_html' => $body !== '' ? $bodyHtml : null,
            'author' => MessageAuthor::Agent,
        ]);

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

            // A manual reply supersedes any pending AI reply proposal for this
            // ticket — cancel it so a later WhatsApp/panel approval can't send the
            // original draft as a duplicate second reply.
            PendingAction::where('ticket_id', $this->record->id)
                ->where('type', 'ticket_reply')
                ->where('status', ActionStatus::Pending)
                ->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => 'בוטלה — נשלחה תשובה ידנית מהשיחה.']);

            SendTicketReplyJob::dispatch($message->id);
        }

        $this->replyForm->fill();
        $this->replyFiles = [];

        Notification::make()
            ->title($channel === MessageChannel::InternalNote ? 'ההערה נשמרה' : 'המענה נשלח ללקוח')
            ->body($rejected > 0 ? "שימו לב: {$rejected} קבצים לא צורפו (סוג קובץ לא נתמך)." : null)
            ->success()->send();
    }

    /**
     * Load an AI draft note into the reply editor so the agent can tweak it and
     * send it straight from the conversation — no detour through the approvals
     * screen. The draft note stays as a record; sending is still a human action.
     */
    public function useDraft(int $messageId): void
    {
        $draft = $this->record->messages()
            ->where('author', MessageAuthor::Ai)
            ->where('channel', MessageChannel::InternalNote)
            // Only an actual draft REPLY — never a classification/priority note,
            // which must never be loaded into the customer-facing editor.
            ->where('body', 'like', '%טיוטת תשובה%')
            ->whereKey($messageId)
            ->first();

        if (! $draft) {
            return;
        }

        // Strip the "🤖 טיוטה … לפני שליחה:" preamble, keeping just the proposed
        // reply text; fall back to the whole note if the marker isn't present.
        $text = Str::of($draft->body)->contains("\n\n")
            ? Str::after($draft->body, "\n\n")
            : $draft->body;

        $this->replyForm->fill(['body' => '<p>'.nl2br(e(trim($text))).'</p>']);

        // Send to the customer's channel, not as an internal note.
        $this->replyChannel = $this->record->channel === TicketChannel::Whatsapp
            ? MessageChannel::Whatsapp->value
            : MessageChannel::Email->value;

        Notification::make()->title('הטיוטה נטענה לעריכה — בדקו ושִלחו')->success()->send();
    }

    /**
     * Score a reply (an AI draft or a sent answer) 1–10. The score feeds the
     * style learner, so future drafts lean on the highly-rated replies.
     */
    public function rateMessage(int $messageId, int $score): void
    {
        $score = max(1, min(10, $score));
        $message = $this->record->messages()->whereKey($messageId)->first();

        if ($message && $message->isRateable()) {
            $message->update(['quality_rating' => $score]);
            Notification::make()->title("הדירוג נשמר ({$score}/10) — הסוכן ילמד מזה")->success()->send();
        }
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
            // Send the customer's connected site to the AI operator: it reads the
            // site read-only and adds a system note here with what to do; any fix
            // is filed for approval (nothing runs без אישור). Shown only when the
            // AI is on and the customer has a connected site.
            Actions\Action::make('investigateSite')
                ->label('בדיקת סוכן AI לאתר')
                ->icon('heroicon-o-globe-alt')
                ->color('info')
                ->visible(fn (): bool => app(ClaudeClient::class)->supportsAgent()
                    && (bool) $this->record->customer?->sites()->where('mcp_enabled', true)->exists())
                ->action(function (): void {
                    InvestigateTicketJob::dispatch($this->record->id);
                    Notification::make()
                        ->title('הסוכן בודק את האתר')
                        ->body('הבדיקה רצה ברקע; הערת מערכת עם ההמלצה תופיע בשיחה, וכל תיקון יומתן לאישור.')
                        ->success()
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
            // Quiet close — mark the ticket closed WITHOUT notifying the customer
            // (unlike "סמן כטופלה"). For internal/spam/no-reply-needed tickets.
            Actions\Action::make('quietClose')
                ->label('סגירה שקטה')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status !== TicketStatus::Closed)
                ->requiresConfirmation()
                ->modalDescription('הפנייה תיסגר ללא שליחת הודעה כלשהי ללקוח.')
                ->action(function (): void {
                    // Match the bulk silent-close: stamp resolved_at so the
                    // resolution history stays consistent.
                    $this->record->update(['status' => TicketStatus::Closed, 'resolved_at' => now()]);
                    Notification::make()->title('הפנייה נסגרה (ללא הודעה ללקוח)')->success()->send();
                }),
            $this->linkToCustomerAction(),
            $this->convertToTaskAction(),
            Actions\EditAction::make()->label('עריכת פרטים'),
        ];
    }

    /**
     * For an UNIDENTIFIED enquiry: attach the sender to an existing customer as
     * an additional contact (name + email/phone/WhatsApp captured from the
     * ticket) and associate the ticket to that customer — so this thread, and
     * every future message from the same sender, lands on the right customer.
     */
    private function linkToCustomerAction(): Actions\Action
    {
        return Actions\Action::make('linkToCustomer')
            ->label('שייך ללקוח קיים')
            ->icon('heroicon-o-user-plus')
            ->color('info')
            ->visible(fn (): bool => $this->record->customer_id === null)
            ->fillForm(fn (): array => [
                'name' => $this->record->contact_name ?: $this->record->contact_handle,
            ])
            ->form([
                Select::make('customer_id')->label('לקוח')
                    ->options(fn (): array => Customer::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->required(),
                TextInput::make('name')->label('שם איש הקשר')->required()->maxLength(255),
                TextInput::make('role')->label('תפקיד')->maxLength(255)
                    ->helperText('למשל: בעלים, מנהל/ת, איש קשר טכני, כספים.'),
            ])
            ->action(function (array $data): void {
                $ticket = $this->record;
                $handle = trim((string) $ticket->contact_handle);

                $fields = [
                    'customer_id' => $data['customer_id'],
                    'name' => $data['name'],
                    'role' => $data['role'] ?? null,
                    'is_primary' => false,
                ];

                // Capture the sender's identifiers so future messages auto-match.
                if ($ticket->channel === TicketChannel::Whatsapp) {
                    $fields['phone'] = $handle ?: null;
                    $fields['whatsapp_jid'] = $ticket->external_thread_ref ?: null;
                } elseif (str_contains($handle, '@')) {
                    $fields['email'] = $handle;
                } elseif ($handle !== '') {
                    $fields['phone'] = $handle;
                }

                Contact::create($fields);
                $ticket->update(['customer_id' => $data['customer_id']]);

                Notification::make()
                    ->title('הפנייה שויכה ללקוח ואיש הקשר נוסף')
                    ->body('פניות עתידיות מאותו שולח יזוהו אוטומטית.')
                    ->success()->send();
            });
    }

    /**
     * Turn this ticket into a team task — prefilled with the ticket's subject,
     * customer and a link back to the ticket, assigned to the current user by
     * default. The ticket itself is untouched.
     */
    private function convertToTaskAction(): Actions\Action
    {
        return Actions\Action::make('convertToTask')
            ->label('צור משימה')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->fillForm(fn (): array => [
                'title' => $this->record->subject,
                'assignees' => [auth()->id()],
                'priority' => TicketPriority::Normal,
            ])
            ->form([
                TextInput::make('title')->label('כותרת')->required()->maxLength(255),
                Select::make('assignees')->label('אחראים')
                    ->options(User::orderBy('name')->pluck('name', 'id'))->multiple()->searchable()->placeholder('ללא שיוך'),
                Select::make('priority')->label('עדיפות')
                    ->options(TicketPriority::class)->default(TicketPriority::Normal)->required(),
                DateTimePicker::make('due_at')->label('מועד יעד')->seconds(false)->native(false),
            ])
            ->action(function (array $data): void {
                $task = Task::create([
                    'title' => $data['title'],
                    'priority' => $data['priority'],
                    'due_at' => $data['due_at'] ?? null,
                    'customer_id' => $this->record->customer_id,
                    'ticket_id' => $this->record->id,
                    'status' => TaskStatus::Open,
                ]);
                $task->assignees()->sync(array_filter((array) ($data['assignees'] ?? [])));

                // Notify the assignees (or managers if none) — same as any new task.
                NotifyTaskCreatedJob::dispatch($task->id);

                Notification::make()->title('נוצרה משימה מהפנייה')->success()->send();
            });
    }
}
