<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\ChargeResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\TicketResource;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * At-a-glance KPIs for the dashboard: customers, active subscriptions,
 * recurring revenue, open tickets and this month's collections.
 */
class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -5;

    protected function getStats(): array
    {
        $activeSubscriptions = Subscription::with('plan')
            ->where('status', SubscriptionStatus::Active)
            ->get();

        // Monthly recurring revenue, in shekels, from active subscriptions.
        $mrrAgorot = $activeSubscriptions->sum(fn (Subscription $s) => $s->totalChargeAgorot());

        $collectedThisMonth = (int) Charge::where('status', ChargeStatus::Succeeded)
            ->where('charged_at', '>=', Carbon::now()->startOfMonth())
            ->sum('total_agorot');

        $openTickets = Ticket::whereIn('status', [TicketStatus::Open, TicketStatus::Pending])->count();
        $pastDue = Subscription::where('status', SubscriptionStatus::PastDue)->count();

        // Every tile links to the screen showing what it counts.
        return [
            Stat::make('לקוחות', Customer::count())
                ->description('סה"כ לקוחות במערכת')
                ->icon('heroicon-o-users')
                ->color('primary')
                ->url(CustomerResource::getUrl()),

            Stat::make('מנויים פעילים', $activeSubscriptions->count())
                ->description($pastDue > 0 ? "{$pastDue} בפיגור תשלום" : 'הכל בתשלום שוטף')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color($pastDue > 0 ? 'warning' : 'success')
                ->url(SubscriptionResource::getUrl()),

            Stat::make('הכנסה חודשית', '₪ '.number_format($mrrAgorot / 100))
                ->description('MRR ממנויים פעילים')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(SubscriptionResource::getUrl()),

            Stat::make('נגבה החודש', '₪ '.number_format($collectedThisMonth / 100))
                ->description('חיובים שהצליחו החודש')
                ->icon('heroicon-o-credit-card')
                ->color('primary')
                ->url(ChargeResource::getUrl()),

            Stat::make('פניות פתוחות', $openTickets)
                ->description('פניות שממתינות לטיפול')
                ->icon('heroicon-o-lifebuoy')
                ->color($openTickets > 0 ? 'warning' : 'success')
                ->url(TicketResource::getUrl()),
        ];
    }
}
