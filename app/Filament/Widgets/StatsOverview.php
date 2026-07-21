<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\ChargeResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TicketResource;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Task;
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
    protected static ?int $sort = -60;

    protected function getStats(): array
    {
        $activeSubscriptions = Subscription::with('plan')
            ->where('status', SubscriptionStatus::Active)
            ->get();

        // Monthly recurring revenue, in shekels, from active subscriptions.
        $mrrAgorot = $activeSubscriptions->sum(fn (Subscription $s) => $s->totalChargeAgorot());

        // One-off income still expected: open payment demands (חשבוניות עסקה
        // ידניות) that were sent and haven't been paid or canceled. These are not
        // recurring, so they're shown separately from the subscription MRR.
        $openDemandsAgorot = (int) Charge::where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->sum('total_agorot');

        // The expected total the dashboard headlines: recurring subscriptions
        // plus outstanding one-off demands.
        $expectedAgorot = $mrrAgorot + $openDemandsAgorot;

        $collectedThisMonth = (int) Charge::where('status', ChargeStatus::Succeeded)
            ->where('charged_at', '>=', Carbon::now()->startOfMonth())
            ->sum('total_agorot');

        // Only tickets awaiting OUR action count as "needs handling" — a ticket
        // in "ממתין ללקוח" (Pending) is waiting on the customer, so it is not a
        // task on the team's plate even though it is not yet closed.
        $openTickets = Ticket::where('status', TicketStatus::Open)->count();
        $pastDue = Subscription::where('status', SubscriptionStatus::PastDue)->count();
        $openTasks = Task::whereIn('status', [TaskStatus::Open, TaskStatus::InProgress])->count();

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

            Stat::make('הכנסה חודשית צפויה', '₪ '.number_format($expectedAgorot / 100))
                ->description('מנויים ₪'.number_format($mrrAgorot / 100).' · חד-פעמי ₪'.number_format($openDemandsAgorot / 100))
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

            Stat::make('משימות פתוחות', $openTasks)
                ->description('משימות צוות שממתינות לביצוע')
                ->icon('heroicon-o-clipboard-document-check')
                ->color($openTasks > 0 ? 'warning' : 'success')
                ->url(TaskResource::getUrl()),
        ];
    }
}
