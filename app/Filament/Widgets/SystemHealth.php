<?php

namespace App\Filament\Widgets;

use App\Services\System\HealthReport;
use Filament\Widgets\Widget;

/**
 * Dashboard: what has stopped working, when something has.
 *
 * The external monitor is the real alarm — it keeps working when this panel
 * does not. This is the other end of the same question: a person who opens the
 * panel should not have to know that a queue exists in order to find out that
 * nothing has been charged since Tuesday.
 *
 * Hidden entirely while everything is fine, like the other "needs attention"
 * widgets — a green box every day is a box nobody reads.
 */
class SystemHealth extends Widget
{
    protected static string $view = 'filament.widgets.system-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -90;

    /** @var array<string, list<array{key: string, label: string, status: string, detail: string}>> */
    private static array $memo = [];

    public static function canView(): bool
    {
        if (! (auth()->user()?->canAccessModule('management') ?? false)) {
            return false;
        }

        return self::problems() !== [];
    }

    /** @return list<array{key: string, label: string, status: string, detail: string}> */
    public function failures(): array
    {
        return self::problems();
    }

    public function anythingStopped(): bool
    {
        return collect(self::problems())->contains(
            fn (array $check): bool => $check['status'] === HealthReport::DOWN,
        );
    }

    /**
     * Collected once per request: canView() and the view itself both ask, and
     * the answer cannot change between them.
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    private static function problems(): array
    {
        return self::$memo['problems'] ??= app(HealthReport::class)->problems();
    }
}
