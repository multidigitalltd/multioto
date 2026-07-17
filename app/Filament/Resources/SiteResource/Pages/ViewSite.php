<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Jobs\InvestigateSiteJob;
use App\Models\MonitorCheck;
use App\Services\Hosting\SiteDiagnostics;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Per-site monitoring history: current up/down state, uptime % and average
 * response time over the last week, TLS certificate days-left, and the most
 * recent probes. Read-only — all remediation goes through the approval gate.
 */
class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.sites.monitor';

    /**
     * The site page doubles as the site's action hub — clicking a card lands
     * here with the day-to-day operations right in the header, so "all the info
     * and options" live in one place (page actions use Filament\Actions, so
     * they are declared here rather than reused from the table's actions).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('diagnose')
                ->label('אבחון')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->action(function (SiteDiagnostics $diagnostics): void {
                    try {
                        $result = $diagnostics->run($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('האבחון נכשל')->body(Str::limit($e->getMessage(), 150))->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('אבחון '.$this->record->domain.($result['healthy'] ? ' — תקין ✓' : ' — נמצאו בעיות'))
                        ->body($result['summary'])
                        ->{$result['healthy'] ? 'success' : 'warning'}()
                        ->persistent()
                        ->send();
                }),

            Actions\Action::make('aiInvestigate')
                ->label('אבחון AI')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->visible(fn (): bool => $this->record->mcp_enabled && (auth()->user()?->isAdmin() ?? false))
                ->form([
                    Textarea::make('goal')
                        ->label('מה לבדוק / לתקן?')
                        ->rows(2)
                        ->default('אבחן את האתר וזהה תקלות. אם נדרש תיקון — הצע פעולה אחת לאישור.'),
                ])
                ->action(function (array $data): void {
                    InvestigateSiteJob::dispatch($this->record->id, (string) ($data['goal'] ?? 'אבחן את האתר.'));
                    Notification::make()->title('האבחון רץ ברקע')
                        ->body('הסיכום יופיע בזיכרון האתר, והצעות תיקון (אם יהיו) ב"אישורי אוטומציה".')
                        ->success()->send();
                }),

            Actions\ActionGroup::make([
                Actions\Action::make('connectionCodes')
                    ->label('קודי חיבור לתוסף')
                    ->icon('heroicon-o-clipboard-document')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->modalHeading(fn (): string => 'קודי חיבור — '.$this->record->domain)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגור')
                    ->modalContent(fn () => view('filament.agent-credentials', [
                        'data' => $this->record->ensureAgentCredentials(),
                    ])),
                Actions\Action::make('downloadPlugin')
                    ->label('הורד תוסף (גרסה אחרונה)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->url(fn (): string => route('agent.plugin.latest'))
                    ->openUrlInNewTab(),
                Actions\EditAction::make()->label('עריכה'),
            ])
                ->label('עוד')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    /** Window (days) the uptime/response statistics are computed over. */
    protected const STATS_WINDOW_DAYS = 7;

    /** Recent probes shown in the history table. */
    protected const RECENT_LIMIT = 30;

    /**
     * Aggregate uptime %, average response time and probe count over the stats
     * window, computed in the database (no row hydration).
     *
     * @return array{total: int, up: int, uptime: ?float, avg_ms: ?int}
     */
    public function getStatsProperty(): array
    {
        $since = Carbon::now()->subDays(self::STATS_WINDOW_DAYS);

        $checks = $this->record->monitorChecks()
            ->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_up then 1 else 0 end) as up')
            ->selectRaw('avg(case when is_up then response_ms end) as avg_ms')
            ->first();

        $total = (int) ($checks->total ?? 0);
        $up = (int) ($checks->up ?? 0);

        return [
            'total' => $total,
            'up' => $up,
            'uptime' => $total > 0 ? round($up / $total * 100, 2) : null,
            'avg_ms' => $checks->avg_ms !== null ? (int) round($checks->avg_ms) : null,
        ];
    }

    /**
     * Most recent probes, newest first.
     *
     * @return Collection<int, MonitorCheck>
     */
    public function getRecentChecksProperty(): Collection
    {
        return $this->record->monitorChecks()
            ->latest('checked_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Response-time trend for the sparkline — the recent probes in
     * chronological order with a bar height (percent of the window's max).
     *
     * @return array{max: int, points: array<int, array{ms: int, up: bool, pct: int, at: Carbon}>}
     */
    public function getTrendProperty(): array
    {
        $checks = $this->record->monitorChecks()
            ->latest('checked_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['checked_at', 'response_ms', 'is_up'])
            ->reverse()
            ->values();

        $max = max(1, (int) $checks->max('response_ms'));

        return [
            'max' => $max,
            'points' => $checks->map(fn (MonitorCheck $c): array => [
                'ms' => (int) $c->response_ms,
                'up' => (bool) $c->is_up,
                'pct' => (int) round($c->response_ms / $max * 100),
                'at' => $c->checked_at,
            ])->all(),
        ];
    }

    public function getStatsWindowDays(): int
    {
        return self::STATS_WINDOW_DAYS;
    }
}
