<?php

namespace App\Filament\Pages;

use App\Enums\SiteStatus;
use App\Filament\Concerns\RespectsModuleAccess;
use App\Jobs\LockOutSiteSessionsJob;
use App\Jobs\PurgeSiteThreatsJob;
use App\Models\SecurityRule;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Security\ThreatQuarantine;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        // The BUILT-IN lists, not everything watched: these two cards promise
        // automatic deletion, and that promise is only true of the names the
        // site's own plugin carries. A rule the team added is watched and
        // reported, and appears below under its own heading.
        $users = ThreatQuarantine::builtInUsers();
        $plugins = ThreatQuarantine::builtInPlugins();
        $rotation = (bool) config('security.key_rotation.enabled', true);

        return [
            ...$this->ownRules(),
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

    /**
     * The rules the team added, for the list of standing rules to include.
     *
     * @return array<int, array{icon: string, title: string, detail: string, active: bool}>
     */
    private function ownRules(): array
    {
        $rules = rescue(
            fn () => SecurityRule::query()->orderBy('type')->orderBy('value')->get(),
            collect(),
            report: false,
        );

        if ($rules->isEmpty()) {
            return [];
        }

        $quarantineOn = ThreatQuarantine::enabled();

        return $rules->groupBy('type')->map(fn ($group, string $type): array => [
            'icon' => $type === SecurityRule::USER ? '👤' : '🧩',
            'title' => $type === SecurityRule::USER
                ? 'משתמשים שהוספתם למעקב'
                : 'תוספים שהוספתם למעקב',
            // Said in full every time: these are NOT removed automatically on a
            // site that guards itself, and a team that believes they are would
            // add a rule and stop looking.
            'detail' => $group->map(fn (SecurityRule $rule): string => $rule->value.($rule->enabled ? '' : ' (מושהה)'))->implode(', ')
                .($quarantineOn
                    ? ' — נמצאים ומדווחים. אינם נמחקים אוטומטית: המחיקה האוטומטית נקבעת בתוסף שבאתר.'
                    : ' — לא נבדקים כרגע: בדיקת ההסגר כבויה במערכת, וכל עוד היא כבויה אף כלל אינו נאכף.'),
            // Gated on the same switch the enforcement reads. With the
            // quarantine off, PurgeSiteThreatsJob returns before it looks at
            // anything — a card that still reads "active" would be telling the
            // team a name is being watched for on sites nobody is scanning.
            'active' => $quarantineOn && $group->contains(fn (SecurityRule $rule): bool => $rule->enabled),
        ])->values()->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->manageRulesAction(),
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

    /**
     * Add and remove watch rules without a deploy.
     *
     * One repeater rather than an add form plus a list plus a delete button:
     * the whole set is on screen at once, which is how somebody actually
     * decides whether a name still belongs there. A list you can only append to
     * is a list that only grows.
     *
     * The modal says plainly what a rule does and does not do. That sentence is
     * the most important thing on this screen: automatic removal is decided by
     * the plugin ON the site, from a list hard coded there, so that nothing sent
     * over the network can widen what a site deletes. Somebody who takes over
     * this panel cannot use it to order every customer's accounts destroyed —
     * and the price of that guarantee is that a rule added here finds and
     * reports rather than removes.
     */
    private function manageRulesAction(): Action
    {
        return Action::make('manageRules')
            ->label('כללי מעקב')
            ->icon('heroicon-o-plus-circle')
            ->modalWidth('3xl')
            ->modalHeading('כללי מעקב משלכם')
            ->modalDescription(
                'שם משתמש או תוסף שראיתם באתר פרוץ ואתם רוצים שהמערכת תחפש בכל האתרים. '
                .'כלל שמוסיפים כאן נמצא ומדווח — הוא אינו נמחק אוטומטית: המחיקה האוטומטית נקבעת בתוסף שמותקן באתר עצמו, '
                .'כדי ששום דבר שנשלח ברשת לא יוכל להרחיב את מה שאתר מוחק. באתר עם תוסף ישן (לפני 1.5.0) המערכת גם תכבה תוסף תואם.'
            )
            ->modalSubmitActionLabel('שמירת הכללים')
            ->fillForm(fn (): array => [
                'rules' => SecurityRule::query()
                    ->orderBy('type')->orderBy('value')
                    ->get(['type', 'value', 'note', 'enabled'])
                    ->map(fn (SecurityRule $rule): array => [
                        'type' => $rule->type,
                        'value' => $rule->value,
                        'note' => $rule->note,
                        'enabled' => $rule->enabled,
                    ])->all(),
            ])
            ->form([
                Forms\Components\Placeholder::make('builtins')
                    ->label('מובנים במערכת (אינם ניתנים לעריכה)')
                    ->content(fn (): string => implode(' · ', array_merge(
                        array_map(fn (string $u): string => "משתמש: {$u}", ThreatQuarantine::builtInUsers()),
                        array_map(fn (string $p): string => "תוסף: {$p}", ThreatQuarantine::builtInPlugins()),
                    )) ?: '—')
                    ->helperText('אלה היחידים שנמחקים אוטומטית מהאתר, על ידי התוסף עצמו.'),

                Forms\Components\Repeater::make('rules')
                    ->label('הכללים שלכם')
                    ->addActionLabel('הוספת כלל')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('סוג')
                            ->options([
                                SecurityRule::USER => 'שם משתמש',
                                SecurityRule::PLUGIN => 'תוסף (slug)',
                            ])
                            ->default(SecurityRule::USER)
                            ->required(),
                        Forms\Components\TextInput::make('value')
                            ->label('הערך המדויק')
                            ->required()
                            ->maxLength(190)
                            // Exact, never a pattern — the same rule the matcher
                            // follows. Said here because somebody typing a name
                            // into a box reasonably expects it to match "close
                            // enough", and a near-miss is reported, not acted on.
                            ->helperText('התאמה מדויקת בלבד. sys_maint2 אינו sys_maint.'),
                        Forms\Components\TextInput::make('note')
                            ->label('למה')
                            ->maxLength(500)
                            ->helperText('כלל שאיש אינו יודע להסביר הוא כלל שאיש לא יעז להסיר.'),
                        Forms\Components\Toggle::make('enabled')
                            ->label('פעיל')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $this->saveRules($data['rules'] ?? []);
            });
    }

    /**
     * Replace the stored rules with what the form holds.
     *
     * Done inside one transaction, because the in-between state — the old rules
     * deleted and the new ones not yet written — is a moment in which the hourly
     * sweep would read an empty watch list and report every site as clean.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveRules(array $rows): void
    {
        $kept = [];

        DB::transaction(function () use ($rows, &$kept): void {
            foreach ($rows as $row) {
                $value = mb_strtolower(trim((string) ($row['value'] ?? '')));
                $type = in_array($row['type'] ?? null, SecurityRule::TYPES, true) ? $row['type'] : SecurityRule::USER;

                if ($value === '') {
                    continue;
                }

                // firstOrNew rather than insert: the same name typed twice, or a
                // name that already exists, is an edit — not a second row the
                // unique index would reject and the whole save with it.
                $rule = SecurityRule::firstOrNew(['type' => $type, 'value' => $value]);
                $rule->note = filled($row['note'] ?? null) ? (string) $row['note'] : null;
                $rule->enabled = (bool) ($row['enabled'] ?? true);

                // Only ever on the way in. Every save submits every row on the
                // form, so writing this each time would make whoever last
                // opened the modal the author of every rule in it — and "who
                // said so" is the part of a rule nobody can reconstruct later.
                $rule->created_by ??= auth()->id();

                $rule->save();

                $kept[] = $type.'|'.$value;
            }

            // Whatever is no longer on the form was removed on it.
            SecurityRule::query()->get(['id', 'type', 'value'])
                ->reject(fn (SecurityRule $rule): bool => in_array($rule->type.'|'.$rule->value, $kept, true))
                ->each(fn (SecurityRule $rule) => $rule->delete());
        });

        Notification::make()
            ->title('כללי המעקב נשמרו')
            ->body(count($kept).' כללים פעילים. הם ייבדקו בסריקה הבאה, ואפשר גם להריץ סריקה עכשיו.')
            ->success()
            ->send();
    }
}
