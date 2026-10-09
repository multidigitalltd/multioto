<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Services\Ai\ClaudeClient;
use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public string $suite = 'original';

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
            'selectedCount' => EvaluationCorpus::SUITES[$this->suite] ?? 400,
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

        $this->validate(['suite' => 'required|in:original,round2,all']);
        app(EvaluationRuns::class)->start((int) auth()->id(), $this->suite);

        Notification::make()
            ->title('הבדיקה נוספה לתור')
            ->body(EvaluationCorpus::SUITES[$this->suite].' התרחישים שנבחרו ירוצו ברקע. אפשר לצאת מהמסך ולחזור לצפות בתוצאות.')
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

    public function download(string $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validateRunId($id);

        // Compatibility with an already-open page. Direct HTTP streaming avoids
        // Livewire retaining the complete JSON and a second base64 copy in memory.
        $this->redirect(route('site-agent.evaluation.download', ['run' => $id]), navigate: false);
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
