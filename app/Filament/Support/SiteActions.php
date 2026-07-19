<?php

namespace App\Filament\Support;

use App\Jobs\InvestigateSiteJob;
use App\Models\Site;
use App\Services\Agent\SiteConnector;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\System\OutboundIp;
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
                && app(ClaudeClient::class)->supportsAgent()
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
                    ->searchable()
                    // Live so the parameter fields below rebuild for the chosen tool.
                    ->live(),

                // Real, labelled fields per tool — no JSON to write. Rebuilds
                // whenever the selected tool changes.
                Forms\Components\Group::make()
                    ->schema(fn (Forms\Get $get): array => self::toolParamFields($record, (string) $get('tool')))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Site $record, ApprovalGate $gate): void {
                $catalog = app(SiteToolCatalog::class);
                $tool = (string) ($data['tool'] ?? '');

                if (! $catalog->allowedOn($record, $tool)) {
                    Notification::make()->title('הכלי מסווג כהרסני ומותר רק באתר סטייג׳ינג')->danger()->send();

                    return;
                }

                $arguments = self::collectToolArguments($record, $tool, $data);
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

    /**
     * The cached parameter spec for a tool (name/type/description/enum/required),
     * as discovered on the last connection test.
     *
     * @return list<array<string, mixed>>
     */
    public static function toolParamSpec(Site $site, string $tool): array
    {
        foreach ((array) data_get($site->mcp_capabilities, 'tools', []) as $definition) {
            if (($definition['name'] ?? null) === $tool) {
                return (array) ($definition['params'] ?? []);
            }
        }

        return [];
    }

    /**
     * Build the form fields for a tool's parameters — a labelled input per
     * parameter (text / number / toggle / dropdown), so an operator never types
     * JSON. When the tool has no known parameter spec (e.g. an older site not
     * re-tested since this shipped), fall back to a friendly key/value grid.
     *
     * @return list<Forms\Components\Component>
     */
    private static function toolParamFields(Site $site, string $tool): array
    {
        if ($tool === '') {
            return [];
        }

        $spec = self::toolParamSpec($site, $tool);

        if ($spec === []) {
            return [
                Forms\Components\KeyValue::make('kv_params')
                    ->label('פרמטרים')
                    ->keyLabel('שם')
                    ->valueLabel('ערך')
                    ->addActionLabel('הוסף פרמטר')
                    ->helperText('לכלי זה אין רשימת פרמטרים ידועה. אם צריך — הוסיפו שם וערך; אם לא — השאירו ריק. (טיפ: "בדקו חיבור AI" כדי לרענן את רשימת הכלים.)'),
            ];
        }

        return array_map(static function (array $param): Forms\Components\Component {
            $name = (string) $param['name'];
            $type = (string) ($param['type'] ?? 'string');
            $enum = (array) ($param['enum'] ?? []);

            $field = match (true) {
                $type === 'boolean' => Forms\Components\Toggle::make($name)->inline(false),
                in_array($type, ['integer', 'number'], true) => Forms\Components\TextInput::make($name)->numeric(),
                $enum !== [] => Forms\Components\Select::make($name)
                    ->options(array_combine($enum, $enum))
                    ->native(false),
                default => Forms\Components\TextInput::make($name),
            };

            $field->label($name)->required((bool) ($param['required'] ?? false));

            if (filled($param['description'] ?? null)) {
                $field->helperText((string) $param['description']);
            }

            return $field;
        }, $spec);
    }

    /**
     * Assemble the tool arguments from the submitted form: typed values from the
     * per-parameter fields (casting numbers/booleans to match the schema), or
     * the key/value fallback when the tool had no known spec.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function collectToolArguments(Site $site, string $tool, array $data): array
    {
        $spec = self::toolParamSpec($site, $tool);

        if ($spec === []) {
            // Fallback grid: keep only rows with a non-empty key.
            return collect((array) ($data['kv_params'] ?? []))
                ->reject(fn ($value, $key): bool => blank($key))
                ->all();
        }

        $arguments = [];

        foreach ($spec as $param) {
            $name = (string) $param['name'];

            if (! array_key_exists($name, $data)) {
                continue;
            }

            $value = $data[$name];

            if ($value === null || $value === '') {
                continue;
            }

            $arguments[$name] = match ((string) ($param['type'] ?? 'string')) {
                'boolean' => (bool) $value,
                'integer' => (int) $value,
                'number' => (float) $value,
                default => $value,
            };
        }

        return $arguments;
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

    /**
     * One-click: whitelist our panel's egress IP in the site's Cloudflare, so an
     * IP Access Rule lets the agent's server-to-server request bypass Cloudflare's
     * protections (the "Just a moment…" 403). The operator supplies a Cloudflare
     * API token for the zone; it is used once and never stored.
     */
    public static function whitelistCloudflare(): Action
    {
        return Action::make('whitelistCloudflare')
            ->label('החרגת IP ב-Cloudflare')
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->visible(fn (): bool => self::isAdmin())
            ->modalHeading(fn (Site $record): string => 'החרגת כתובת המערכת ב-Cloudflare — '.$record->domain)
            ->modalDescription('נחריג את כתובת ה-IP של המערכת מהגנות Cloudflare של האתר, כדי שחיבור הסוכן לא ייחסם. נדרש טוקן API של Cloudflare עם הרשאת עריכה ל-Firewall/IP Access Rules של הזון. הטוקן משמש פעם אחת ואינו נשמר.')
            ->modalSubmitActionLabel('החרג עכשיו')
            ->form([
                Forms\Components\TextInput::make('api_token')
                    ->label('Cloudflare API Token')
                    ->password()->autocomplete('new-password')
                    ->required(fn (): bool => blank(config('billing.cloudflare.api_token')))
                    ->helperText(self::cloudflareTokenHint()),
            ])
            ->action(function (Site $record, array $data): void {
                $ip = app(OutboundIp::class)->current();

                if ($ip === null) {
                    Notification::make()
                        ->title('לא זוהתה כתובת ה-IP של המערכת')
                        ->body('לא הצלחנו לזהות את כתובת ה-IP היוצאת של השרת. נסו שוב מאוחר יותר.')
                        ->danger()->send();

                    return;
                }

                $result = app(CloudflareClient::class)->whitelistIp(
                    self::cloudflareToken($data),
                    $record->domain,
                    $ip,
                    'Multi Digital agent — allow panel IP',
                );

                Notification::make()
                    ->title('Cloudflare — '.$record->domain)
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /** One-click: purge the site's Cloudflare (CDN) cache. */
    public static function purgeCloudflareCache(): Action
    {
        return Action::make('purgeCloudflareCache')
            ->label('ניקוי קאש ב-Cloudflare')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->visible(fn (): bool => self::isAdmin())
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record): string => 'ניקוי קאש ב-Cloudflare — '.$record->domain)
            ->modalDescription('ננקה את כל הקאש של האתר ב-Cloudflare. נדרש טוקן API עם הרשאת Cache Purge לזון.')
            ->modalSubmitActionLabel('נקה קאש')
            ->form([
                Forms\Components\TextInput::make('api_token')
                    ->label('Cloudflare API Token')
                    ->password()->autocomplete('new-password')
                    ->required(fn (): bool => blank(config('billing.cloudflare.api_token')))
                    ->helperText(self::cloudflareTokenHint()),
            ])
            ->action(function (Site $record, array $data): void {
                $result = app(CloudflareClient::class)->purgeCache(self::cloudflareToken($data), $record->domain);

                Notification::make()
                    ->title('Cloudflare — '.$record->domain)
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /** The token to use: the one typed in the action, else the saved account token. */
    private static function cloudflareToken(array $data): string
    {
        return trim((string) ($data['api_token'] ?? '')) ?: trim((string) config('billing.cloudflare.api_token'));
    }

    private static function cloudflareTokenHint(): string
    {
        return filled(config('billing.cloudflare.api_token'))
            ? 'קיים טוקן שמור בהגדרות ← אינטגרציות — השאירו ריק כדי להשתמש בו, או הזינו טוקן אחר לפעולה זו.'
            : 'Cloudflare → My Profile → API Tokens → Create Token, עם ההרשאות לזון של האתר. אפשר גם לשמור טוקן קבוע בהגדרות ← אינטגרציות.';
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
