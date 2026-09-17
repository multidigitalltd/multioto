<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\SiteAgentRequestResource\Pages;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * יומן סוכן האתר — what customers changed on their own sites, and when.
 *
 * The agent edits live websites on a customer's say-so, with no one from the
 * team in the loop. That is the product, and it is also why this screen exists:
 * when a customer rings up because "משהו באתר השתנה", the answer has to be a
 * record — their own words, the exact text they were shown, the moment they
 * said yes — and not a reconstruction.
 *
 * Read-only by construction. Nothing here may be created, edited or deleted
 * from the panel: a journal somebody can rewrite answers no question at all.
 */
class SiteAgentRequestResource extends Resource
{
    use RespectsModuleAccess;

    protected static ?string $model = SiteAgentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'יומן סוכן האתר';

    protected static ?string $modelLabel = 'בקשה';

    protected static ?string $pluralModelLabel = 'בקשות לסוכן האתר';

    protected static ?int $navigationSort = 9;

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

    /** Human names for the closed vocabulary of operations. */
    private const OPERATIONS = [
        SiteAgentRequest::OP_APPEND => 'הוספת טקסט',
        SiteAgentRequest::OP_REPLACE => 'החלפת טקסט',
        SiteAgentRequest::OP_TITLE => 'שינוי כותרת',
        SiteAgentRequest::OP_PRICE => 'שינוי מחיר',
        SiteAgentRequest::OP_STOCK => 'שינוי מלאי',
        SiteAgentRequest::OP_IMAGE => 'החלפת תמונה',
    ];

    private const STATES = [
        SiteAgentRequest::AWAITING => 'ממתין לאישור',
        SiteAgentRequest::APPLIED => 'בוצע',
        SiteAgentRequest::CANCELED => 'בוטל לפני ביצוע',
        SiteAgentRequest::EXPIRED => 'פג בלי תשובה',
        SiteAgentRequest::FAILED => 'נכשל',
        SiteAgentRequest::REVERTED => 'הוחזר לקדמותו',
    ];

    public static function getEloquentQuery(): Builder
    {
        // Three columns read a relation each. Loaded with the page, and only the
        // columns the page shows.
        return parent::getEloquentQuery()
            ->with(['site:id,domain', 'customer:id,name', 'subscriber:id,phone,name']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('מתי')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')
                    ->searchable()
                    ->description(fn (SiteAgentRequest $record): ?string => $record->customer?->name),
                Tables\Columns\TextColumn::make('subscriber.phone')
                    ->label('מי ביקש')
                    ->searchable()
                    ->description(fn (SiteAgentRequest $record): ?string => $record->subscriber?->name),
                Tables\Columns\TextColumn::make('message')
                    ->label('מה ביקשו')
                    // Their own words, in full on hover. Truncating without the
                    // tooltip would leave the one column that answers "what did
                    // they actually ask for" unable to answer it.
                    ->limit(60)
                    ->tooltip(fn (SiteAgentRequest $record): string => (string) $record->message)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('operation')
                    ->label('פעולה')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => self::OPERATIONS[$state] ?? '—'),
                Tables\Columns\TextColumn::make('state')
                    ->label('מצב')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SiteAgentRequest::APPLIED => 'success',
                        SiteAgentRequest::FAILED => 'danger',
                        SiteAgentRequest::AWAITING => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (SiteAgentRequest $record): ?string => $record->failure_reason),
                // What the customer was actually shown before they said yes.
                // Off by default because it is long, and there because the
                // approval was given to THIS text and nothing else.
                Tables\Columns\TextColumn::make('preview')
                    ->label('מה הוצג לאישור')
                    ->limit(80)
                    ->tooltip(fn (SiteAgentRequest $record): ?string => $record->preview)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->label('מצב')
                    ->options(self::STATES),
                Tables\Filters\SelectFilter::make('site_id')
                    ->label('אתר')
                    ->options(fn (): array => Site::query()
                        ->whereHas('siteAgentRequests')
                        ->orderBy('domain')
                        ->pluck('domain', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSiteAgentRequests::route('/'),
        ];
    }
}
