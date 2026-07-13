<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Jobs\SendTicketReplyJob;
use App\Models\CannedResponse;
use App\Models\TicketMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The ticket conversation thread. Agents read the full history and send a reply
 * that is routed back to the customer's original channel via SendTicketReplyJob.
 * Internal notes stay internal and are never delivered.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'שיחה';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('channel')
                ->label('ערוץ')
                ->options([
                    MessageChannel::Whatsapp->value => 'וואטסאפ',
                    MessageChannel::Email->value => 'מייל',
                    MessageChannel::InternalNote->value => 'הערה פנימית',
                ])
                ->default(fn () => $this->defaultReplyChannel())
                ->required()
                ->live(),

            Forms\Components\Select::make('canned_response')
                ->label('תבנית מענה מהיר')
                ->options(fn () => CannedResponse::orderBy('title')->pluck('title', 'id'))
                ->searchable()
                ->dehydrated(false)
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    if ($state && $canned = CannedResponse::find($state)) {
                        $set('body', $canned->body);
                    }
                })
                ->visible(fn (Forms\Get $get) => $get('channel') !== MessageChannel::InternalNote->value),

            Forms\Components\Textarea::make('body')
                ->label('תוכן')
                ->required()
                ->rows(5)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('direction')
                    ->label('כיוון')
                    ->badge()
                    ->formatStateUsing(fn (MessageDirection $state) => $state === MessageDirection::Inbound ? 'נכנס' : 'יוצא')
                    ->color(fn (MessageDirection $state) => $state === MessageDirection::Inbound ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('channel')->label('ערוץ')->badge(),
                Tables\Columns\TextColumn::make('author')->label('מאת')->badge(),
                // Preserve the message's line breaks (email/WhatsApp arrive with
                // real newlines) instead of collapsing them into one run.
                Tables\Columns\TextColumn::make('body')->label('תוכן')->wrap()
                    ->extraAttributes(['style' => 'white-space: pre-line;'])
                    ->limit(600),
                // Inbound files a customer sent — openable/downloadable links.
                Tables\Columns\TextColumn::make('attachments')
                    ->label('קבצים')
                    ->placeholder('—')
                    ->html()
                    ->formatStateUsing(fn (?array $state, TicketMessage $record): string => self::attachmentLinks($record)),
                Tables\Columns\TextColumn::make('created_at')->label('מתי')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('מענה / הערה')
                    ->modalHeading('שליחת מענה')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['direction'] = MessageDirection::Outbound->value;
                        $data['author'] = MessageAuthor::Agent->value;

                        return $data;
                    })
                    ->after(function (Model $record): void {
                        // Deliver everything except internal notes to the channel.
                        if ($record->channel !== MessageChannel::InternalNote) {
                            SendTicketReplyJob::dispatch($record->id);
                        }
                    }),

                // Human-in-the-loop for the AI layer: opens the reply form
                // pre-filled with the latest AI draft. The agent reviews/edits
                // and only then sends — the AI never messages a customer itself.
                Tables\Actions\Action::make('approveAiDraft')
                    ->label('אישור טיוטת AI')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn () => $this->latestAiDraft() !== null)
                    ->form([
                        Forms\Components\Select::make('channel')
                            ->label('ערוץ')
                            ->options([
                                MessageChannel::Whatsapp->value => 'וואטסאפ',
                                MessageChannel::Email->value => 'מייל',
                            ])
                            ->default(fn () => $this->defaultReplyChannel())
                            ->required(),
                        Forms\Components\Textarea::make('body')
                            ->label('תוכן (ניתן לעריכה לפני שליחה)')
                            ->default(fn () => $this->extractDraftReply($this->latestAiDraft()))
                            ->required()
                            ->rows(6),
                    ])
                    ->action(function (array $data): void {
                        $message = $this->getOwnerRecord()->messages()->create([
                            'direction' => MessageDirection::Outbound,
                            'channel' => MessageChannel::from($data['channel']),
                            'body' => $data['body'],
                            'author' => MessageAuthor::Agent,
                        ]);

                        SendTicketReplyJob::dispatch($message->id);
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    /**
     * Render a message's attachments as safe, openable links (name + URL both
     * escaped) to the signed, team-only attachment route. Empty when none.
     */
    protected static function attachmentLinks(TicketMessage $message): string
    {
        if (blank($message->attachments)) {
            return '';
        }

        $links = [];
        foreach ($message->attachments as $i => $attachment) {
            $url = route('support.attachment', ['message' => $message->id, 'index' => $i]);
            $name = e($attachment['name'] ?? 'קובץ מצורף');
            $links[] = '<a href="'.e($url).'" target="_blank" rel="noopener" class="text-primary-600 underline">📎 '.$name.'</a>';
        }

        return implode('<br>', $links);
    }

    /**
     * Pre-select the reply channel to match how the ticket came in.
     */
    protected function defaultReplyChannel(): string
    {
        return match ($this->getOwnerRecord()->channel) {
            TicketChannel::Whatsapp => MessageChannel::Whatsapp->value,
            default => MessageChannel::Email->value,
        };
    }

    /**
     * The most recent unsent AI draft on this ticket, if any.
     */
    protected function latestAiDraft(): ?TicketMessage
    {
        return $this->getOwnerRecord()->messages()
            ->where('author', MessageAuthor::Ai)
            ->where('channel', MessageChannel::InternalNote)
            ->where('body', 'like', '%טיוטת תשובה%')
            ->latest('created_at')
            ->first();
    }

    /**
     * Strip the "🤖 טיוטת תשובה …:" preamble to recover the raw reply text.
     */
    protected function extractDraftReply(?TicketMessage $draft): string
    {
        if (! $draft) {
            return '';
        }

        $parts = explode("\n\n", $draft->body, 2);

        return trim($parts[1] ?? $draft->body);
    }
}
