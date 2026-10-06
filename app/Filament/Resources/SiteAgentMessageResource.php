<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\SiteAgentMessageResource\Pages;
use App\Models\SiteAgentMessage;
use App\Models\SiteAgentSubscriber;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * שיחות הבוט — what owners wrote to the site agent, and what it answered.
 *
 * The request journal shows only the turns that became a change. This shows
 * all of them — the questions it misunderstood, the answers that were too
 * long, the requests it refused — which is what the team reads to improve the
 * standing instructions on the product settings screen.
 *
 * Admins only and read-only: the answers quote the sites' own customers
 * (names, phones, orders), and the transcript is pruned after
 * siteagent.assistant.transcript_days.
 */
class SiteAgentMessageResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SiteAgentMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'שיחות הבוט';

    protected static ?string $modelLabel = 'הודעה';

    protected static ?string $pluralModelLabel = 'שיחות בוט ניהול האתר';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['subscriber:id,phone,name,site_id', 'subscriber.site:id,domain']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('מתי')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber.phone')
                    ->label('שיחה')
                    ->searchable()
                    ->description(fn (SiteAgentMessage $record): ?string => collect([$record->subscriber?->name, $record->subscriber?->site?->domain])->filter()->implode(' · ') ?: null)
                    // One click narrows the list to this conversation alone.
                    ->url(fn (SiteAgentMessage $record): string => self::getUrl('index', [
                        'tableFilters' => ['site_agent_subscriber_id' => ['value' => $record->site_agent_subscriber_id]],
                    ])),
                Tables\Columns\TextColumn::make('role')
                    ->label('מי')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === SiteAgentMessage::USER ? 'בעל האתר' : 'הבוט')
                    ->color(fn (string $state): string => $state === SiteAgentMessage::USER ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('body')
                    ->label('תוכן')
                    ->searchable()
                    ->wrap()
                    ->limit(400)
                    ->tooltip(fn (SiteAgentMessage $record): string => $record->body),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('מי')
                    ->options([SiteAgentMessage::USER => 'בעל האתר', SiteAgentMessage::ASSISTANT => 'הבוט']),
                Tables\Filters\SelectFilter::make('site_agent_subscriber_id')
                    ->label('שיחה')
                    ->options(fn (): array => SiteAgentSubscriber::query()
                        ->whereHas('messages')
                        ->orderBy('phone')
                        ->with('site:id,domain')
                        ->get(['id', 'phone', 'name', 'site_id'])
                        // One number may manage several sites — one conversation each.
                        ->mapWithKeys(fn (SiteAgentSubscriber $s): array => [
                            $s->id => collect([$s->phone, $s->name, $s->site?->domain])->filter()->implode(' · '),
                        ])
                        ->all())
                    ->searchable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteAgentMessages::route('/'),
        ];
    }
}
