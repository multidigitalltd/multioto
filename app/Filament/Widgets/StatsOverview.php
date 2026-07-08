<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
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
    protected static ?int $sort = -3;

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

        return [
            Stat::make('לקוחות', Customer::count())
                ->description('סה"כ לקוחות במערכת')
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('מנויים פעילים', $activeSubscriptions->count())
                ->description($pastDue > 0 ? "{$pastDue} בפיגור תשלום" : 'הכל בתשלום שוטף')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color($pastDue > 0 ? 'warning' : 'success'),

            Stat::make('הכנסה חודשית', '₪ '.number_format($mrrAgorot / 100))
                ->description('MRR ממנויים פעילים')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('נגבה החודש', '₪ '.number_format($collectedThisMonth / 100))
                ->description('חיובים שהצליחו החודש')
                ->icon('heroicon-o-credit-card')
                ->color('primary'),

            Stat::make('פניות פתוחות', $openTickets)
                ->description('פניות שממתינות לטיפול')
                ->icon('heroicon-o-lifebuoy')
                ->color($openTickets > 0 ? 'warning' : 'success'),
        ];
    }
}
