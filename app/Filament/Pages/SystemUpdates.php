<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Services\System\DeployManager;
use App\Support\Changelog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * מערכת ועדכונים — גרסה נוכחית + עדכון בלחיצה, ולצדו "מה חדש" (יומן הגרסאות
 * המותקנות). יומן האירועים התפעולי עבר לעמוד ייעודי "יומן אירועים" בקבוצת ניהול.
 */
class SystemUpdates extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'מערכת ועדכונים';

    protected static ?string $title = 'מערכת ועדכונים';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.system-updates';

    public ?array $version = null;

    public ?array $lastStatus = null;

    public ?array $available = null;

    public bool $pending = false;

    public bool $configured = false;

    /** The "מה חדש" release feed. */
    public function getReleasesProperty(): Collection
    {
        return Changelog::releases();
    }

    public function mount(DeployManager $deploy): void
    {
        $this->refreshState($deploy);
    }

    protected function refreshState(DeployManager $deploy): void
    {
        $this->version = $deploy->currentVersion();
        $this->lastStatus = $deploy->lastStatus();
        $this->available = $deploy->availableUpdate();
        $this->pending = $deploy->isPending();
        $this->configured = $deploy->isConfigured();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAgain')
                ->label('רענון סטטוס')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn (DeployManager $deploy) => $this->refreshState($deploy)),

            Action::make('update')
                ->label('עדכן עכשיו')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (DeployManager $deploy) => $deploy->isConfigured())
                ->disabled(fn (DeployManager $deploy) => $deploy->isPending())
                ->requiresConfirmation()
                ->modalHeading('עדכון המערכת')
                ->modalDescription('המערכת תמשוך את הגרסה האחרונה ותחיל מיגרציות חדשות. הנתונים והקבצים נשמרים. התהליך מתבצע ברקע ועשוי להימשך דקה-שתיים.')
                ->modalSubmitActionLabel('עדכן עכשיו')
                ->action(function (DeployManager $deploy): void {
                    if ($deploy->requestUpdate(Auth::user()?->email)) {
                        Notification::make()
                            ->title('העדכון התבקש')
                            ->body('העדכון יבוצע אוטומטית תוך כדקה. אפשר לרענן את הסטטוס בהמשך.')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('לא ניתן לבקש עדכון כרגע')
                            ->body('ייתכן שכבר יש עדכון בתהליך, או שסוכן העדכון אינו מוגדר בשרת.')
                            ->warning()
                            ->send();
                    }

                    $this->refreshState($deploy);
                }),
        ];
    }
}
