<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the one job we need from Cloudflare: whitelist our panel's IP
 * for a customer's zone, so an IP Access Rule lets our server-to-server agent
 * request bypass all of Cloudflare's protections (managed challenge, WAF, rate
 * limiting) — the fix for the "Just a moment…" 403 that blocks the MCP endpoint.
 *
 * The API token is supplied per-call by the operator and never stored or logged;
 * all business decisions live here, not in the caller.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Add an "Allow" IP Access Rule for $ip on $domain's zone. Idempotent — a
     * matching rule already in place is treated as success.
     *
     * @return array{ok: bool, message: string}
     */
    public function whitelistIp(string $token, string $domain, string $ip, string $notes): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->fail('כתובת ה-IP של המערכת אינה תקינה.');
        }

        // Every Cloudflare request is inside the same guard, so a timeout or a
        // dropped connection at any step yields the friendly failure notice
        // rather than an unhandled exception out of the Filament action.
        try {
            $zoneId = $this->zoneId($token, $domain);

            if ($zoneId === null) {
                return $this->fail('לא נמצא Zone פעיל ב-Cloudflare עבור הדומיין הזה. ודאו שהאתר מנוהל בחשבון שאליו שייך הטוקן.');
            }

            if ($this->alreadyWhitelisted($token, $zoneId, $ip)) {
                return $this->ok("כתובת ה-IP {$ip} כבר מוחרגת ב-Cloudflare — לא נדרש שינוי.");
            }

            $response = $this->request($token)->post(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
                'mode' => 'whitelist',
                'configuration' => ['target' => 'ip', 'value' => $ip],
                'notes' => $notes,
            ]);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($response->successful() && data_get($response->json(), 'success') === true) {
            return $this->ok("כתובת ה-IP {$ip} הוחרגה מהגנות Cloudflare — חיבור הסוכן לא ייחסם יותר.");
        }

        return $this->fail($this->errorMessage($response, 'החרגת ה-IP ב-Cloudflare נכשלה'));
    }

    /**
     * Purge everything from $domain's Cloudflare cache. Guarded like whitelistIp
     * so any network failure yields a friendly message.
     *
     * @return array{ok: bool, message: string}
     */
    public function purgeCache(string $token, string $domain): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }

        try {
            $zoneId = $this->zoneId($token, $domain);

            if ($zoneId === null) {
                return $this->fail('לא נמצא Zone פעיל ב-Cloudflare עבור הדומיין הזה. ודאו שהאתר מנוהל בחשבון שאליו שייך הטוקן.');
            }

            $response = $this->request($token)->post(self::BASE."/zones/{$zoneId}/purge_cache", [
                'purge_everything' => true,
            ]);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($response->successful() && data_get($response->json(), 'success') === true) {
            return $this->ok("הקאש של {$domain} נוקה ב-Cloudflare.");
        }

        return $this->fail($this->errorMessage($response, 'ניקוי הקאש ב-Cloudflare נכשל'));
    }

    /**
     * List the IP Access Rules on $domain's zone so the team can verify what's
     * whitelisted/blocked from inside the panel — no hunting in the shifting
     * Cloudflare dashboard. Read-only.
     *
     * @return array{ok: bool, message: string, rules: list<array{target: string, value: string, mode: string, notes: string}>}
     */
    public function listAccessRules(string $token, string $domain): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.') + ['rules' => []];
        }

        try {
            $zoneId = $this->zoneId($token, $domain);

            if ($zoneId === null) {
                return $this->fail('לא נמצא Zone פעיל ב-Cloudflare עבור הדומיין הזה. ודאו שהאתר מנוהל בחשבון שאליו שייך הטוקן.') + ['rules' => []];
            }

            $rules = [];
            $page = 1;

            do {
                $body = $this->request($token)->get(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
                    'per_page' => 50,
                    'page' => $page,
                ])->throw()->json();

                foreach ((array) data_get($body, 'result', []) as $rule) {
                    $rules[] = [
                        'target' => (string) data_get($rule, 'configuration.target', ''),
                        'value' => (string) data_get($rule, 'configuration.value', ''),
                        'mode' => (string) ($rule['mode'] ?? ''),
                        'notes' => (string) ($rule['notes'] ?? ''),
                    ];
                }

                $totalPages = (int) data_get($body, 'result_info.total_pages', 1);
                $page++;
            } while ($totalPages > 0 && $page <= $totalPages);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.') + ['rules' => []];
        }

        return ['ok' => true, 'message' => count($rules).' כללי גישה', 'rules' => $rules];
    }

    /** Valid actions for a country rule. 'remove' deletes an existing rule. */
    public const COUNTRY_MODES = ['managed_challenge', 'js_challenge', 'block', 'whitelist', 'remove'];

    /**
     * Apply (or remove) a country IP Access Rule across EVERY zone the token can
     * see — so one change covers all the account's sites at once, which is how
     * the team wants it (the rules overlap). $mode is one of COUNTRY_MODES;
     * 'whitelist' = allow the country, 'remove' = delete the country rule.
     * Idempotent per zone: an existing country rule is updated, not duplicated.
     *
     * @return array{ok: bool, message: string}
     */
    public function applyCountryRuleEverywhere(string $token, string $country, string $mode, string $notes): array
    {
        $token = trim($token);
        $country = strtoupper(trim($country));

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return $this->fail('קוד מדינה חייב להיות שתי אותיות (ISO), למשל US.');
        }
        if (! in_array($mode, self::COUNTRY_MODES, true)) {
            return $this->fail('פעולה לא מוכרת לכלל מדינה.');
        }

        try {
            $zones = $this->listZones($token);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($zones === []) {
            return $this->fail('לא נמצאו זונים ב-Cloudflare עבור הטוקן הזה.');
        }

        $applied = 0;
        $failed = 0;

        foreach ($zones as $zone) {
            try {
                $this->applyCountryRuleToZone($token, $zone, $country, $mode);
                $applied++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $verb = $mode === 'remove' ? "הוסר כלל המדינה {$country}" : "כלל המדינה {$country} ({$mode}) הוחל";

        // Success requires EVERY zone to succeed. A partial failure must not be
        // recorded as a completed all-zones operation — otherwise the approval
        // gate marks it Executed while some sites stay unprotected (or keep a
        // rule that was meant to be removed).
        if ($failed > 0) {
            return $this->fail("הפעולה הצליחה ב-{$applied} אתרים אך נכשלה ב-{$failed}. נסו שוב — הכלל לא הוחל על כל האתרים.");
        }

        return $applied > 0
            ? $this->ok("{$verb} על {$applied} אתרים ב-Cloudflare.")
            : $this->fail('הפעולה נכשלה בכל הזונים.');
    }

    /**
     * The phase that holds a zone's WAF custom rules, and the description that
     * identifies the single rule this system owns inside it. The description is
     * the key: it is how the rule is found again to be updated instead of added
     * a second time, so it must never be edited by hand in the dashboard.
     */
    private const CUSTOM_PHASE = 'http_request_firewall_custom';

    public const COUNTRY_RULE_DESCRIPTION = 'Multi Digital — country rule';

    /** Actions valid for the combined country rule. 'remove' deletes the rule. */
    public const COUNTRY_LIST_MODES = ['managed_challenge', 'js_challenge', 'challenge', 'block', 'whitelist', 'remove'];

    /**
     * One WAF custom rule per zone, covering ALL the given countries at once.
     *
     * The older applyCountryRuleEverywhere() writes an IP Access Rule per
     * country, and Cloudflare caps how many of those a zone may hold — twenty
     * countries meant twenty rules to create, read and later remember. A custom
     * rule takes an expression, so the same twenty countries are one rule:
     *
     *     (ip.src.country in {"MX" "HK" "IR"})
     *
     * Applying again REPLACES the list rather than adding to it: the list on
     * screen is the list in force, which is the only version an operator can
     * reason about. Idempotent per zone — the rule is found by its description
     * and updated, never duplicated.
     *
     * @param  list<string>|string  $countries  ISO-3166 alpha-2 codes, as a list or
     *                                          a delimited string ("MX,HK,IR") —
     *                                          the tags field hands back the
     *                                          latter, and a pasted list is how
     *                                          this is used in practice.
     * @return array{ok: bool, message: string}
     */
    public function applyCountryListEverywhere(string $token, array|string $countries, string $mode): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }
        if (! in_array($mode, self::COUNTRY_LIST_MODES, true)) {
            return $this->fail('פעולה לא מוכרת לכלל מדינות.');
        }

        $clean = [];

        foreach ($this->splitCountries($countries) as $country) {
            $country = strtoupper(trim((string) $country));

            if ($country === '') {
                continue;
            }

            // Rejected rather than skipped: a dropped typo would leave the
            // operator believing a country is covered when it is not.
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                return $this->fail("קוד מדינה לא תקין: {$country}. נדרשים קודי ISO של שתי אותיות, למשל MX.");
            }

            $clean[$country] = true;
        }

        $countries = array_keys($clean);
        sort($countries);

        if ($countries === [] && $mode !== 'remove') {
            return $this->fail('יש להזין לפחות מדינה אחת.');
        }

        // The setting has two possible shapes on Cloudflare — a combined WAF rule
        // for blocking/challenging, and one access rule per country for allowing
        // — and only one of them can be in force. Whichever mode is chosen, the
        // other shape is withdrawn: leaving it would keep enforcing a policy the
        // operator has just replaced, while the screen shows only the new one.
        if ($mode === 'whitelist') {
            // Letting a country THROUGH has no custom-rule equivalent, so it
            // still costs one rule per country. That is fine: an allow list is a
            // handful of countries, not the dozens that made the rule budget the
            // problem in the first place.
            $allowed = $this->allowCountries($token, $countries);

            if (! $allowed['ok']) {
                return $allowed;
            }

            $cleared = $this->applyCombinedRule($token, [], 'remove');

            return $cleared['ok']
                ? $allowed
                : $this->fail('רשימת ההיתר עודכנה, אך הכלל המשולב הקודם לא הוסר — יש להריץ שוב.');
        }

        $combined = $this->applyCombinedRule($token, $countries, $mode);

        if (! $combined['ok']) {
            return $combined;
        }

        // Blocking, challenging or removing all mean the same thing about the
        // other shape: no allow list of ours is in force any more.
        return $this->withdrawAllowRules($token, [])
            ? $combined
            : $this->fail('הכלל המשולב עודכן, אך רשימת ההיתר הקודמת לא הוסרה — יש להריץ שוב.');
    }

    /**
     * The combined WAF rule across every zone the token can see.
     *
     * @param  list<string>  $countries
     * @return array{ok: bool, message: string}
     */
    private function applyCombinedRule(string $token, array $countries, string $mode): array
    {
        try {
            $zones = $this->listZones($token);
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.');
        }

        if ($zones === []) {
            return $this->fail('לא נמצאו זונים ב-Cloudflare עבור הטוקן הזה.');
        }

        $applied = 0;
        $failed = 0;

        foreach ($zones as $zone) {
            try {
                $this->applyCountryListToZone($token, (string) $zone['id'], $countries, $mode);
                $applied++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        // Every zone must succeed. A partial run reported as success would let
        // the approval gate mark the action Executed while some sites keep an
        // old list — or none at all.
        if ($failed > 0) {
            return $this->fail("הפעולה הצליחה ב-{$applied} אתרים אך נכשלה ב-{$failed}. נסו שוב — הכלל לא הוחל על כל האתרים.");
        }

        $what = $mode === 'remove'
            ? 'כלל המדינות הוסר'
            : count($countries).' מדינות ('.(self::COUNTRY_MODE_LABELS[$mode] ?? $mode).') בכלל אחד';

        return $this->ok("{$what} על {$applied} אתרים ב-Cloudflare.");
    }

    /**
     * Flatten however the countries arrived — a list, or one string holding
     * several — into individual entries, without judging them yet.
     *
     * @return list<string>
     */
    private function splitCountries(array|string $countries): array
    {
        $items = [];

        foreach ((array) $countries as $entry) {
            foreach (preg_split('/[^A-Za-z]+/', (string) $entry, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $items[] = $part;
            }
        }

        return $items;
    }

    /**
     * The valid country codes in whatever was typed — for callers that need the
     * list itself rather than to apply it. Invalid entries are dropped here
     * because this is used to NARROW a destructive cleanup: a typo must shrink
     * what gets deleted, never widen it.
     *
     * @return list<string>
     */
    public static function countryCodesIn(array|string $countries): array
    {
        $codes = [];

        foreach ((new self)->splitCountries($countries) as $country) {
            $country = strtoupper($country);

            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $codes[$country] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Make the allow list BE the given countries, one IP Access Rule each.
     *
     * A replacement, like every other mode: a country dropped from the list is
     * dropped from Cloudflare too. Upserting alone would leave a country the
     * operator deliberately removed still bypassing every protection, while the
     * screen told them the list they saved is the list in force.
     *
     * Only rules carrying our own marker are withdrawn — an allow rule somebody
     * else put there is theirs to remove.
     *
     * @param  list<string>  $countries
     * @return array{ok: bool, message: string}
     */
    private function allowCountries(string $token, array $countries): array
    {
        if (! $this->withdrawAllowRules($token, $countries)) {
            return $this->fail('הסרת מדינות שהוצאו מרשימת ההיתר נכשלה — הרשימה לא עודכנה. נסו שוב.');
        }

        foreach ($countries as $country) {
            $result = $this->applyCountryRuleEverywhere($token, $country, 'whitelist', self::COUNTRY_RULE_DESCRIPTION);

            // Stop at the first failure rather than reporting a half-applied
            // allow list as done.
            if (! $result['ok']) {
                return $result;
            }
        }

        return $this->ok(count($countries).' מדינות סומנו כמעבר חופשי בכל האתרים.');
    }

    /**
     * Withdraw our own country allow rules, except for the countries in $keep.
     * Somebody else's allow rule is theirs to remove.
     *
     * @param  list<string>  $keep
     * @return bool whether every withdrawal succeeded
     */
    private function withdrawAllowRules(string $token, array $keep): bool
    {
        try {
            foreach ($this->listZones($token) as $zone) {
                $zoneId = (string) $zone['id'];

                foreach ($this->countryAccessRules($token, $zoneId) as $rule) {
                    $country = strtoupper((string) data_get($rule, 'configuration.value'));

                    if ((string) data_get($rule, 'mode') !== 'whitelist'
                        || in_array($country, $keep, true)
                        || ! str_contains((string) data_get($rule, 'notes'), 'Multi Digital')) {
                        continue;
                    }

                    $this->request($token)
                        ->delete(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules/".data_get($rule, 'id'))
                        ->throw();
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Every country-targeted IP Access Rule on a zone, across all pages.
     *
     * @return list<array<string, mixed>>
     */
    private function countryAccessRules(string $token, string $zoneId, int $page = 1): array
    {
        $body = $this->request($token)->get(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
            'configuration.target' => 'country',
            'per_page' => 100,
            'page' => $page,
        ])->throw()->json();

        $rules = (array) data_get($body, 'result', []);
        $totalPages = (int) data_get($body, 'result_info.total_pages', 1);

        if ($page < $totalPages) {
            $rules = array_merge($rules, $this->countryAccessRules($token, $zoneId, $page + 1));
        }

        return array_values($rules);
    }

    /**
     * Delete the legacy per-country IP Access Rules that the combined rule has
     * made redundant.
     *
     * Three conditions, all of them narrowing:
     *  - Only blocking/challenging rules. A country on 'whitelist' is there to
     *    let someone THROUGH, and deleting it would quietly tighten access.
     *  - Only rules that are ours (by their notes) or whose country the combined
     *    rule now covers. Anything else belongs to a person or an integration
     *    that did not ask us to clean up after it.
     *  - Nothing at all when $covered is empty and the rule carries no marker,
     *    so a cleanup can never be broader than the rule that justified it.
     *
     * @param  list<string>  $covered  countries the combined rule now handles
     * @return array{ok: bool, message: string, removed: int}
     */
    public function removeLegacyCountryRulesEverywhere(string $token, array $covered = []): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.') + ['removed' => 0];
        }

        $removable = ['block', 'challenge', 'js_challenge', 'managed_challenge'];
        $covered = array_map(fn ($c): string => strtoupper(trim((string) $c)), $covered);
        $removed = 0;

        try {
            foreach ($this->listZones($token) as $zone) {
                $zoneId = (string) $zone['id'];

                // Read every page BEFORE deleting anything. Deleting shifts the
                // later pages back by one, so interleaving the two would step
                // over rules — and stopping at the first page that happens to
                // hold nothing of ours would miss the rest entirely.
                $deletable = array_filter(
                    $this->countryAccessRules($token, $zoneId),
                    function (array $rule) use ($removable, $covered): bool {
                        if (! in_array((string) data_get($rule, 'mode'), $removable, true)) {
                            return false;
                        }

                        return str_contains((string) data_get($rule, 'notes'), 'Multi Digital')
                            || in_array(strtoupper((string) data_get($rule, 'configuration.value')), $covered, true);
                    },
                );

                foreach ($deletable as $rule) {
                    $this->request($token)
                        ->delete(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules/".data_get($rule, 'id'))
                        ->throw();

                    $removed++;
                }
            }
        } catch (\Throwable) {
            return $this->fail("הניקוי נעצר באמצע — {$removed} כללים ישנים הוסרו. נסו שוב.") + ['removed' => $removed];
        }

        return $this->ok($removed === 0
            ? 'לא נמצאו כללי מדינה ישנים שלנו לניקוי.'
            : "{$removed} כללי מדינה ישנים הוסרו ופינו מקום במכסת הכללים.") + ['removed' => $removed];
    }

    /** Upsert (or delete) the combined country rule on a single zone. */
    private function applyCountryListToZone(string $token, string $zoneId, array $countries, string $mode): void
    {
        $ruleset = $this->customRuleset($token, $zoneId);
        $existingId = $this->ownedRuleId($ruleset);

        if ($mode === 'remove') {
            if ($ruleset !== null && $existingId !== null) {
                $this->request($token)
                    ->delete(self::BASE."/zones/{$zoneId}/rulesets/{$ruleset['id']}/rules/{$existingId}")
                    ->throw();
            }

            return;
        }

        $rule = [
            'action' => $mode,
            'expression' => $this->countryExpression($countries),
            'description' => self::COUNTRY_RULE_DESCRIPTION,
            'enabled' => true,
        ];

        // A zone that has never had a custom rule has no ruleset to add to; the
        // entrypoint is created with our rule already inside it.
        if ($ruleset === null) {
            $this->request($token)->post(self::BASE."/zones/{$zoneId}/rulesets", [
                'name' => 'Multi Digital custom rules',
                'kind' => 'zone',
                'phase' => self::CUSTOM_PHASE,
                'rules' => [$rule],
            ])->throw();

            return;
        }

        if ($existingId !== null) {
            $this->request($token)
                ->patch(self::BASE."/zones/{$zoneId}/rulesets/{$ruleset['id']}/rules/{$existingId}", $rule)
                ->throw();

            return;
        }

        $this->request($token)->post(self::BASE."/zones/{$zoneId}/rulesets/{$ruleset['id']}/rules", $rule)->throw();
    }

    /**
     * The country set as a Cloudflare expression. Every value has already been
     * proved to be two letters, so nothing else can reach the expression string.
     *
     * @param  list<string>  $countries
     */
    private function countryExpression(array $countries): string
    {
        return '(ip.src.country in {"'.implode('" "', $countries).'"})';
    }

    /**
     * A zone's custom-rules entrypoint ruleset, or null when the zone has none.
     *
     * @return array{id: string, rules: array<int, array<string, mixed>>}|null
     */
    private function customRuleset(string $token, string $zoneId): ?array
    {
        $response = $this->request($token)->get(self::BASE."/zones/{$zoneId}/rulesets/phases/".self::CUSTOM_PHASE.'/entrypoint');

        // 404 means "no custom rules here yet", which is a normal starting state
        // and not a failure to report.
        if ($response->status() === 404) {
            return null;
        }

        $body = $response->throw()->json();

        return [
            'id' => (string) data_get($body, 'result.id'),
            'rules' => (array) data_get($body, 'result.rules', []),
        ];
    }

    /** The id of our own rule inside a ruleset, found by its description. */
    private function ownedRuleId(?array $ruleset): ?string
    {
        foreach ($ruleset['rules'] ?? [] as $rule) {
            if ((string) ($rule['description'] ?? '') === self::COUNTRY_RULE_DESCRIPTION) {
                return (string) $rule['id'];
            }
        }

        return null;
    }

    /**
     * The combined country rule as it stands across the account: which countries
     * it covers, with which action, and on how many zones — so the operator edits
     * the real list instead of retyping it from memory.
     *
     * `consistent` is false when the zones do not all carry the SAME list and
     * action — which is what a run that failed halfway leaves behind. The list is
     * then not safe to preload: re-saving it would quietly restore whichever
     * version happened to be read first onto the zones that already moved on.
     *
     * @return array{ok: bool, message: string, total_zones: int, countries: list<string>, mode: ?string, zones: int, consistent: bool}
     */
    public function countryListOverview(string $token): array
    {
        $token = trim($token);
        $empty = ['total_zones' => 0, 'countries' => [], 'mode' => null, 'zones' => 0, 'consistent' => true];

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.') + $empty;
        }

        try {
            $zones = $this->listZones($token);
            $variants = [];
            $withRule = 0;

            foreach ($zones as $zone) {
                $rules = $this->customRuleset($token, (string) $zone['id'])['rules'] ?? [];

                foreach ($rules as $rule) {
                    if ((string) ($rule['description'] ?? '') !== self::COUNTRY_RULE_DESCRIPTION) {
                        continue;
                    }

                    $withRule++;
                    $countries = $this->countriesIn((string) ($rule['expression'] ?? ''));
                    sort($countries);
                    $action = (string) ($rule['action'] ?? '');

                    // Keyed by content, so two zones agreeing collapse into one
                    // entry and two disagreeing do not.
                    $variants[$action.'|'.implode(',', $countries)] = ['countries' => $countries, 'mode' => $action];
                }
            }
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.') + $empty;
        }

        // A zone MISSING the rule is a disagreement too — that is what a partial
        // removal leaves behind, and preloading the surviving list would let a
        // re-save recreate the rule on the zones it was already deleted from.
        $consistent = count($variants) <= 1
            && ($withRule === 0 || $withRule === count($zones));

        $first = reset($variants) ?: ['countries' => [], 'mode' => null];

        return [
            'ok' => true,
            'message' => $consistent
                ? count($first['countries']).' מדינות בכלל המשולב'
                : 'הכלל אינו זהה בכל האתרים — '.count($variants).' גרסאות שונות',
            'total_zones' => count($zones),
            // Nothing is offered for editing when the zones disagree: the
            // operator must re-apply a list deliberately, not inherit one.
            'countries' => $consistent ? $first['countries'] : [],
            'mode' => $consistent ? $first['mode'] : null,
            'zones' => $withRule,
            'consistent' => $consistent,
        ];
    }

    /**
     * The country codes inside an expression we wrote.
     *
     * @return list<string>
     */
    private function countriesIn(string $expression): array
    {
        preg_match_all('/"([A-Z]{2})"/', $expression, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** Hebrew labels for country-rule modes, for operator-facing summaries. */
    public const COUNTRY_MODE_LABELS = [
        'block' => 'חסימה',
        'managed_challenge' => 'אתגר מנוהל',
        'js_challenge' => 'אתגר JavaScript',
        'whitelist' => 'מעבר חופשי',
        'challenge' => 'אתגר (CAPTCHA)',
    ];

    /**
     * Account-wide overview of the EXISTING country access rules: which
     * countries already have a rule, with which action, and on how many zones —
     * so the operator sees what is already blocked before adding another rule.
     *
     * @return array{ok: bool, message: string, total_zones: int, countries: list<array{country: string, modes: array<string, int>}>}
     */
    public function countryRulesOverview(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.') + ['total_zones' => 0, 'countries' => []];
        }

        try {
            $zones = $this->listZones($token);
            $byCountry = [];

            foreach ($zones as $zone) {
                $page = 1;

                do {
                    $body = $this->request($token)->get(self::BASE."/zones/{$zone['id']}/firewall/access_rules/rules", [
                        'configuration.target' => 'country',
                        'per_page' => 100,
                        'page' => $page,
                    ])->throw()->json();

                    foreach ((array) data_get($body, 'result', []) as $rule) {
                        $country = strtoupper((string) data_get($rule, 'configuration.value'));

                        if ($country !== '') {
                            $mode = (string) data_get($rule, 'mode');
                            $byCountry[$country][$mode] = ($byCountry[$country][$mode] ?? 0) + 1;
                        }
                    }

                    $totalPages = (int) data_get($body, 'result_info.total_pages', 1);
                    $page++;
                } while ($totalPages > 0 && $page <= $totalPages);
            }
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.') + ['total_zones' => 0, 'countries' => []];
        }

        ksort($byCountry);

        return [
            'ok' => true,
            'message' => count($byCountry).' כללי מדינה קיימים',
            'total_zones' => count($zones),
            'countries' => collect($byCountry)
                ->map(fn (array $modes, string $country): array => ['country' => $country, 'modes' => $modes])
                ->values()->all(),
        ];
    }

    /** Upsert (or delete) the country rule for a single zone. */
    private function applyCountryRuleToZone(string $token, array $zone, string $country, string $mode): void
    {
        $zoneId = (string) $zone['id'];
        $existing = data_get($this->request($token)->get(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
            'configuration.target' => 'country',
            'configuration.value' => $country,
        ])->json(), 'result.0.id');

        if ($mode === 'remove') {
            if (filled($existing)) {
                $this->request($token)->delete(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules/{$existing}")->throw();
            }

            return;
        }

        if (filled($existing)) {
            $this->request($token)->patch(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules/{$existing}", ['mode' => $mode])->throw();

            return;
        }

        $this->request($token)->post(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
            'mode' => $mode,
            'configuration' => ['target' => 'country', 'value' => $country],
            'notes' => 'Multi Digital — country rule',
        ])->throw();
    }

    /**
     * Every zone the token can see (paginated).
     *
     * @return list<array{id: string, name: string}>
     */
    private function listZones(string $token): array
    {
        $zones = [];
        $page = 1;

        do {
            $body = $this->request($token)->get(self::BASE.'/zones', ['per_page' => 50, 'page' => $page])->throw()->json();

            foreach ((array) data_get($body, 'result', []) as $zone) {
                if (filled($zone['id'] ?? null)) {
                    $zones[] = ['id' => (string) $zone['id'], 'name' => (string) ($zone['name'] ?? '')];
                }
            }

            $totalPages = (int) data_get($body, 'result_info.total_pages', 1);
            $page++;
        } while ($totalPages > 0 && $page <= $totalPages);

        return $zones;
    }

    /** Resolve the zone id for a domain, trying the host and each parent domain. */
    private function zoneId(string $token, string $domain): ?string
    {
        foreach ($this->zoneCandidates($domain) as $name) {
            $id = data_get($this->request($token)->get(self::BASE.'/zones', [
                'name' => $name,
                'status' => 'active',
            ])->json(), 'result.0.id');

            if (filled($id)) {
                return (string) $id;
            }
        }

        return null;
    }

    private function alreadyWhitelisted(string $token, string $zoneId, string $ip): bool
    {
        return filled(data_get($this->request($token)->get(self::BASE."/zones/{$zoneId}/firewall/access_rules/rules", [
            'mode' => 'whitelist',
            'configuration.target' => 'ip',
            'configuration.value' => $ip,
        ])->json(), 'result.0.id'));
    }

    /**
     * Zone-name candidates from most to least specific — so a subdomain
     * (shop.example.co.il) still resolves to its registrable zone
     * (example.co.il) without bundling a public-suffix list.
     *
     * @return list<string>
     */
    private function zoneCandidates(string $domain): array
    {
        $host = strtolower(trim($domain));
        $host = (string) preg_replace(['#^https?://#', '#/.*$#', '#^www\.#'], '', $host);
        $parts = array_values(array_filter(explode('.', $host)));

        $candidates = [];
        for ($i = 0; $i <= count($parts) - 2; $i++) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }

        return $candidates;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(15);
    }

    private function errorMessage(Response $response, string $prefix): string
    {
        $detail = data_get($response->json(), 'errors.0.message');

        return $prefix.(filled($detail) ? ': '.$detail : " (HTTP {$response->status()})").'.';
    }

    /** @return array{ok: true, message: string} */
    private function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok: false, message: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
