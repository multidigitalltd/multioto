<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Filament\Support\SiteActions;
use App\Services\Cloudflare\CloudflareClient;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    /**
     * Cache key for the account-wide country-rules overview (N zones = N API
     * calls). Bound to the token's hash: replacing the saved token — possibly
     * for a DIFFERENT Cloudflare account — must never serve the previous
     * account's zones from cache.
     */
    private function countryOverviewCacheKey(string $token): string
    {
        return 'cloudflare.country_rules_overview.'.sha1($token);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->countryRuleAction(),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * A Cloudflare country rule applied to EVERY zone at once — the team's rules
     * overlap, so one change covers all sites. Uses the saved Cloudflare token
     * (or a one-time token typed here). Admin-only.
     */
    private function countryRuleAction(): Actions\Action
    {
        return Actions\Action::make('countryRule')
            ->label('כלל מדינה ב-Cloudflare')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading('כלל מדינה ב-Cloudflare — לכל האתרים')
            ->modalDescription('הכלל יוחל על כל הזונים בחשבון ה-Cloudflare בבת אחת (IP Access Rule לפי מדינה). נדרשת הרשאת Firewall Services · Edit בטוקן.')
            ->modalSubmitActionLabel('החל על כל האתרים')
            ->form([
                Forms\Components\Placeholder::make('current_rules')
                    ->label('מה כבר חסום היום')
                    ->content(fn (): HtmlString => $this->currentCountryRules()),
                Forms\Components\TextInput::make('country')
                    ->label('קוד מדינה (ISO, שתי אותיות)')
                    ->required()->maxLength(2)->placeholder('US')
                    ->helperText('למשל US, RU, CN, IL. אות גדולה/קטנה לא משנה.'),
                Forms\Components\Select::make('mode')
                    ->label('פעולה')->required()->native(false)->default('managed_challenge')
                    ->options([
                        'managed_challenge' => 'אתגר גישה מנוהל (Managed Challenge)',
                        'js_challenge' => 'אתגר JavaScript',
                        'block' => 'חסימה',
                        'whitelist' => 'מעבר חופשי (Allow)',
                        'remove' => 'הסרת הכלל',
                    ]),
                SiteActions::cloudflareTokenField(),
            ])
            ->action(function (array $data): void {
                $token = trim((string) ($data['api_token'] ?? '')) ?: trim((string) config('billing.cloudflare.api_token'));

                $result = app(CloudflareClient::class)->applyCountryRuleEverywhere(
                    $token,
                    (string) ($data['country'] ?? ''),
                    (string) ($data['mode'] ?? ''),
                    'Multi Digital — country rule',
                );

                // The overview must reflect the change the next time it opens.
                Cache::forget($this->countryOverviewCacheKey(trim((string) config('billing.cloudflare.api_token'))));

                Notification::make()
                    ->title('כללי מדינה ב-Cloudflare')
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /**
     * "What is already blocked" — the existing country rules across all zones,
     * shown inside the modal so the operator never adds a rule blind. Reads the
     * saved token only (never a typed one) and caches for a few minutes: the
     * overview costs one API call per zone.
     */
    private function currentCountryRules(): HtmlString
    {
        $token = trim((string) config('billing.cloudflare.api_token'));
        $muted = 'font-size:.85rem;color:rgb(107 114 128)';

        if ($token === '') {
            return new HtmlString('<span style="'.$muted.'">אין טוקן Cloudflare שמור — שמרו טוקן בהגדרות ← אינטגרציות כדי לראות כאן את הכללים הקיימים.</span>');
        }

        $overview = Cache::remember($this->countryOverviewCacheKey($token), now()->addMinutes(5),
            fn (): array => app(CloudflareClient::class)->countryRulesOverview($token));

        if (! $overview['ok']) {
            return new HtmlString('<span style="'.$muted.'">'.e($overview['message']).'</span>');
        }

        if ($overview['countries'] === []) {
            return new HtmlString('<span style="'.$muted.'">אין כרגע כללי מדינה על אף אתר ('.$overview['total_zones'].' זונים בחשבון).</span>');
        }

        $lines = collect($overview['countries'])->map(function (array $entry) use ($overview): string {
            $modes = collect($entry['modes'])->map(fn (int $zones, string $mode): string => e(CloudflareClient::COUNTRY_MODE_LABELS[$mode] ?? $mode)
                .($zones === $overview['total_zones'] ? ' בכל האתרים' : " ב-{$zones} מתוך {$overview['total_zones']} אתרים"))->implode(', ');

            return '<li><strong>'.e($entry['country']).'</strong> — '.$modes.'</li>';
        });

        return new HtmlString('<ul style="font-size:.875rem;display:flex;flex-direction:column;gap:.25rem">'.$lines->implode('').'</ul>');
    }
}
