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

    /** When the host agent last looked for a newer version, and how it went. */
    public ?array $lastCheck = null;

    public bool $checkStale = false;

    public ?string $checkError = null;

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
        $this->lastCheck = $deploy->lastCheck();
        $this->checkStale = $deploy->checkIsStale();
        $this->checkError = $deploy->lastCheckError();
    }

    /**
     * Tell the operator what the refresh actually found.
     *
     * Ordered by what the person needs to do about it, not by severity: a check
     * that never ran is a one-line install on the server, and until somebody
     * does it no amount of pressing this button will ever change anything.
     */
    protected function announceState(DeployManager $deploy): void
    {
        if ($this->lastCheck === null) {
            Notification::make()
                ->title('סוכן העדכון בשרת מעולם לא רץ')
                ->body('ולכן אין מה לרענן — אף אחד לא בדק אם יש גרסה חדשה. '
                    .'מתקינים אותו פעם אחת בשרת: bash docker/install-deploy-watcher.sh')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        if ($this->checkError !== null) {
            Notification::make()
                ->title('בדיקת העדכונים נכשלת')
                ->body('היעדר הודעה על גרסה חדשה אינו אומר שאתם מעודכנים. הפירוט במסך.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if ($this->checkStale) {
            Notification::make()
                ->title('הסוכן הפסיק לבדוק')
                ->body('הבדיקה האחרונה: '.($this->lastCheck['at'] ?? 'לא ידוע').'. הוא אמור לבדוק כל דקה.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        if ($this->available !== null) {
            Notification::make()
                ->title('יש גרסה חדשה')
                ->body(($this->available['behind'] ?? '?').' שינויים ממתינים. אפשר ללחוץ "עדכן עכשיו".')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('אתם מעודכנים')
            ->body('נבדק: '.($this->lastCheck['at'] ?? 'לא ידוע'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAgain')
                ->label('רענון סטטוס')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                // Says what it found, every time.
                //
                // This button re-reads what the host agent wrote; it cannot go
                // and look for an update itself (the web process never runs a
                // shell command). So when the agent has never run, every file it
                // reads is absent, nothing on the page changes, and the button
                // looks broken — which is precisely how it was reported. An
                // answer, even "there was nothing to read and here is why", is
                // the difference between a dead button and a diagnosis.
                ->action(function (DeployManager $deploy): void {
                    $this->refreshState($deploy);

                    $this->announceState($deploy);
                }),

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
