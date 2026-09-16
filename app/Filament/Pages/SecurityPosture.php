<?php

namespace App\Filament\Pages;

use App\Enums\SiteStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Jobs\LockOutSiteSessionsJob;
use App\Jobs\PurgeSiteThreatsJob;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Security\ThreatQuarantine;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * אבטחה — מה חסום, מה הוסר ומה עדיין פתוח, על כל האתרים במקום אחד.
 *
 * The security facts were each individually visible and collectively invisible:
 * the quarantine list lived in config, what the guard removed lived in one
 * site's event feed, and whether a site's keys were ever rotated lived nowhere
 * anybody looked. Answering "are we covered?" meant opening every site in turn.
 *
 * The screen is built around the two questions that actually get asked. What is
 * this system blocking on my behalf — the standing rules, stated plainly. And
 * what has it done lately, with the failures first: a site the guard could not
 * clean, or whose keys could not be replaced, is the only row on here that is
 * somebody's job today.
 */
class SecurityPosture extends Page implements HasTable
{
    use InteractsWithTable;
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    // The same group the sites themselves live under, which is also what gates
    // it: an invented group has no module key, so a team member limited to
    // support or finance would have been able to open this screen.
    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'אבטחה';

    protected static ?string $title = 'אבטחת אתרים';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.security-posture';

    /**
     * The standing rules, for the panel to state rather than the team to
     * remember. Read from the same config the enforcement reads, so a screen
     * that says a login is blocked cannot be describing a rule that was turned
     * off months ago.
     *
     * @return array<int, array{icon: string, title: string, detail: string, active: bool}>
     */
    public function rules(): array
    {
        $quarantineOn = ThreatQuarantine::enabled();
        $users = ThreatQuarantine::users();
        $plugins = ThreatQuarantine::plugins();
        $rotation = (bool) config('security.key_rotation.enabled', true);

        return [
            [
                'icon' => '👤',
                'title' => 'משתמשים שנמחקים מיד עם הופעתם',
                'detail' => $users === []
                    ? 'לא מוגדר אף שם משתמש.'
                    : implode(', ', $users).' — נמחקים בלי לבקש אישור, בכל אתר שהתוסף מותקן בו.',
                'active' => $quarantineOn && $users !== [],
            ],
            [
                'icon' => '🧩',
                'title' => 'תוספים שנמחקים מיד עם התקנתם',
                'detail' => $plugins === []
                    ? 'לא מוגדר אף תוסף.'
                    : implode(', ', $plugins).' — נמחקים בלי לבקש אישור, בכל אתר שהתוסף מותקן בו.',
                'active' => $quarantineOn && $plugins !== [],
            ],
            [
                'icon' => '🔐',
                'title' => 'החלפת מפתחות הצפנה חודשית',
                'detail' => $rotation
                    ? sprintf(
                        'ב-%d לחודש בשעה %02d:00 — מפתחות חדשים וניתוק כל ההתחברויות בכל אתר מחובר, מפוזר על פני %d דקות. הלקוח יתנתק גם הוא.',
                        max(1, min(28, (int) config('security.key_rotation.day', 1))),
                        max(0, min(23, (int) config('security.key_rotation.hour', 4))),
                        max(0, (int) config('security.key_rotation.spread_minutes', 120)),
                    )
                    : 'כבויה. מפתחות מוחלפים רק ידנית או אחרי חשד לפריצה.',
                'active' => $rotation,
            ],
            [
                'icon' => '🚨',
                'title' => 'אחרי חשד לפריצה',
                'detail' => 'ברגע שהשומר מסיר משתמש או תוסף מרשימת ההסגר — מפתחות ההצפנה מוחלפים וכל ההתחברויות מנותקות אוטומטית, '
                    .'כי מחיקת החשבון שנפתח אינה עושה דבר לדפדפן שכבר מחובר.',
                'active' => $quarantineOn,
            ],
        ];
    }

