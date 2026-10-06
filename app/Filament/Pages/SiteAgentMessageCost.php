<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Jobs\SyncSiteAgentMessagingCostJob;
use App\Services\SiteAgent\MessagingCostReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * עלות ההודעות מול מה שחויב — האם המוצר מרוויח על ההודעות או מפסיד.
 *
 * The plan screen has a "price per message" field, and until now nothing said
 * what a message costs. That was survivable while Meta did not charge for the
 * bot's replies. Since 1 October 2026 it does — service messages went from free
 * to the utility rate — and a margin that turned negative that day would show
 * up nowhere at all.
 *
 * The cost side is Meta's own figure, pulled daily by
 * SyncSiteAgentMessagingCostJob and read here from cache: a page that calls a
 * third party is a page that is down when the third party is.
 *
 * Admin-only. It is the product's gross margin.
 */
class SiteAgentMessageCost extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'עלות הודעות הבוט';

    protected static ?string $title = 'בוט ניהול האתר — עלות ההודעות מול מה שחויב';

    // 25 ולא 24: לוח השימוש בבוט יושב על 24 באותה קבוצה, ושני פריטים באותו
    // מספר מסתדרים ביניהם באופן שרירותי.
    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.site-agent-message-cost';

    /** Trailing window the comparison covers. Bound to the page's select. */
    public int $windowDays = 30;

    /**
     * The windows the operator may pick (whitelist — never free input).
     *
     * The keys are MessagingCostReport::WINDOWS: cost is cached per window, and
     * offering one the pull never fetched would show an empty figure under a
     * period the screen named itself.
     */
    public const WINDOWS = [7 => '7 ימים', 30 => '30 ימים', 90 => '90 ימים'];

    public function updatedWindowDays(mixed $value): void
    {
        $this->windowDays = array_key_exists((int) $value, self::WINDOWS) ? (int) $value : 30;
    }

    /** @return array<string, mixed> */
    public function getSummaryProperty(): array
    {
        return app(MessagingCostReport::class)->summary($this->windowDays);
    }

    /** What one message costs us on average — the figure the plan price must beat. */
    public function getCostPerMessageProperty(): ?int
    {
        return app(MessagingCostReport::class)->costPerMessage($this->windowDays);
    }

    /**
     * Pull Meta's figures now rather than waiting for tonight.
     *
     * Queued, not run inline: the same job the scheduler runs, so there is one
     * code path and the button cannot hold the request open while Meta is slow.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('רענון מול מטא')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    SyncSiteAgentMessagingCostJob::dispatch($this->windowDays);

                    Notification::make()
                        ->title('הבקשה נשלחה')
                        ->body('הנתונים יתעדכנו ברקע תוך דקה. רעננו את העמוד אחר כך.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
