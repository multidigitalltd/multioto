<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Resources\TicketResource;
use App\Models\Setting;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * דירוגי לקוחות — מה הלקוחות באמת כתבו.
 *
 * הדירוג וההערה נשמרו מאז ומתמיד, אבל ההערה הופיעה רק כ-tooltip על עמודה
 * ברשימת הפניות: כדי לקרוא מה לקוח כתב היה צריך לדעת לרחף מעל המקום הנכון.
 * משוב שנאסף ואי אפשר לקרוא אותו הוא משוב שלא נאסף.
 */
class CustomerRatings extends Page implements HasTable
{
    use InteractsWithTable;
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?string $navigationLabel = 'דירוגי לקוחות';

    protected static ?string $title = 'דירוגי לקוחות — מה הלקוחות כתבו';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.pages.collections';

    public function table(Table $table): Table
    {
        return $table
            ->query(Ticket::query()->whereNotNull('csat_rating')->with('customer'))
            ->defaultSort('csat_rated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('csat_rating')
                    ->label('דירוג')
                    ->state(fn (Ticket $record): string => str_repeat('★', (int) $record->csat_rating)
                        .str_repeat('☆', 5 - (int) $record->csat_rating))
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')
                    ->state(fn (Ticket $record): string => $record->customer?->name ?? $record->senderName())
                    ->searchable(),
                // The whole reason this screen exists: the words, in full, in a
                // column — not hidden behind a hover.
                Tables\Columns\TextColumn::make('csat_comment')
                    ->label('מה נכתב')
                    ->placeholder('— ללא הערה —')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject')->label('פנייה')->limit(40),
                Tables\Columns\TextColumn::make('csat_rated_at')->label('דורג')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('csat_rating')
                    ->label('דירוג')
                    ->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★']),
                Tables\Filters\Filter::make('with_comment')
                    ->label('רק עם הערה')
                    ->query(fn ($query) => $query->whereNotNull('csat_comment')),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('פתח פנייה')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('אין עדיין דירוגים')
            ->emptyStateDescription('דירוג נשלח ללקוח אוטומטית כשפנייה נסגרת.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('googleLink')
                ->label('קישור לביקורת בגוגל')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->modalHeading('קישור לביקורת בגוגל')
                ->modalDescription('לקוח שנתן חמישה כוכבים יראה מיד אחרי התודה כפתור שמזמין אותו לכתוב גם בגוגל. בלי קישור — לא מוצג כלום.')
                ->fillForm(fn (): array => ['url' => (string) config('billing.support.csat.google_review_url')])
                ->form([
                    Forms\Components\TextInput::make('url')
                        ->label('כתובת הביקורת')
                        ->url()
                        ->placeholder('https://g.page/r/…/review')
                        ->helperText('בגוגל: הפרופיל העסקי ← בקשת ביקורות ← העתקת הקישור. השאירו ריק כדי לא להציג את הכפתור.')
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $url = trim((string) ($data['url'] ?? ''));

                    Setting::put('support.csat_google_url', $url !== '' ? $url : null);
                    config(['billing.support.csat.google_review_url' => $url]);

                    Notification::make()
                        ->title($url === '' ? 'הכפתור בגוגל כובה' : 'הקישור נשמר')
                        ->body($url === ''
                            ? 'לקוחות מרוצים יראו רק את הודעת התודה.'
                            : 'לקוח שידרג חמישה כוכבים יוזמן לכתוב ביקורת בגוגל.')
                        ->success()->send();
                }),
        ];
    }
}