    /** Sites the enforcement cannot reach at all — it protects none of them. */
    public function unprotectedSites(): int
    {
        return Site::query()
            ->where('status', SiteStatus::Active)
            ->where(fn (Builder $q) => $q->where('mcp_enabled', false)->orWhereNull('mcp_endpoint'))
            ->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SiteEvent::query()
                    ->whereIn('type', ['threat_purged', 'threat_found', 'sessions_locked', 'sessions_lock_failed'])
                    ->with('site.customer'),
            )
            // Failures first, then newest. A site the guard could not clean is
            // the only row here that is somebody's job today, and sorting by
            // date alone buries it under a month of routine rotations.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'warning' THEN 1 ELSE 2 END")
                ->orderByDesc('detected_at'))
            ->columns([
                Tables\Columns\TextColumn::make('detected_at')
                    ->label('מתי')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')
                    ->searchable()
                    ->url(fn (SiteEvent $record): ?string => $record->site_id
                        ? route('filament.admin.resources.sites.view', ['record' => $record->site_id])
                        : null),
                Tables\Columns\TextColumn::make('site.customer.name')
                    ->label('לקוח')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('מה קרה')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (SiteEvent::TYPES[$state][1] ?? $state))
                    ->color(fn (SiteEvent $record): string => match ($record->severity) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('title')
                    ->label('פירוט')
                    ->wrap()
                    ->description(fn (SiteEvent $record): ?string => $record->detail ?: null),
            ])
            ->filters([
                Tables\Filters\Filter::make('open')
                    ->label('רק מה שדורש טיפול')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('severity', SiteEvent::ACTIONABLE_SEVERITIES)
                        ->whereNull('acknowledged_at')),
                Tables\Filters\SelectFilter::make('type')
                    ->label('סוג')
                    ->options([
                        'threat_purged' => 'סימן פריצה הוסר',
                        'threat_found' => 'סימן פריצה שלא הוסר',
                        'sessions_locked' => 'התחברויות נותקו',
                        'sessions_lock_failed' => 'ניתוק שלא הושלם',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('lockOut')
                    ->label('נתק התחברויות')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (SiteEvent $record): bool => $record->site?->mcp_enabled === true)
                    ->requiresConfirmation()
                    ->modalHeading('החלפת מפתחות וניתוק כל ההתחברויות')
                    // The price is stated before the button, not after: every
                    // user on the customer's site is signed out, the customer
                    // included, and they will not know why unless somebody
                    // tells them.
                    ->modalDescription(fn (SiteEvent $record): string => "כל המשתמשים באתר {$record->site?->domain} יתנתקו מיד ויצטרכו להתחבר מחדש — כולל הלקוח. סיסמאות, תוכן ומסד הנתונים אינם משתנים.")
                    ->modalSubmitActionLabel('החלף ונתק')
                    ->action(function (SiteEvent $record): void {
                        LockOutSiteSessionsJob::dispatch($record->site_id, LockOutSiteSessionsJob::REASON_INTRUSION);

                        Notification::make()->title('הפעולה נשלחה לאתר')
                            ->body('התוצאה תירשם כאן ותישלח בהתראה.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('לא נרשם אף אירוע אבטחה')
            ->emptyStateDescription('כשהשומר יסיר משהו, או כשיוחלפו מפתחות, זה יופיע כאן.')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sweepAll')
                ->label('סרוק והסר בכל האתרים')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('סריקת הסגר בכל האתרים')
                // Says what it does, not what it used to do. The sweep runs the
                // guard, the guard removes what it finds, and a find now signs
                // that site's users out — approving this on a promise that
                // nothing gets disconnected would be approving it blind.
                ->modalDescription(
                    'כל אתר מחובר ייבדק עכשיו: מה מרשימת ההסגר נמצא בו, ומה השומר כבר הסיר. '
                    .'באתר נקי לא משתנה דבר — אבל באתר שנמצא בו משתמש או תוסף מרשימת ההסגר, '
                    .'השומר יסיר אותו וכל המשתמשים באתר ההוא ינותקו ויצטרכו להתחבר מחדש, כולל הלקוח.'
                )
                ->modalSubmitActionLabel('סרוק והסר מה שנמצא')
                ->action(function (): void {
                    $ids = Site::query()
                        ->where('mcp_enabled', true)
                        ->whereNotNull('mcp_endpoint')
                        ->pluck('id');

                    $ids->each(fn (int $id) => PurgeSiteThreatsJob::dispatch($id));

                    Notification::make()->title("נשלחה סריקה ל-{$ids->count()} אתרים")
                        ->body('הממצאים יופיעו כאן עם סיום הבדיקה.')->success()->send();
                }),
        ];
    }
}
