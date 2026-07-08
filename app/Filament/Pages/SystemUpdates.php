<?php

namespace App\Filament\Pages;

use App\Services\System\DeployManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * מערכת ועדכונים — מציג את הגרסה הנוכחית ומאפשר לבקש עדכון בלחיצה. הבקשה
 * נכתבת כקובץ דגל; סוכן מורשה על השרת (docker/deploy-watcher.sh) מבצע את
 * העדכון בפועל. הממשק עצמו לעולם לא מריץ פקודות מערכת.
 */
class SystemUpdates extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'מערכת ועדכונים';

    protected static ?string $title = 'מערכת ועדכונים';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.system-updates';

    public ?array $version = null;

    public ?array $lastStatus = null;

    public bool $pending = false;

    public bool $configured = false;

    public function mount(DeployManager $deploy): void
    {
        $this->refreshState($deploy);
    }

    protected function refreshState(DeployManager $deploy): void
    {
        $this->version = $deploy->currentVersion();
        $this->lastStatus = $deploy->lastStatus();
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
