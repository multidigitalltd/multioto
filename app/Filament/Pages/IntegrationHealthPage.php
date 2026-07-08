<?php

namespace App\Filament\Pages;

use App\Services\Health\IntegrationHealth;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * בדיקת חיבורים — כפתור לכל אינטגרציה שבודק בזמן אמת אם המפתחות עובדים ומציג
 * ✅ / ❌. הבדיקות הן פעולה יזומה של המנהל, ולכן מותר לבצע קריאת HTTP קצרה
 * ישירות בבקשה.
 */
class IntegrationHealthPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'בדיקת חיבורים';

    protected static ?string $title = 'בדיקת חיבורי אינטגרציות';

    protected static ?int $navigationSort = 85;

    protected static string $view = 'filament.pages.integration-health';

    /** @var array<string, array{state: string, message: string}> */
    public array $results = [];

    /** @return array<string, array{label: string, description: string}> */
    public function getIntegrationsProperty(): array
    {
        return app(IntegrationHealth::class)->integrations();
    }

    public function test(string $key): void
    {
        $result = app(IntegrationHealth::class)->check($key);

        $this->results[$key] = [
            'state' => $result->state(),
            'message' => $result->message,
        ];

        $notification = Notification::make()->title($result->message);
        $result->ok ? $notification->success() : ($result->configured ? $notification->danger() : $notification->warning());
        $notification->send();
    }

    public function testAll(): void
    {
        foreach (array_keys($this->integrations) as $key) {
            $this->test($key);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testAll')
                ->label('בדוק הכל')
                ->icon('heroicon-o-bolt')
                ->action('testAll'),
        ];
    }
}
