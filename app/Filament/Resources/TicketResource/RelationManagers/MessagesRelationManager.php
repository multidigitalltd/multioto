<?php

namespace App\Filament\Resources\TicketResource\RelationManagers;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Jobs\SendTicketReplyJob;
use App\Models\CannedResponse;
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
                Tables\Columns\TextColumn::make('body')->label('תוכן')->wrap()->limit(120),
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
            ])
            ->actions([])
            ->bulkActions([]);
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
}
