<?php

namespace App\Filament\Pages;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Filament\Concerns\AdminOnly;
use App\Jobs\ScanSiteOpportunitiesJob;
use App\Models\OpportunityNote;
use App\Models\Site;
use App\Models\Task;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * ראדאר הזדמנויות — במקום לחפש מה למכור, המערכת מוצאת. כל אתר נסרק שבועית,
 * והממצאים שכבר קיימים (נגישות, מסמכי חובה, פגיעויות, מוניטין, מהירות, קישורים
 * שבורים, SEO, PHP ישן) מתורגמים לרשימת עבודה מתומחרת עם ההוכחה שמאחוריה.
 *
 * הזדמנות שלא מתאימה ללקוח מסוים אפשר לדחות, והיא לא תחזור בסריקה הבאה. הזדמנות
 * שכבר הוצעה מסומנת ככזו, כדי שהרשימה תשקף מה באמת פתוח ולא מה שנמצא פעם.
 *
 * המחירים הם נקודת פתיחה להצעה ומוצגים לצוות בלבד.
 */
class OpportunityRadarPage extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'ראדאר הזדמנויות';

    protected static ?string $title = 'ראדאר הזדמנויות — עבודה שאפשר להציע ללקוחות';

    protected static ?int $navigationSort = 24;

    protected static string $view = 'filament.pages.opportunity-radar';

    /** Which verdict to show: open (no verdict), offered, or dismissed. */
    public string $filter = 'open';

    /** Free-text match on the domain or the customer name. */
    public string $search = '';

    /** Show only what is marked urgent. */
    public bool $urgentOnly = false;

    /** @var array<string, string> */
    public const FILTERS = [
        'open' => 'פתוחות',
        'offered' => 'הוצעו ללקוח',
        'dismissed' => 'נדחו',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rescan')
                ->label('סרוק את כל האתרים עכשיו')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('סריקת הזדמנויות לכל האתרים')
                ->modalDescription('נסרוק כל אתר מחדש: נגישות, מסמכים, מוניטין, מהירות, קישורים שבורים ו-SEO. הסריקה רצה ברקע ועשויה להימשך כמה דקות. הזדמנויות שנדחו יישארו דחויות.')
                ->modalSubmitActionLabel('סרוק עכשיו')
                ->action(function (): void {
                    $count = 0;

                    Site::query()->whereNotNull('domain')->pluck('id')
                        ->each(function (int $id) use (&$count): void {
                            ScanSiteOpportunitiesJob::dispatch($id);
                            $count++;
                        });

                    Notification::make()->title("הסריקה רצה ברקע ל-{$count} אתרים")
                        ->body('רעננו את העמוד בעוד כמה דקות כדי לראות את התוצאות המעודכנות.')
                        ->success()->send();
                }),
        ];
    }

    /**
     * "Not relevant here" — with a reason, because in three months nobody will
     * remember why the accessibility work was waved away on this one site.
     */
    public function dismissAction(): Actions\Action
    {
        return Actions\Action::make('dismiss')
            ->label('לא רלוונטי')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->modalHeading('דחיית הזדמנות')
            ->modalDescription('ההזדמנות תוסתר מהרשימה הפתוחה ולא תחזור בסריקות הבאות. תמיד אפשר להחזיר אותה מלשונית "נדחו".')
            ->modalSubmitActionLabel('דחה')
            ->form([
                Forms\Components\TextInput::make('reason')
                    ->label('סיבה (אופציונלי)')
                    ->placeholder('למשל: הלקוח כבר עשה את זה מול ספק אחר')
                    ->maxLength(190),
            ])
            ->action(function (array $arguments, array $data): void {
                OpportunityNote::decide(
                    (int) $arguments['site'], (string) $arguments['key'],
                    OpportunityNote::DISMISSED, $data['reason'] ?: null,
                );

                Notification::make()->title('ההזדמנות נדחתה')->success()->send();
            });
    }

    /** Already quoted — off the open list, but not forgotten. */
    public function markOfferedAction(): Actions\Action
    {
        return Actions\Action::make('markOffered')
            ->label('סומן כהוצע')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->action(function (array $arguments): void {
                OpportunityNote::decide(
                    (int) $arguments['site'], (string) $arguments['key'], OpportunityNote::OFFERED,
                );

                Notification::make()->title('ההזדמנות סומנה כהוצעה ללקוח')->success()->send();
            });
    }

    /** Undo — the verdict is deleted, and the finding returns to the open list. */
    public function restoreAction(): Actions\Action
    {
        return Actions\Action::make('restore')
            ->label('החזר לרשימה')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->action(function (array $arguments): void {
                OpportunityNote::where('site_id', (int) $arguments['site'])
                    ->where('key', (string) $arguments['key'])
                    ->delete();

                Notification::make()->title('ההזדמנות חזרה לרשימה הפתוחה')->success()->send();
            });
    }

    /** Turn one finding into a task, so following it up is not a memory game. */
    public function openTaskAction(): Actions\Action
    {
        return Actions\Action::make('openTask')
            ->label('פתח משימה')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->action(function (array $arguments): void {
                $site = Site::with('customer:id,name')->find((int) $arguments['site']);

                if ($site === null) {
                    return;
                }

                $item = collect((array) data_get($site->opportunities, 'items', []))
                    ->firstWhere('key', (string) $arguments['key']);

                if ($item === null) {
                    Notification::make()->title('ההזדמנות כבר לא קיימת בסריקה האחרונה')->warning()->send();

                    return;
                }

                Task::create([
                    'title' => 'להציע ל'.($site->customer?->name ?? $site->domain).': '.$item['title'],
                    'description' => $site->domain."\n\n".$item['evidence'],
                    'customer_id' => $site->customer_id,
                    'status' => TaskStatus::Open,
                    'priority' => ($item['severity'] ?? '') === 'high'
                        ? TicketPriority::High
                        : TicketPriority::Normal,
                ]);

                Notification::make()->title('נפתחה משימה')
                    ->body('המשימה מופיעה במסך המשימות עם ההוכחה שמאחורי ההצעה.')
                    ->success()->send();
            });
    }

    /**
     * One row per site with at least one matching opportunity, most valuable
     * first. Each item carries the verdict on it, so the view can label it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRowsProperty(): Collection
    {
        $notes = OpportunityNote::map();
        $search = trim($this->search);

        return Site::query()
            ->with('customer:id,name')
            ->whereNotNull('opportunities')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('domain', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->get(['id', 'customer_id', 'domain', 'opportunities'])
            ->map(function (Site $site) use ($notes): array {
                $items = collect((array) data_get($site->opportunities, 'items', []))
                    ->filter(fn (array $item): bool => $this->matches($site->id, $item, $notes))
                    ->map(function (array $item) use ($site, $notes): array {
                        $note = $notes[$site->id][$item['key'] ?? ''] ?? null;

                        return $item + [
                            'reason' => $note?->reason,
                            'decided_by' => $note?->user?->name,
                        ];
                    })
                    ->values()->all();

                return [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'customer' => $site->customer?->name,
                    'scanned_at' => data_get($site->opportunities, 'scanned_at'),
                    'items' => $items,
                    // Recomputed from what is shown, not read from the stored
                    // total: a dismissed item must not inflate the pipeline.
                    'total_agorot' => collect($items)->sum(fn (array $i): int => (int) ($i['price_agorot'] ?? 0)),
                ];
            })
            ->filter(fn (array $row): bool => $row['items'] !== [])
            ->sortByDesc('total_agorot')
            ->values();
    }

    /**
     * Whether one opportunity belongs in the current view.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, OpportunityNote>>  $notes
     */
    private function matches(int $siteId, array $item, array $notes): bool
    {
        if ($this->urgentOnly && ($item['severity'] ?? '') !== 'high') {
            return false;
        }

        $status = ($notes[$siteId][$item['key'] ?? ''] ?? null)?->status;

        // "Open" is the absence of a verdict, which is why it is not a status.
        return $this->filter === 'open' ? $status === null : $status === $this->filter;
    }

    /** Total indicative value of what is currently on screen, in agorot. */
    public function getTotalAgorotProperty(): int
    {
        return (int) $this->rows->sum('total_agorot');
    }

    /**
     * How many opportunities sit under each verdict — so the counts stay visible
     * even while looking at one of them, and a dismissed pile cannot grow unseen.
     *
     * @return array<string, int>
     */
    public function getCountsProperty(): array
    {
        $notes = OpportunityNote::map();
        $counts = ['open' => 0, 'offered' => 0, 'dismissed' => 0];

        foreach (Site::query()->whereNotNull('opportunities')->get(['id', 'opportunities']) as $site) {
            foreach ((array) data_get($site->opportunities, 'items', []) as $item) {
                $status = ($notes[$site->id][$item['key'] ?? ''] ?? null)?->status ?? 'open';
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
