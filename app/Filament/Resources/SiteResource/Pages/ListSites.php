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
    private function countryOverviewCacheKey(string $token, string $kind = 'legacy'): string
    {
        return 'cloudflare.country_rules_overview.'.$kind.'.'.sha1($token);
    }

    /**
     * The combined country rule as it stands, cached: reading it costs one API
     * call per zone. Uses the saved token only, never one typed into the modal.
     *
     * @return array<string, mixed>
     */
    private function countryOverview(): array
    {
        $token = trim((string) config('billing.cloudflare.api_token'));

        if ($token === '') {
            return ['ok' => false, 'countries' => [], 'mode' => null, 'zones' => 0, 'total_zones' => 0];
        }

        return Cache::remember($this->countryOverviewCacheKey($token, 'list'), now()->addMinutes(5),
            fn (): array => app(CloudflareClient::class)->countryListOverview($token));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->countryRuleAction(),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * One Cloudflare rule covering a whole list of countries, applied to EVERY
     * zone at once — the team's rules overlap, so one change covers all sites.
     *
     * A rule per country runs into Cloudflare's per-zone rule budget and is
     * miserable to maintain; a WAF custom rule takes an expression, so twenty
     * countries fit in one rule. Uses the saved Cloudflare token (or a one-time
     * token typed here). Admin-only.
     */
    private function countryRuleAction(): Actions\Action
    {
        return Actions\Action::make('countryRule')
            ->label('כלל מדינות ב-Cloudflare')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
            ->modalHeading('כלל מדינות ב-Cloudflare — לכל האתרים')
            ->modalDescription('כל המדינות שתזינו נכנסות לכלל WAF אחד לכל אתר, במקום כלל נפרד לכל מדינה. הכלל יוחל על כל הזונים בחשבון בבת אחת. נדרשות בטוקן ההרשאות Zone WAF · Edit (לכלל המשולב) ו-Firewall Services · Edit (לכללים הישנים ולמעבר חופשי).')
            ->modalSubmitActionLabel('החל על כל האתרים')
            ->fillForm(fn (): array => $this->currentCountryList())
            ->form([
                Forms\Components\Placeholder::make('current_rules')
                    ->label('מה קיים היום')
                    ->content(fn (): HtmlString => $this->currentCountryRules()),
                Forms\Components\TagsInput::make('countries')
                    ->label('מדינות (קודי ISO של שתי אותיות)')
                    ->placeholder('MX')
                    ->separator(',')
                    ->helperText('אפשר להדביק רשימה מופרדת בפסיקים: MX,HK,IR,CN. הרשימה כאן מחליפה את הרשימה הקיימת — מה שלא ברשימה, לא נחסם.')
                    ->required(fn (Forms\Get $get): bool => $get('mode') !== 'remove')
                    ->visible(fn (Forms\Get $get): bool => $get('mode') !== 'remove'),
                Forms\Components\Select::make('mode')
                    ->label('פעולה')->required()->native(false)->default('managed_challenge')->live()
                    ->options([
                        'managed_challenge' => 'אתגר גישה מנוהל (Managed Challenge)',
                        'js_challenge' => 'אתגר JavaScript',
                        'challenge' => 'אתגר (CAPTCHA)',
                        'block' => 'חסימה',
                        'whitelist' => 'מעבר חופשי (Allow)',
                        'remove' => 'הסרת הכלל המשולב',
                    ])
                    ->helperText(fn (Forms\Get $get): ?string => $get('mode') === 'whitelist'
                        ? 'מעבר חופשי נשמר ככלל נפרד לכל מדינה (IP Access Rule) — אין לו מקבילה בכלל משולב. לרשימת היתר קצרה זה בסדר.'
                        : null),
                Forms\Components\Toggle::make('remove_legacy')
                    ->label('נקה גם את כללי המדינה הישנים')
                    ->helperText('מוחק את כללי ה-IP Access Rule הישנים (כלל לכל מדינה) שהכלל המשולב מחליף, ומפנה מקום במכסת הכללים. כללי "מעבר חופשי" לא נמחקים.')
                    ->default(false),
                SiteActions::cloudflareTokenField(),
            ])
            ->action(function (array $data): void {
                $token = trim((string) ($data['api_token'] ?? '')) ?: trim((string) config('billing.cloudflare.api_token'));
                $client = app(CloudflareClient::class);

                // The tags field hands back a comma-joined string; the client
                // accepts either shape.
                $result = $client->applyCountryListEverywhere(
                    $token,
                    $data['countries'] ?? [],
                    (string) ($data['mode'] ?? ''),
                );

                $message = $result['message'];
                $ok = $result['ok'];

                // Only after the combined rule is actually in place: clearing the
                // old rules first would leave the sites unprotected in between.
                // The applied list is passed in so the cleanup can only touch
                // rules the new one has genuinely made redundant.
                if ($ok && ($data['remove_legacy'] ?? false)) {
                    $cleanup = $client->removeLegacyCountryRulesEverywhere(
                        $token,
                        CloudflareClient::countryCodesIn($data['countries'] ?? []),
                    );

                    // A cleanup that stopped halfway is not a success, even
                    // though the rule itself went out fine — the operator has a
                    // half-migrated account and needs to know.
                    $ok = $cleanup['ok'];
                    $message .= ' '.$cleanup['message'];
                }

                // Both overviews must reflect the change the next time they open.
                $saved = trim((string) config('billing.cloudflare.api_token'));
                Cache::forget($this->countryOverviewCacheKey($saved));
                Cache::forget($this->countryOverviewCacheKey($saved, 'list'));

                Notification::make()
                    ->title('כללי מדינה ב-Cloudflare')
                    ->body($message)
                    ->{$ok ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /**
     * Open the modal with the list that is actually in force, so editing it is
     * editing — not retyping twenty codes from memory and losing one.
     *
     * @return array<string, mixed>
     */
    private function currentCountryList(): array
    {
        $overview = $this->countryOverview();

        if (! ($overview['ok'] ?? false) || ($overview['countries'] ?? []) === []) {
            return [];
        }

        return array_filter([
            // Joined, not a list: the tags field stores a delimited string.
            'countries' => implode(',', $overview['countries']),
            'mode' => in_array($overview['mode'], CloudflareClient::COUNTRY_LIST_MODES, true)
                ? $overview['mode']
                : null,
        ]);
    }

    /**
     * "What exists today" — the combined rule first, then any leftover per-country
     * rules from the old mechanism, so the operator both edits the real list and
     * sees what is still taking up the per-zone rule budget. Reads the saved token
     * only (never a typed one) and caches for a few minutes: each overview costs
     * one API call per zone.
     */
    private function currentCountryRules(): HtmlString
    {
        $token = trim((string) config('billing.cloudflare.api_token'));
        $muted = 'font-size:.85rem;color:rgb(107 114 128)';

        if ($token === '') {
            return new HtmlString('<span style="'.$muted.'">אין טוקן Cloudflare שמור — שמרו טוקן בהגדרות ← אינטגרציות כדי לראות כאן את הכללים הקיימים.</span>');
        }

        $sections = [$this->combinedRuleLine($muted), $this->legacyRulesLine($token, $muted)];

        return new HtmlString(implode('', array_filter($sections)));
    }

    /** The combined rule: which countries, which action, on how many zones. */
    private function combinedRuleLine(string $muted): string
    {
        $overview = $this->countryOverview();

        if (! ($overview['ok'] ?? false)) {
            return '<div style="'.$muted.'">'.e($overview['message'] ?? 'לא ניתן לקרוא את הכללים הקיימים.').'</div>';
        }

        // A run that failed halfway leaves different lists on different zones.
        // Showing one of them as "the" list would invite a re-save that quietly
        // pushes the wrong countries back onto the zones that already moved on.
        if (! ($overview['consistent'] ?? true)) {
            return '<div style="font-size:.875rem;color:rgb(180 83 9)"><strong>הכלל אינו זהה בכל האתרים.</strong><br>'
                .'<span style="'.$muted.'">כנראה החלה שנכשלה באמצע. הזינו את הרשימה המבוקשת מחדש והחילו — כך כל האתרים יחזרו לאותו מצב.</span></div>';
        }

        if (($overview['countries'] ?? []) === []) {
            return '<div style="'.$muted.'">אין כרגע כלל מדינות משולב ('.$overview['total_zones'].' זונים בחשבון).</div>';
        }

        $mode = CloudflareClient::COUNTRY_MODE_LABELS[$overview['mode']] ?? $overview['mode'];
        $scope = $overview['zones'] === $overview['total_zones']
            ? 'בכל האתרים'
            : "ב-{$overview['zones']} מתוך {$overview['total_zones']} אתרים";

        return '<div style="font-size:.875rem"><strong>הכלל המשולב</strong> — '
            .count($overview['countries']).' מדינות, '.e($mode).', '.$scope.':<br>'
            .'<span style="'.$muted.'">'.e(implode(', ', $overview['countries'])).'</span></div>';
    }

    /** Leftovers from the rule-per-country era, if any are still in place. */
    private function legacyRulesLine(string $token, string $muted): string
    {
        $overview = Cache::remember($this->countryOverviewCacheKey($token), now()->addMinutes(5),
            fn (): array => app(CloudflareClient::class)->countryRulesOverview($token));

        if (! $overview['ok'] || $overview['countries'] === []) {
            return '';
        }

        $countries = collect($overview['countries'])->pluck('country')->implode(', ');

        return '<div style="'.$muted.';margin-top:.5rem">כללים ישנים (כלל נפרד לכל מדינה) עדיין קיימים על '
            .count($overview['countries']).' מדינות: '.e($countries)
            .'. אפשר לנקות אותם למטה כדי לפנות מקום במכסת הכללים.</div>';
    }
}
