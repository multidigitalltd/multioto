<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteAgentEvaluation extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'בדיקות הבוט';

    protected static ?string $title = 'בדיקות בוט ניהול האתר';

    protected static ?int $navigationSort = 86;

    protected static string $view = 'filament.pages.site-agent-evaluation';

    protected function getViewData(): array
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $run = app(EvaluationRuns::class)->latest();

        return [
            'run' => $run,
            'active' => in_array($run['status'] ?? null, ['queued', 'running', 'cancel_requested'], true),
            'configured' => $this->aiConfigured(),
            'provider' => (string) config('billing.ai.provider'),
            'model' => (string) config('billing.ai.model'),
        ];
    }

    public function start(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (! $this->aiConfigured()) {
            throw ValidationException::withMessages([
                'evaluation' => 'לפני הפעלת הבדיקה יש להפעיל את סוכן ה־AI ולהגדיר ספק, מודל ומפתח API במסך הגדרות ה־AI.',
            ]);
        }

        app(EvaluationRuns::class)->start((int) auth()->id());

        Notification::make()
            ->title('הבדיקה נוספה לתור')
            ->body('400 התרחישים ירוצו ברקע. אפשר לצאת מהמסך ולחזור לצפות בתוצאות.')
            ->success()
            ->send();
    }

    public function cancel(string $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validateRunId($id);

        app(EvaluationRuns::class)->cancel($id);

        Notification::make()
            ->title('בקשת העצירה נרשמה')
            ->body('תרחיש שכבר התחיל יסתיים לפני העצירה. התוצאות שכבר נאספו יישמרו.')
            ->success()
            ->send();
    }

    public function download(string $id): StreamedResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validateRunId($id);

        // Read through the authorized service, never from a client-supplied path.
        $report = app(EvaluationRuns::class)->report($id);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            "site-agent-evaluation-{$id}.json",
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    private function aiConfigured(): bool
    {
        return app(ClaudeClient::class)->isEnabled()
            && filled(config('billing.ai.provider'))
            && filled(config('billing.ai.model'));
    }

    private function validateRunId(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages(['evaluation' => 'מזהה הבדיקה אינו תקין.']);
        }
    }
}
