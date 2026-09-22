<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ActivateSiteAgent;
use App\Filament\Pages\ManageSiteAgent;
use App\Filament\Resources\SiteAgentRequestResource;
use App\Filament\Resources\SiteAgentSubscriberResource;
use App\Models\SiteAgentSubscriber;
use App\Services\SiteAgent\SiteAgentProduct;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * סוכן הוואטסאפ לאתר, על לוח הבקרה.
 *
 * The product had screens — a subscriber list, a journal, an activation form —
 * and no presence. Nothing on the dashboard said it existed, which for a thing
 * we SELL is the difference between a product and a feature somebody once
 * built: nobody sees it stall, nobody sees it grow, and nobody is reminded to
 * sell it.
 *
 * Each tile is a number next to the thing that makes it mean something, and
 * every one of them links to the screen where it can be acted on. The tile that
 * earns its place is "מושהים": numbers that work, belonging to customers who
 * have stopped paying. It is the only one that is quiet in both directions —
 * the customer is not being charged, and the customer is not being served.
 */
class SiteAgentOverview extends BaseWidget
{
    /**
     * Same module as the product's own screens (navigation group ניהול), so a
     * team member who cannot open the subscriber list is not shown its totals
     * on the dashboard instead.
     */
    public static function canView(): bool
    {
        if (! (auth()->user()?->canAccessModule('management') ?? false)) {
            return false;
        }

        // An install that does not sell this gets no tiles for it. A row of
        // zeros every morning is a row nobody reads, and it would push the
        // widgets that DO describe that business further down the page.
        return app(SiteAgentProduct::class)->enabled()
            || SiteAgentSubscriber::query()->exists();
    }

    protected static ?int $sort = -40;

    protected ?string $heading = 'סוכן וואטסאפ לניהול אתר';

    protected function getStats(): array
    {
        $product = app(SiteAgentProduct::class);

        $numbers = $product->numbers();
        $money = $product->money();
        $activity = $product->activity();
        $missing = $product->missing();

        $stats = [];

        // First, and only when there is something to say: the product cannot do
        // its job. Placed ahead of the numbers deliberately — subscriber counts
        // look perfectly healthy while verification codes are being refused by
        // Meta, so the reassuring number must not be the first thing read.
        if ($missing !== []) {
            $stats[] = Stat::make('חסר להפעלה', count($missing))
                ->description($missing[0]['label'].' — '.$missing[0]['detail'])
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(ManageSiteAgent::getUrl());
        }

        $stats[] = Stat::make('מספרים פעילים', $numbers['active'])
            ->description($numbers['pending'] > 0
                ? $numbers['pending'].' ממתינים לאימות'
                : 'מספרים מאומתים עם מנוי בתוקף')
            ->icon('heroicon-o-device-phone-mobile')
            ->color($numbers['active'] > 0 ? 'success' : 'gray')
            ->url(SiteAgentSubscriberResource::getUrl());

        $stats[] = Stat::make('מושהים — לא משלמים', $numbers['paused'])
            ->description($numbers['paused'] > 0
                ? 'הסוכן שותק אצלם עד שהמנוי יחודש'
                : 'כל מספר פעיל מכוסה במנוי')
            ->icon('heroicon-o-pause-circle')
            ->color($numbers['paused'] > 0 ? 'warning' : 'success')
            ->url(SiteAgentSubscriberResource::getUrl());

        // The money tile is shown only to whoever may see money elsewhere in
        // the panel: the rest of the dashboard's revenue lives behind the
        // finance module, and this is the same revenue by another name.
        if (auth()->user()?->canAccessModule('finance') ?? false) {
            $stats[] = Stat::make('הכנסה חודשית מהמוצר', '₪ '.number_format($money['monthly_agorot'] / 100))
                ->description($this->revenueNote($money))
                ->icon('heroicon-o-banknotes')
                ->color($money['past_due'] > 0 || $money['unbilled'] > 0 ? 'warning' : 'success')
                ->url(ActivateSiteAgent::getUrl());
        }

        $stats[] = Stat::make('שינויים שבוצעו השבוע', $activity['applied'])
            ->description($this->activityNote($activity))
            ->icon('heroicon-o-pencil-square')
            ->color($activity['failed'] > 0 ? 'warning' : 'primary')
            ->url(SiteAgentRequestResource::getUrl());

        return $stats;
    }

    /**
     * What sits behind the monthly figure.
     *
     * Both exceptions are money that is NOT arriving while the product is being
     * used, so each is named rather than folded into the total.
     *
     * @param  array{subscribed: int, monthly_agorot: int, past_due: int, unbilled: int}  $money
     */
    private function revenueNote(array $money): string
    {
        $notes = [$money['subscribed'].' מנויים בתוקף'];

        if ($money['past_due'] > 0) {
            $notes[] = $money['past_due'].' בפיגור';
        }

        if ($money['unbilled'] > 0) {
            $notes[] = $money['unbilled'].' בניסיון ללא כרטיס';
        }

        return implode(' · ', $notes);
    }

    /** @param  array{awaiting: int, applied: int, failed: int}  $activity */
    private function activityNote(array $activity): string
    {
        $notes = [];

        if ($activity['awaiting'] > 0) {
            $notes[] = $activity['awaiting'].' ממתינים לאישור הלקוח';
        }

        if ($activity['failed'] > 0) {
            $notes[] = $activity['failed'].' נכשלו';
        }

        return $notes === [] ? 'בקשות שלקוחות ביצעו בעצמם' : implode(' · ', $notes);
    }
}
