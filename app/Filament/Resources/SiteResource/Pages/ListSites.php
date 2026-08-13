<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Filament\Support\SiteActions;
use App\Jobs\RefreshCloudflareCountryRulesJob;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Cloudflare\CountryRulesSnapshot;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    /**
     * The last reading of the country rules — from the cache, never from
     * Cloudflare.
     *
     * Reading them live costs two API calls per zone, and doing that while the
     * modal opens is what made the window take forever and then fail to load at
     * all: a few dozen zones outlast whatever the web server allows a request to
     * take, and a request that dies caches nothing, so every attempt paid the
     * full price again. A queued job does the reading now.
     *
     * @return array<string, mixed>
     */
    private function countryOverview(): array
    {
        $reading = CountryRulesSnapshot::read();

        return $reading['data'] ?? ['ok' => false, 'actions' => [], 'legacy' => [], 'unreadable' => 0, 'total_zones' => 0];
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
                    // Required for the hint action below: Filament addresses a
                    // form component's actions by this key.
                    ->key('current_rules')
                    ->label('מה קיים היום')
                    ->content(fn (): HtmlString => $this->currentCountryRules())
                    // The reading runs in the background, so it cannot be waited
                    // for here; the button starts it and says so.
                    ->hintAction(
                        Forms\Components\Actions\Action::make('refreshCountryRules')
                            ->label('קריאה מחדש מ-Cloudflare')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn () => $this->refreshCountryRules())
                    ),
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

                // What is stored now describes the state BEFORE this change.
                // Marked rather than deleted — the list stays on screen, labelled
                // as predating the change — and re-read in the background. Marked
                // even when the run failed: a run that stopped halfway changed
                // some zones and not others, which is exactly when the stored
                // picture is worth least.
                CountryRulesSnapshot::markStale();
                RefreshCloudflareCountryRulesJob::dispatch();

                Notification::make()
                    ->title('כללי מדינה ב-Cloudflare')
                    ->body($message)
                    ->{$ok ? 'success' : 'danger'}()
                    ->send();
            });
    }

    /**
     * Ask for a fresh reading of the country rules.
     *
     * Queued, and the answer is not waited for — that wait is the whole reason
     * this window used to die. Whoever pressed the button is told where the
     * answer will appear instead of being left in front of an unchanged screen.
     */
    public function refreshCountryRules(): void
    {
        RefreshCloudflareCountryRulesJob::dispatch();

        Notification::make()
            ->title('הקריאה מ-Cloudflare יצאה לדרך')
            ->body('היא רצה ברקע ולוקחת כמה שניות — קריאה לכל אתר בחשבון. סגרו את החלון ופתחו אותו שוב כדי לראות את התוצאה.')
            ->info()->send();
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
     * sees what is still taking up the per-zone rule budget.
     *
     * Entirely from the stored reading; nothing here talks to Cloudflare, so the
     * window opens at once. What the reading cannot vouch for is stated instead
     * of left blank — a box showing nothing must never be read as "no rules".
     */
    private function currentCountryRules(): HtmlString
    {
        $muted = 'font-size:.85rem;color:rgb(107 114 128)';

        if (CountryRulesSnapshot::token() === '') {
            return new HtmlString('<span style="'.$muted.'">אין טוקן Cloudflare שמור — שמרו טוקן בהגדרות ← אינטגרציות כדי לראות כאן את הכללים הקיימים.</span>');
        }

        $reading = CountryRulesSnapshot::read();

        // Never read, or read and failed before anything was stored. Said
        // plainly: an empty box here must not be mistaken for "no rules".
        if ($reading === null) {
            return new HtmlString('<div style="'.$muted.'">'.(CountryRulesSnapshot::isRefreshing()
                ? 'הכללים נקראים כרגע מ-Cloudflare ברקע. סגרו את החלון ופתחו אותו שוב בעוד כמה שניות.'
                : 'הכללים הקיימים עדיין לא נקראו מ-Cloudflare. לחצו "קריאה מחדש מ-Cloudflare" למעלה — הקריאה רצה ברקע ולוקחת כמה שניות.')
                .'</div>');
        }

        $sections = [
            $this->combinedRuleLine($muted),
            $this->legacyRulesLine($muted),
            $this->readingAgeLine($reading, $muted),
        ];

        return new HtmlString(implode('', array_filter($sections)));
    }

    /**
     * When this picture was read, and every reason it might no longer be true.
     *
     * A list on screen with no date beside it reads as the current state of
     * Cloudflare. It is a reading, and each of the ways it can be out of date is
     * worth more than the list itself.
     *
     * @param  array{at: Carbon, error: ?string, error_at: ?Carbon, stale: bool}  $reading
     */
    private function readingAgeLine(array $reading, string $muted): string
    {
        $lines = ['<div style="'.$muted.';margin-top:.5rem">נקרא מ-Cloudflare '.e($reading['at']->diffForHumans()).'.</div>'];

        if ($reading['stale']) {
            $lines[] = '<div style="font-size:.85rem;color:rgb(180 83 9)">הכללים שונו מהמסך הזה אחרי הקריאה — מה שמוצג למעלה הוא המצב שלפני השינוי. קריאה מעודכנת רצה ברקע.</div>';
        }

        if ($reading['error'] !== null) {
            $lines[] = '<div style="font-size:.85rem;color:rgb(180 83 9)">הקריאה האחרונה ('
                .e($reading['error_at']?->diffForHumans() ?? '').') נכשלה: '.e($reading['error'])
                .' — ייתכן שהמצב בפועל שונה ממה שמוצג.</div>';
        }

        if (CountryRulesSnapshot::isRefreshing()) {
            $lines[] = '<div style="'.$muted.'">קריאה חדשה רצה כרגע ברקע.</div>';
        }

        return implode('', $lines);
    }

    /** Every action's list side by side — they coexist, so they are shown together. */
    private function combinedRuleLine(string $muted): string
    {
        $overview = $this->countryOverview();

        if (! ($overview['ok'] ?? false)) {
            return '<div style="'.$muted.'">'.e($overview['message'] ?? 'לא ניתן לקרוא את הכללים הקיימים.').'</div>';
        }

        $lines = [];

        // Zones the reading could not cover. Counted and said, because a zone
        // nobody managed to read looks exactly like a zone with no rule.
        if (($overview['unreadable'] ?? 0) > 0) {
            $lines[] = '<div style="font-size:.875rem;color:rgb(180 83 9)">'.$overview['unreadable']
                .' מתוך '.$overview['total_zones'].' אתרים לא נקראו — התמונה כאן חלקית, ולא ניתן להציע ממנה רשימה להחלפה.</div>';
        }

        if (($overview['actions'] ?? []) === []) {
            return implode('', $lines)
                .'<div style="'.$muted.'">אין כרגע כללי מדינות ('.$overview['total_zones'].' זונים בחשבון).</div>';
        }

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

    /**
     * Leftovers from the rule-per-country era, if any are still in place. Out of
     * the same reading as the combined rules — they come from the same responses,
     * and asking Cloudflare twice for them was half the cost of opening this
     * window.
     */
    private function legacyRulesLine(string $muted): string
    {
        $legacy = $this->countryOverview()['legacy'] ?? [];

        if ($legacy === []) {
            return '';
        }

        $countries = collect($legacy)->pluck('country')->implode(', ');

        return '<div style="'.$muted.';margin-top:.5rem">כללים ישנים (כלל נפרד לכל מדינה) עדיין קיימים על '
            .count($legacy).' מדינות: '.e($countries)
            .'. אפשר לנקות אותם למטה כדי לפנות מקום במכסת הכללים.</div>';
    }
}
