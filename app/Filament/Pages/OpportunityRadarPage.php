<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Jobs\ScanSiteOpportunitiesJob;
use App\Models\Site;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * ראדאר הזדמנויות — במקום לחפש מה למכור, המערכת מוצאת. כל אתר נסרק שבועית,
 * והממצאים שכבר קיימים (נגישות, מסמכי חובה, פגיעויות, מהירות, קישורים שבורים,
 * SEO, PHP ישן) מתורגמים לרשימת עבודה מתומחרת עם ההוכחה שמאחוריה.
 *
 * המחירים הם נקודת פתיחה להצעה ומוצגים לצוות בלבד. למנהלים — נתון עסקי.
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rescan')
                ->label('סרוק את כל האתרים עכשיו')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('סריקת הזדמנויות לכל האתרים')
                ->modalDescription('נסרוק כל אתר מחדש: נגישות, מסמכים, מהירות, קישורים שבורים ו-SEO. הסריקה רצה ברקע ועשויה להימשך כמה דקות.')
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
     * One row per site that has at least one opportunity, most valuable first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRowsProperty(): Collection
    {
        return Site::query()
            ->with('customer:id,name')
            ->whereNotNull('opportunities')
            ->get(['id', 'customer_id', 'domain', 'opportunities'])
            ->map(fn (Site $site): array => [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'customer' => $site->customer?->name,
                'scanned_at' => data_get($site->opportunities, 'scanned_at'),
                'items' => (array) data_get($site->opportunities, 'items', []),
                'total_agorot' => (int) data_get($site->opportunities, 'total_agorot', 0),
            ])
            ->filter(fn (array $row): bool => $row['items'] !== [])
            ->sortByDesc('total_agorot')
            ->values();
    }

    /** Total indicative value across every site, in agorot. */
    public function getTotalAgorotProperty(): int
    {
        return (int) $this->rows->sum('total_agorot');
    }
}
