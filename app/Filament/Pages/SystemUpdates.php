<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\SystemLog;
use App\Services\System\DeployManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * מערכת ועדכונים — גרסה נוכחית + עדכון בלחיצה, ו*יומן אירועים* של המערכת (בעיקר
 * כשלי סוכן ה-AI) כדי לאבחן תקלות מהפאנל בלי גישה ל-storage/logs. הרשומות
 * נשמרות לתקופה (billing.system.log_retention_days) ונמחקות אוטומטית.
 */
class SystemUpdates extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'מערכת ועדכונים';

    protected static ?string $title = 'מערכת ועדכונים';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.system-updates';

    private const LEVELS = ['info' => 'מידע', 'warning' => 'אזהרה', 'error' => 'שגיאה'];

    private const SOURCES = ['ai' => 'סוכן AI', 'billing' => 'חיוב', 'monitoring' => 'ניטור', 'system' => 'מערכת'];

    public ?array $version = null;

    public ?array $lastStatus = null;

    public bool $pending = false;

    public bool $configured = false;

    /** Red badge with the number of errors logged in the last 24 hours. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = SystemLog::query()->where('level', 'error')->where('created_at', '>=', now()->subDay())->count();
        } catch (\Throwable) {
            return null; // never let the log table break the nav
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

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

    /** The system event log, shown under the version/update section. */
    public function table(Table $table): Table
    {
        return $table
            ->query(SystemLog::query())
            ->defaultSort('created_at', 'desc')
            ->heading('יומן אירועים')
            ->description('אירועים תפעוליים אחרונים — בעיקר כשלי סוכן AI. נשמר לתקופה מוגבלת ונמחק אוטומטית.')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('מתי')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('level')->label('רמה')->badge()
                    ->formatStateUsing(fn (string $state): string => self::LEVELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')->label('מקור')->badge()
                    ->formatStateUsing(fn (string $state): string => self::SOURCES[$state] ?? $state),
                Tables\Columns\TextColumn::make('message')->label('הודעה')->wrap()->limit(160),
                Tables\Columns\TextColumn::make('context')->label('פרטים')
                    ->formatStateUsing(fn ($state): string => filled($state) ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—')
                    ->wrap()->limit(200)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('רמה')->options(self::LEVELS),
                Tables\Filters\SelectFilter::make('source')->label('מקור')->options(self::SOURCES),
            ])
            ->emptyStateHeading('אין רשומות ביומן')
            ->emptyStateDescription('אירועים תפעוליים (כמו כשלי סוכן AI) יופיעו כאן.');
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
