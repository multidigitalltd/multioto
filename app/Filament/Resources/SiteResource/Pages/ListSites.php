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
            ->modalDescription('לכל פעולה יש רשימת מדינות משלה, בכלל WAF אחד לכל אתר. אפשר להחליף את הרשימה, להוסיף אליה או להוריד ממנה — בלי לגעת ברשימות של הפעולות האחרות. השינוי חל על כל הזונים בחשבון בבת אחת. נדרשות בטוקן ההרשאות Zone WAF · Edit (לכלל המשולב) ו-Firewall Services · Edit (למעבר חופשי ולכללים הישנים).')
            ->modalSubmitActionLabel('החל על כל האתרים')
            // Opens on "add" with an EMPTY field: the box means "which countries
            // to add", and the list already in force is shown above it. Only
            // "replace" loads the current list, because only there does the box
            // mean "the whole list from now on".
            ->fillForm(fn (): array => ['mode' => 'managed_challenge', 'operation' => 'add',
                'countries' => [], 'remove_legacy' => false])
            ->form([
                Forms\Components\Placeholder::make('current_rules')
                    ->label('מה קיים היום')
                    ->content(fn (): HtmlString => $this->currentCountryRules()),
                Forms\Components\Select::make('mode')
                    ->label('פעולה')->required()->native(false)->default('managed_challenge')->live()
                    ->options([
                        'managed_challenge' => 'אתגר גישה מנוהל (Managed Challenge)',
                        'js_challenge' => 'אתגר JavaScript',
                        'challenge' => 'אתגר (CAPTCHA)',
                        'block' => 'חסימה',
                        'whitelist' => 'מעבר חופשי (Allow)',
                        'remove' => 'מחיקת כל כללי המדינות שלנו',
                    ])
                    // Switching action reloads the box only in "replace" mode,
                    // where it holds a whole list; in add/subtract it holds the
                    // few countries being changed and must not fill itself.
                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                        $set('countries', $get('operation') === 'replace'
                            ? ($this->currentCountryList((string) $state)['countries'] ?? [])
                            : []);
                    })
                    ->helperText(fn (Forms\Get $get): ?string => match ($get('mode')) {
                        'whitelist' => 'מעבר חופשי נשמר ככלל נפרד לכל מדינה (IP Access Rule) — אין לו מקבילה בכלל משולב. לרשימת היתר קצרה זה בסדר.',
                        'remove' => 'מוחק את כל כללי המדינות שהמערכת יצרה — חסימה, אתגרים ומעבר חופשי כאחד. להורדת מדינות בודדות בחרו את הפעולה עצמה ואז "הסרה מהרשימה".',
                        default => null,
                    }),
                Forms\Components\Radio::make('operation')
                    ->label('מה לעשות עם המדינות שמתחת')
                    ->options([
                        'add' => 'הוספה לרשימה הקיימת',
                        'subtract' => 'הסרה מהרשימה הקיימת',
                        'replace' => 'החלפת הרשימה כולה',
                    ])
                    ->default('add')
                    ->required()
                    ->live()
                    ->visible(fn (Forms\Get $get): bool => $get('mode') !== 'remove')
                    // Choosing "replace" loads the list in force, so it can be
                    // edited; choosing add/subtract empties the box, so nobody
                    // submits the whole list as the thing to add or remove.
                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                        $set('countries', $state === 'replace'
                            ? ($this->currentCountryList((string) $get('mode'))['countries'] ?? [])
                            : []);
                    })
                    ->helperText('"החלפה" מוחקת מהרשימה כל מדינה שלא הוזנה כאן. בהוספה ובהסרה מזינים רק את המדינות שמשתנות — הרשימה הקיימת מופיעה למעלה.'),
                Forms\Components\TagsInput::make('countries')
                    ->label(fn (Forms\Get $get): string => match ($get('operation')) {
                        'subtract' => 'מדינות להסרה (קודי ISO של שתי אותיות)',
                        'replace' => 'הרשימה החדשה במלואה (קודי ISO של שתי אותיות)',
                        default => 'מדינות להוספה (קודי ISO של שתי אותיות)',
                    })
                    ->placeholder('MX')
                    ->separator(',')
                    ->helperText(fn (Forms\Get $get): string => $get('operation') === 'replace'
                        ? 'אפשר להדביק רשימה מופרדת בפסיקים: MX,HK,IR,CN. מה שלא יופיע כאן יוסר מהרשימה.'
                        : 'רק המדינות שמשתנות. אפשר להדביק רשימה מופרדת בפסיקים: MX,HK,IR,CN.')
                    ->required(fn (Forms\Get $get): bool => $get('mode') !== 'remove')
                    ->visible(fn (Forms\Get $get): bool => $get('mode') !== 'remove'),
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
                    (string) ($data['operation'] ?? 'replace'),
                );

                $message = $result['message'];
                $ok = $result['ok'];

                // Only after the combined rule is actually in place: clearing the
                // old rules first would leave the sites unprotected in between.
                // The applied list is passed in so the cleanup can only touch
                // rules the new one has genuinely made redundant.
                if ($ok && ($data['remove_legacy'] ?? false)) {
                    // Countries that were just SUBTRACTED are no longer covered
                    // by the combined rule, so cleaning their old rules away too
                    // would leave them with no protection at all.
                    $cleanup = $client->removeLegacyCountryRulesEverywhere(
                        $token,
                        ($data['operation'] ?? 'replace') === 'subtract'
                            ? []
                            : CloudflareClient::countryCodesIn($data['countries'] ?? []),
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
     * The list in force for one action, so editing it is editing — not retyping
     * twenty codes from memory and losing one.
     *
     * @return array<string, mixed>
     */
    private function currentCountryList(string $mode): array
    {
        $entry = $this->countryOverview()['actions'][$mode] ?? null;

        // A list the zones disagree on is deliberately not offered: re-saving it
        // would push one zone's version onto the others.
        if ($entry === null || ! $entry['consistent'] || $entry['countries'] === []) {
            return [];
        }

        // A list, not a joined string: the tags field keeps its state as an
        // array, and Livewire cannot even serialise anything else into it.
        return ['countries' => $entry['countries']];
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

    /** Every action's list side by side — they coexist, so they are shown together. */
    private function combinedRuleLine(string $muted): string
    {
        $overview = $this->countryOverview();

        if (! ($overview['ok'] ?? false)) {
            return '<div style="'.$muted.'">'.e($overview['message'] ?? 'לא ניתן לקרוא את הכללים הקיימים.').'</div>';
        }

        if (($overview['actions'] ?? []) === []) {
            return '<div style="'.$muted.'">אין כרגע כללי מדינות ('.$overview['total_zones'].' זונים בחשבון).</div>';
        }

        $lines = [];

        foreach ($overview['actions'] as $mode => $entry) {
            $label = e(CloudflareClient::COUNTRY_MODE_LABELS[$mode] ?? $mode);

            // A run that failed halfway leaves different lists on different
            // zones. Showing one of them as "the" list would invite a re-save
            // that pushes the wrong countries onto the zones that moved on.
            if (! $entry['consistent']) {
                $lines[] = '<div style="font-size:.875rem;color:rgb(180 83 9)"><strong>'.$label.'</strong> — '
                    .'הכלל אינו זהה בכל האתרים (קיים ב-'.$entry['zones'].' מתוך '.$overview['total_zones'].'). '
                    .'הזינו את הרשימה המבוקשת ובחרו "החלפת הרשימה כולה" כדי ליישר את כולם.</div>';

                continue;
            }

            $lines[] = '<div style="font-size:.875rem"><strong>'.$label.'</strong> — '
                .count($entry['countries']).' מדינות, בכל האתרים:<br>'
                .'<span style="'.$muted.'">'.e(implode(', ', $entry['countries'])).'</span></div>';
        }

        return implode('', $lines);
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
