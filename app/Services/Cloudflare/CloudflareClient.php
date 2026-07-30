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
     * What to do with the submitted countries relative to the ones already in
     * force FOR THAT ACTION.
     *
     * Each action keeps its own rule, so a block list and a challenge list live
     * side by side and neither disturbs the other. 'add' and 'subtract' let a
     * country be added or dropped without restating the rest of the list — which
     * is how this is used day to day.
     */
    public const COUNTRY_LIST_OPERATIONS = ['replace', 'add', 'subtract'];

    /** The rule this system owns for a given action. */
    private function ruleDescription(string $mode): string
    {
        return self::COUNTRY_RULE_DESCRIPTION.' ('.$mode.')';
    }

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
     * Each action keeps its own rule, so a block list and a challenge list can
     * both be in force, and $operation decides whether the submitted countries
     * replace that action's list, join it, or leave it. Idempotent per zone — the
     * rule is found by its description and updated, never duplicated.
     *
     * @param  list<string>|string  $countries  ISO-3166 alpha-2 codes, as a list or
     *                                          a delimited string ("MX,HK,IR") —
     *                                          the tags field hands back the
     *                                          latter, and a pasted list is how
     *                                          this is used in practice.
     * @return array{ok: bool, message: string}
     */
    public function applyCountryListEverywhere(string $token, array|string $countries, string $mode, string $operation = 'replace'): array
    {
        $token = trim($token);

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.');
        }
        if (! in_array($mode, self::COUNTRY_LIST_MODES, true)) {
            return $this->fail('פעולה לא מוכרת לכלל מדינות.');
        }
        if (! in_array($operation, self::COUNTRY_LIST_OPERATIONS, true)) {
            return $this->fail('אופן עדכון לא מוכר לרשימת המדינות.');
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

        // Each action stands on its own. Setting an allow list does not clear a
        // block list, and blocking does not clear an allow list — they answer
        // different questions about different countries, and quietly undoing one
        // because the other was edited is how a policy disappears unnoticed.
        if ($mode === 'whitelist') {
            // Letting a country THROUGH has no custom-rule equivalent, so it
            // still costs one rule per country. That is fine: an allow list is a
            // handful of countries, not the dozens that made the rule budget the
            // problem in the first place.
            return $this->allowCountries($token, $countries, $operation);
        }

        $combined = $this->applyCombinedRule($token, $countries, $mode, $operation);

        // The clean slate covers the allow rules too — they are country rules of
        // ours as much as the WAF one is.
        if ($mode === 'remove' && $combined['ok'] && ! $this->withdrawAllowRules($token, [])) {
            return $this->fail('כללי ה-WAF הוסרו, אך רשימת ההיתר לא — יש להריץ שוב.');
        }

        return $combined;
    }

    /**
     * The combined WAF rule for one action, across every zone the token can see.
     *
     * @param  list<string>  $countries
     * @return array{ok: bool, message: string}
     */
    private function applyCombinedRule(string $token, array $countries, string $mode, string $operation = 'replace'): array
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
                $this->applyCountryListToZone($token, (string) $zone['id'], $countries, $mode, $operation);
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

        $label = self::COUNTRY_MODE_LABELS[$mode] ?? $mode;
        $count = count($countries);

        $what = match (true) {
            $mode === 'remove' => 'כלל המדינות הוסר',
            $operation === 'add' => "{$count} מדינות נוספו לכלל {$label}",
            $operation === 'subtract' => "{$count} מדינות הוסרו מכלל {$label}",
            default => "{$count} מדינות ({$label}) בכלל אחד",
        };

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
    private function allowCountries(string $token, array $countries, string $operation = 'replace'): array
    {
        // Subtracting means only the named countries go; adding leaves the rest
        // of the list alone; replacing withdraws everything not named.
        $keep = match ($operation) {
            'add' => null,
            'subtract' => $countries,
            default => $countries,
        };

        if ($operation !== 'add' && ! $this->withdrawAllowRules($token, $keep ?? [], invert: $operation === 'subtract')) {
            return $this->fail('הסרת מדינות שהוצאו מרשימת ההיתר נכשלה — הרשימה לא עודכנה. נסו שוב.');
        }

        if ($operation !== 'subtract') {
            foreach ($countries as $country) {
                $result = $this->applyCountryRuleEverywhere($token, $country, 'whitelist', self::COUNTRY_RULE_DESCRIPTION);

                // Stop at the first failure rather than reporting a half-applied
                // allow list as done.
                if (! $result['ok']) {
                    return $result;
                }
            }
        }

        $count = count($countries);

        return $this->ok(match ($operation) {
            'add' => "{$count} מדינות נוספו לרשימת ההיתר בכל האתרים.",
            'subtract' => "{$count} מדינות הוסרו מרשימת ההיתר בכל האתרים.",
            default => "{$count} מדינות סומנו כמעבר חופשי בכל האתרים.",
        });
    }

    /**
     * Withdraw our own country allow rules. By default $countries is the list to
     * KEEP; with $invert it is the list to remove and everything else stays.
     * Somebody else's allow rule is theirs to remove either way.
     *
     * @param  list<string>  $countries
     * @return bool whether every withdrawal succeeded
     */
    private function withdrawAllowRules(string $token, array $countries, bool $invert = false): bool
    {
        try {
            foreach ($this->listZones($token) as $zone) {
                $zoneId = (string) $zone['id'];

                foreach ($this->countryAccessRules($token, $zoneId) as $rule) {
                    $country = strtoupper((string) data_get($rule, 'configuration.value'));
                    $named = in_array($country, $countries, true);

                    if ((string) data_get($rule, 'mode') !== 'whitelist'
                        || ($invert ? ! $named : $named)
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

    /** Upsert (or delete) this action's combined country rule on a single zone. */
    private function applyCountryListToZone(string $token, string $zoneId, array $countries, string $mode, string $operation = 'replace'): void
    {
        $ruleset = $this->customRuleset($token, $zoneId);
        $existing = $this->ownedRule($ruleset, $mode);
        $existingId = $existing['id'] ?? null;

        // "remove" is the clean slate: every country rule of ours, whatever its
        // action. Removing one action's list is done by subtracting its countries
        // until nothing is left, which deletes that rule on its own.
        if ($mode === 'remove') {
            foreach ($ruleset['rules'] ?? [] as $rule) {
                if (! str_starts_with((string) ($rule['description'] ?? ''), self::COUNTRY_RULE_DESCRIPTION)) {
                    continue;
                }

                $this->request($token)
                    ->delete(self::BASE."/zones/{$zoneId}/rulesets/{$ruleset['id']}/rules/".$rule['id'])
                    ->throw();
            }

            return;
        }

        // Merged per zone, against what that zone actually holds — so adding a
        // country to a set of zones that had drifted apart adds it to each of
        // them rather than flattening them onto one list.
        $countries = $this->merge($this->countriesIn((string) ($existing['expression'] ?? '')), $countries, $operation);

        // Nothing left to enforce. Keeping an empty rule would be a rule that
        // matches nobody, which reads on the dashboard as if something is set.
        if ($countries === []) {
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
            'description' => $this->ruleDescription($mode),
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

    /**
     * Our own rule for one action inside a ruleset, found by its description.
     *
     * A rule written before actions had their own rule carries the bare
     * description; it belongs to whichever action it is currently performing, so
     * it is adopted rather than orphaned and duplicated.
     *
     * @return array{id: string, expression: string}|null
     */
    private function ownedRule(?array $ruleset, string $mode): ?array
    {
        $legacy = null;

        foreach ($ruleset['rules'] ?? [] as $rule) {
            $description = (string) ($rule['description'] ?? '');

            if ($description === $this->ruleDescription($mode)) {
                return ['id' => (string) $rule['id'], 'expression' => (string) ($rule['expression'] ?? '')];
            }

            if ($description === self::COUNTRY_RULE_DESCRIPTION && (string) ($rule['action'] ?? '') === $mode) {
                $legacy = ['id' => (string) $rule['id'], 'expression' => (string) ($rule['expression'] ?? '')];
            }
        }

        return $legacy;
    }

    /**
     * The country list an operation produces.
     *
     * @param  list<string>  $current
     * @param  list<string>  $given
     * @return list<string>
     */
    private function merge(array $current, array $given, string $operation): array
    {
        $result = match ($operation) {
            'add' => array_unique(array_merge($current, $given)),
            'subtract' => array_diff($current, $given),
            default => $given,
        };

        $result = array_values($result);
        sort($result);

        return $result;
    }

    /**
     * The combined country rule as it stands across the account: which countries
     * it covers, with which action, and on how many zones — so the operator edits
     * the real list instead of retyping it from memory.
     *
     * One entry per action, since each action has its own rule and its own list.
     * An entry is `consistent` only when every zone carries the same list for
     * that action — a run that failed halfway leaves zones disagreeing, and such
     * a list is not safe to preload: re-saving it would quietly restore whichever
     * version happened to be read first onto the zones that already moved on.
     *
     * @return array{ok: bool, message: string, total_zones: int, actions: array<string, array{countries: list<string>, zones: int, consistent: bool}>}
     */
    public function countryListOverview(string $token): array
    {
        $token = trim($token);
        $empty = ['total_zones' => 0, 'actions' => []];

        if ($token === '') {
            return $this->fail('חסר טוקן API של Cloudflare.') + $empty;
        }

        try {
            $zones = $this->listZones($token);
            $seen = [];

            foreach ($zones as $zone) {
                foreach ($this->customRuleset($token, (string) $zone['id'])['rules'] ?? [] as $rule) {
                    $action = (string) ($rule['action'] ?? '');

                    if (! in_array((string) ($rule['description'] ?? ''), [
                        $this->ruleDescription($action), self::COUNTRY_RULE_DESCRIPTION,
                    ], true)) {
                        continue;
                    }

                    $countries = $this->countriesIn((string) ($rule['expression'] ?? ''));
                    sort($countries);

                    $seen[$action]['zones'] = ($seen[$action]['zones'] ?? 0) + 1;
                    // Keyed by content, so zones that agree collapse to one
                    // variant and zones that disagree do not.
                    $seen[$action]['variants'][implode(',', $countries)] = $countries;
                }
            }

            // The allow list lives in access rules, not in the ruleset, but it is
            // the same setting to the operator and belongs in the same picture.
            foreach ($zones as $zone) {
                $allowed = [];

                foreach ($this->countryAccessRules($token, (string) $zone['id']) as $rule) {
                    if ((string) data_get($rule, 'mode') === 'whitelist'
                        && str_contains((string) data_get($rule, 'notes'), 'Multi Digital')) {
                        $allowed[] = strtoupper((string) data_get($rule, 'configuration.value'));
                    }
                }

                if ($allowed !== []) {
                    sort($allowed);
                    $seen['whitelist']['zones'] = ($seen['whitelist']['zones'] ?? 0) + 1;
                    $seen['whitelist']['variants'][implode(',', $allowed)] = $allowed;
                }
            }
        } catch (\Throwable) {
            return $this->fail('הפנייה ל-Cloudflare נכשלה — בדקו את הטוקן והחיבור לרשת.') + $empty;
        }

        $actions = [];

        foreach ($seen as $action => $entry) {
            // A zone MISSING the rule is a disagreement too — that is what a
            // partial removal leaves behind.
            $consistent = count($entry['variants']) === 1 && $entry['zones'] === count($zones);

            $actions[$action] = [
                'countries' => $consistent ? reset($entry['variants']) : [],
                'zones' => $entry['zones'],
                'consistent' => $consistent,
            ];
        }

        ksort($actions);

        return [
            'ok' => true,
            'message' => count($actions).' כללי מדינות פעילים',
            'total_zones' => count($zones),
            'actions' => $actions,
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
