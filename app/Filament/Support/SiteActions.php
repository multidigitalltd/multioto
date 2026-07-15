<?php

namespace App\Filament\Support;

use App\Jobs\InvestigateSiteJob;
use App\Models\Site;
use App\Services\Agent\SiteConnector;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Reusable table actions for a Site, so the same buttons work identically on the
 * Sites resource AND on a customer's card (SitesRelationManager) — one place to
 * define them, no drift. The one-time connection-setup actions are meant to be
 * tucked into an ActionGroup by the caller; the AI actions are the day-to-day ones.
 */
class SiteActions
{
    private static function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /** Ask the AI to investigate the site read-only and propose a gated fix. */
    public static function aiInvestigate(): Action
    {
        return Action::make('aiInvestigate')
            ->label('אבחון AI')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (Site $record): bool => $record->mcp_enabled
                && app(ClaudeClient::class)->isEnabled()
                && self::isAdmin())
            ->form([
                Forms\Components\Textarea::make('goal')
                    ->label('מה לבדוק / לתקן?')
                    ->rows(2)
                    ->default('אבחן את האתר וזהה תקלות. אם נדרש תיקון — הצע פעולה אחת לאישור.'),
            ])
            ->action(function (array $data, Site $record): void {
                InvestigateSiteJob::dispatch($record->id, (string) ($data['goal'] ?? 'אבחן את האתר.'));

                Notification::make()->title('האבחון רץ ברקע')
                    ->body('הסיכום יופיע בזיכרון האתר, והצעות תיקון (אם יהיו) ב"אישורי אוטומציה" לאישורך.')
                    ->success()->send();
            });
    }

    /** Propose an MCP tool call — always through the approval gate. */
    public static function proposeMcpAction(): Action
    {
        return Action::make('proposeMcpAction')
            ->label('פעולת AI')
            ->icon('heroicon-o-cpu-chip')
            ->color('warning')
            ->visible(fn (Site $record): bool => $record->mcp_enabled
                && filled(data_get($record->mcp_capabilities, 'tools'))
                && self::isAdmin())
            ->form(fn (Site $record): array => [
                Forms\Components\Select::make('tool')
                    ->label('כלי')
                    ->options(collect((array) data_get($record->mcp_capabilities, 'tools', []))
                        ->mapWithKeys(function (array $tool) use ($record): array {
                            $name = (string) ($tool['name'] ?? '');

                            return [$name => "{$name} (".app(SiteToolCatalog::class)->resolveTierLabel($record, $name).')'];
                        })->all())
                    ->required()
                    ->searchable(),
                Forms\Components\Textarea::make('arguments')
                    ->label('פרמטרים (JSON)')
                    ->rows(3)
                    ->placeholder('{"plugin": "elementor"}')
                    ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                        if (filled($value) && ! is_array(json_decode((string) $value, true))) {
                            $fail('הפרמטרים חייבים להיות JSON תקין.');
                        }
                    }),
            ])
            ->action(function (array $data, Site $record, ApprovalGate $gate): void {
                $catalog = app(SiteToolCatalog::class);
                $tool = (string) $data['tool'];

                if (! $catalog->allowedOn($record, $tool)) {
                    Notification::make()->title('הכלי מסווג כהרסני ומותר רק באתר סטייג׳ינג')->danger()->send();

                    return;
                }

                $arguments = filled($data['arguments'] ?? null) ? (array) json_decode((string) $data['arguments'], true) : [];
                $argsText = $arguments === [] ? 'ללא פרמטרים' : json_encode($arguments, JSON_UNESCAPED_UNICODE);

                $gate->propose(
                    type: 'site_action',
                    summary: "🤖 פעולת AI באתר {$record->domain}\nכלי: {$tool} ({$catalog->resolveTierLabel($record, $tool)})\nפרמטרים: {$argsText}",
                    payload: ['site_id' => $record->id, 'tool' => $tool, 'arguments' => $arguments],
                    customerId: $record->customer_id,
                    proposedBy: 'team',
                );

                Notification::make()->title('הפעולה נשלחה לאישור')
                    ->body('תופיע ב"אישורי אוטומציה" ותישלח לוואטסאפ לאישור לפני ביצוע.')
                    ->success()->send();
            });
    }

    /** Live MCP handshake: verify the connection and refresh the tool list. */
    public static function testMcp(): Action
    {
        return Action::make('testMcp')
            ->label('בדוק חיבור AI')
            ->icon('heroicon-o-signal')
            ->color('info')
            ->visible(fn (Site $record): bool => ($record->mcp_enabled || filled($record->mcp_endpoint)) && self::isAdmin())
            ->action(function (Site $record, SiteConnector $connector): void {
                $result = $connector->testConnection($record);

                Notification::make()
                    ->title('חיבור סוכן AI — '.$record->domain)
                    ->body($result->message)
                    ->{$result->ok ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /** The ready-made connection codes to copy into the site's plugin. */
    public static function connectionCodes(): Action
    {
        return Action::make('connectionCodes')
            ->label('קודי חיבור לתוסף')
            ->icon('heroicon-o-clipboard-document')
            ->color('primary')
            ->visible(fn (): bool => self::isAdmin())
            ->modalHeading(fn (Site $record): string => 'קודי חיבור — '.$record->domain)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('סגור')
            ->modalContent(fn (Site $record) => view('filament.agent-credentials', [
                'data' => $record->ensureAgentCredentials(),
            ]));
    }

    /** Download the current plugin build to install on a site. */
    public static function downloadPlugin(): Action
    {
        return Action::make('downloadPlugin')
            ->label('הורד תוסף (גרסה אחרונה)')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => self::isAdmin())
            ->url(fn (): string => route('agent.plugin.latest'))
            ->openUrlInNewTab();
    }

    /** Rotate the site's connection token — revokes the previous one. */
    public static function generateAgentToken(): Action
    {
        return Action::make('generateAgentToken')
            ->label('טוקן חדש')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (): bool => self::isAdmin())
            ->requiresConfirmation()
            ->modalHeading('החלפת טוקן חיבור לאתר')
            ->modalDescription('ייווצר טוקן חדש עבור התוסף באתר. הטוקן הקודם יבוטל — יש לעדכן את התוסף בטוקן החדש.')
            ->modalSubmitActionLabel('צור טוקן חדש')
            ->action(function (Site $record): void {
                $token = $record->generateAgentToken();

                Notification::make()
                    ->title('נוצר טוקן חדש — עדכנו אותו בתוסף')
                    ->body('הטוקן הקודם בוטל. הטוקן החדש (זמין גם ב"קודי חיבור לתוסף"):'."\n\n".$token)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
