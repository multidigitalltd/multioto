<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\RunSiteAuditJob;
use App\Models\Site;
use App\Models\SiteAudit;
use App\Models\Task;
use App\Services\Audit\AuditReport;
use App\Services\Audit\Comparison;
use App\Services\Audit\PublicTarget;
use App\Services\Audit\SiteAuditor;
use App\Services\Security\DnsLookup;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * בדיקת אתר — מכניסים כתובת ומקבלים רשימה של מה שבור, מה חסר ומה כדאי לשפר,
 * ומתוכה מסמך PDF שאפשר לשלוח ללקוח.
 *
 * הבדיקה נעשית מבחוץ בלבד, בלי גישה לניהול האתר ובלי להתקין דבר. זו לא מגבלה
 * טכנית אלא בחירה: מי שמקלידים את כתובתו הוא בדרך כלל עדיין לא לקוח, וכל מה
 * שהדוח אומר הוא דבר שהוא יכול לאמת בעצמו — וגם מה שהמבקרים שלו רואים ממילא.
 */
class SiteAudits extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationGroup = 'כלים';

    protected static ?string $navigationLabel = 'בדיקת אתר';

    protected static ?string $title = 'בדיקת אתר — מה שבור, מה חסר ומה כדאי לשפר';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.site-audits';

    public ?array $data = ['url' => ''];

    public function mount(): void
    {
        $this->form->fill(['url' => '']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('כתובת האתר לבדיקה')
                    ->description('הבדיקה נעשית מבחוץ, כמו כל מבקר — בלי סיסמאות ובלי להתקין דבר באתר. '
                        .'היא לוקחת בין חצי דקה לשתי דקות ורצה ברקע.')
                    ->schema([
                        TextInput::make('url')
                            ->label('כתובת')
                            ->placeholder('example.co.il')
                            ->helperText('אפשר להקליד רק את שם הדומיין — https יתווסף לבד.')
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false),
                    ])
                    ->footerActions([
                        FormAction::make('audit')
                            ->label('בדוק את האתר')
                            ->icon('heroicon-o-play')
                            ->action('startAudit'),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Queue an audit for the address on screen.
     *
     * The address is checked here rather than in the job so a typo — or an
     * attempt to point the tool at something inside the network — is answered
     * on the screen, in front of the person who typed it, instead of becoming a
     * failed row they have to go and read.
     */
    public function startAudit(): void
    {
        if ($this->queue((string) ($this->form->getState()['url'] ?? ''))) {
            $this->form->fill(['url' => '']);
        }
    }

    /**
     * Run the same site again, from the row of an earlier audit.
     *
     * Deliberately not a shortcut around the address check: the name is
     * approved afresh every time, because where a name points is not a property
     * of the name and an address that was public last month need not be now.
     */
    public function recheck(SiteAudit $audit): void
    {
        $this->queue($audit->url);
    }

    /**
     * Turn the findings into one task with a checklist, for the team to work
     * through.
     *
     * One task and not one per finding: fixing a site is a single job, and a
     * list that arrives as fourteen separate tasks is a list nobody closes.
     * Keyed on the audit, so pressing the button twice does not open a second
     * copy of work already scheduled.
     *
     * @param  list<int>  $chosen  indexes into the audit's problems
     */
    public function createTask(SiteAudit $audit, array $chosen): void
    {
        $problems = $audit->problems();
        $picked = array_values(array_intersect_key($problems, array_flip($chosen)));

        if ($picked === []) {
            Notification::make()->title('לא נבחר אף ממצא')->warning()->send();

            return;
        }

        $existing = Task::where('source_ref', self::taskRef($audit))->first();

        if ($existing !== null) {
            Notification::make()
                ->title('כבר נפתחה משימה לבדיקה הזו')
                ->body('משימה מס\' '.$existing->id.' — '.$existing->title)
                ->warning()
                ->send();

            return;
        }

        $task = Task::create([
            'source_ref' => self::taskRef($audit),
            'title' => 'תיקון ממצאי בדיקת אתר — '.$audit->host,
            'description' => $this->brief($audit, $picked),
            'subtasks' => array_map(
                fn (array $finding): array => [
                    'title' => self::mark($finding).' '.($finding['title'] ?? ''),
                    'done' => false,
                ],
                $picked,
            ),
            'customer_id' => $audit->site?->customer_id,
            'status' => TaskStatus::Open,
            // The worst finding sets the priority: a task whose checklist opens
            // with an unreachable site is not a normal-priority task.
            'priority' => ($picked[0]['severity'] ?? '') === 'critical' ? TicketPriority::High : TicketPriority::Normal,
        ]);

        // Unassigned, so this reaches the managers — the same path every other
        // "new task" entry point uses. Non-fatal: the task is already saved.
        try {
            NotifyTaskCreatedJob::dispatch($task->id);
        } catch (\Throwable) {
            // A queue hiccup must not make a saved task look like a failed one.
        }

        Notification::make()
            ->title('נפתחה משימה')
            ->body('משימה מס\' '.$task->id.' עם '.count($picked).' פריטים לביצוע.')
            ->success()
            ->send();
    }

    /** The description: what to do, in the words the audit already used. */
    private function brief(SiteAudit $audit, array $picked): string
    {
        $lines = ['בדיקת אתר '.$audit->url.' מתאריך '
            .($audit->finished_at?->format('d/m/Y') ?? $audit->created_at->format('d/m/Y')).':', ''];

        foreach ($picked as $finding) {
            $lines[] = self::mark($finding).' '.($finding['title'] ?? '');

            if (! empty($finding['fix'])) {
                $lines[] = '   '.$finding['fix'];
            }
        }

        return implode("\n", $lines);
    }

    private static function mark(array $finding): string
    {
        return match ($finding['severity'] ?? '') {
            'critical' => '[מיידי]',
            'warning' => '[חשוב]',
            default => '[מומלץ]',
        };
    }

    private static function taskRef(SiteAudit $audit): string
    {
        return 'site-audit-'.$audit->id;
    }

    /**
     * The findings on offer, indexed the way the action reads them back.
     *
     * @return array<int, string>
     */
    private static function choices(SiteAudit $audit): array
    {
        $options = [];

        foreach ($audit->problems() as $index => $finding) {
            $options[$index] = self::mark($finding).' '.($finding['title'] ?? '');
        }

        return $options;
    }

    /** Validate the address and put one audit of it on the queue. */
    private function queue(string $address): bool
    {
        $url = SiteAuditor::normaliseUrl($address);
        $host = DnsLookup::host($url);

        try {
            app(PublicTarget::class)->assert($host);
        } catch (\Throwable $e) {
            Notification::make()->title('לא ניתן לבדוק את הכתובת')->body($e->getMessage())->danger()->send();

            return false;
        }

        $audit = SiteAudit::create([
            'url' => $url,
            'host' => $host,
            // Linked when it is a site we already look after, so the audit sits
            // beside everything else known about it — and left empty when it is
            // not, which is the ordinary case for this tool.
            'site_id' => Site::query()->get(['id', 'domain'])
                ->first(fn (Site $site): bool => DnsLookup::host((string) $site->domain) === $host)?->id,
            'user_id' => Auth::id(),
            'status' => SiteAudit::STATUS_RUNNING,
        ]);

        RunSiteAuditJob::dispatch($audit->id);

        Notification::make()
            ->title('הבדיקה יצאה לדרך')
            ->body('התוצאות יופיעו ברשימה שלמטה תוך דקה-שתיים. אפשר לרענן את הדף.')
            ->success()
            ->send();

        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SiteAudit::query()->latest('id'))
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('מתי')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('אתר')->wrap()->searchable()
                    ->url(fn (SiteAudit $record): string => $record->url, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')
                    ->badge()
                    // "נחסמה" is not a failure and not a pass: the site answered,
                    // but with a firewall page, so most of the audit could not
                    // run. A reader not told that reads a short list of findings
                    // as a short list of problems.
                    ->formatStateUsing(fn (string $state, SiteAudit $record): string => match (true) {
                        $state === SiteAudit::STATUS_RUNNING => 'בבדיקה',
                        $state === SiteAudit::STATUS_COMPLETED && $record->blocked() => 'נחסמה',
                        $state === SiteAudit::STATUS_COMPLETED => 'הושלמה',
                        default => 'נכשלה',
                    })
                    ->color(fn (string $state, SiteAudit $record): string => match (true) {
                        $state === SiteAudit::STATUS_COMPLETED && $record->blocked() => 'warning',
                        $state === SiteAudit::STATUS_COMPLETED => 'success',
                        $state === SiteAudit::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('critical')
                    ->label('מיידי')->badge()->color('danger')
                    ->state(fn (SiteAudit $record): string => (string) $record->count('critical')),
                Tables\Columns\TextColumn::make('warning')
                    ->label('חשוב')->badge()->color('warning')
                    ->state(fn (SiteAudit $record): string => (string) $record->count('warning')),
                Tables\Columns\TextColumn::make('notice')
                    ->label('מומלץ')->badge()->color('info')
                    ->state(fn (SiteAudit $record): string => (string) $record->count('notice')),
                Tables\Columns\TextColumn::make('error')
                    ->label('שגיאה')->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('findings')
                    ->label('ממצאים')
                    ->icon('heroicon-o-list-bullet')
                    ->visible(fn (SiteAudit $record): bool => $record->status === SiteAudit::STATUS_COMPLETED)
                    ->modalHeading(fn (SiteAudit $record): string => 'ממצאי הבדיקה — '.$record->host)
                    ->modalContent(fn (SiteAudit $record) => view('filament.pages.partials.site-audit-findings', [
                        'audit' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה')
                    ->modalWidth('4xl'),

                Tables\Actions\Action::make('changes')
                    ->label('מה השתנה')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('gray')
                    ->visible(fn (SiteAudit $record): bool => $record->status === SiteAudit::STATUS_COMPLETED)
                    ->modalHeading(fn (SiteAudit $record): string => 'מה השתנה מאז הבדיקה הקודמת — '.$record->host)
                    ->modalContent(fn (SiteAudit $record) => view('filament.pages.partials.site-audit-changes', [
                        'comparison' => Comparison::for($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה')
                    ->modalWidth('3xl'),

                Tables\Actions\Action::make('task')
                    ->label('פתיחת משימה')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->visible(fn (SiteAudit $record): bool => $record->status === SiteAudit::STATUS_COMPLETED
                        && $record->problems() !== [])
                    ->modalHeading('פתיחת משימה מממצאי הבדיקה')
                    ->modalDescription('הממצאים שייבחרו יהפכו לתת-משימות לסימון אחת-אחת. נפתחת משימה אחת — תיקון אתר הוא עבודה אחת.')
                    ->modalSubmitActionLabel('פתח משימה')
                    ->form(fn (SiteAudit $record): array => [
                        CheckboxList::make('findings')
                            ->label('ממצאים')
                            ->options(self::choices($record))
                            // Everything ticked by default: the audit already
                            // decided these are worth doing, and the person
                            // here is deciding what to leave out, not what to
                            // put in.
                            ->default(array_keys(self::choices($record)))
                            ->bulkToggleable()
                            ->columns(1),
                    ])
                    ->action(fn (SiteAudit $record, array $data) => $this->createTask(
                        $record,
                        array_map('intval', $data['findings'] ?? []),
                    )),

                Tables\Actions\Action::make('recheck')
                    ->label('בדיקה חוזרת')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('לבדוק שוב את האתר?')
                    ->modalDescription('הבדיקה תרוץ מחדש על אותה כתובת ותתווסף כשורה חדשה. הבדיקה הנוכחית נשמרת כפי שהיא.')
                    ->modalSubmitActionLabel('בדוק שוב')
                    ->action(fn (SiteAudit $record) => $this->recheck($record)),

                Tables\Actions\Action::make('pdf')
                    ->label('הורדת PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->visible(fn (SiteAudit $record): bool => $record->status === SiteAudit::STATUS_COMPLETED)
                    ->action(fn (SiteAudit $record): StreamedResponse => $this->download($record)),

                Tables\Actions\Action::make('delete')
                    ->label('מחיקה')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('למחוק את הבדיקה?')
                    ->action(fn (SiteAudit $record) => $record->delete()),
            ])
            ->emptyStateHeading('עדיין לא נבדק אף אתר')
            ->emptyStateDescription('הקלידו כתובת למעלה ולחצו "בדוק את האתר".');
    }

    private function download(SiteAudit $audit): StreamedResponse
    {
        $report = app(AuditReport::class);
        $pdf = $report->pdf($audit);

        return response()->streamDownload(
            fn () => print ($pdf),
            $report->filename($audit),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
