<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The approval/restore bridge for bounded companion-plugin editors.
 * Tool names come exclusively from the catalogue. Expected values and SEO
 * provider come from the site, never from the model or its conversation memory.
 */
class SiteAgentExtendedActions
{
    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    public function handles(string $name): bool
    {
        return isset(SiteAgentExtendedCatalogue::actions()[$name]);
    }

    public function handlesOperation(string $operation): bool
    {
        return in_array($operation, array_column(SiteAgentExtendedCatalogue::actions(), 'operation'), true);
    }

    public function definitions(Site $site): array
    {
        $out = [];
        foreach (SiteAgentExtendedCatalogue::actions() as $name => $spec) {
            if ($this->toolbox->siteHas($site, $spec['read']) && $this->toolbox->siteHas($site, $spec['write'])) {
                $out[] = ['name' => $name, 'description' => $spec['description'] ?? $spec['title'],
                    'input_schema' => ['type' => 'object', 'properties' => $spec['properties'], 'required' => $spec['required']]];
            }
        }

        return $out;
    }

    public function pluginTools(): array
    {
        return array_values(array_unique(array_column(SiteAgentExtendedCatalogue::actions(), 'write')));
    }

    /** A live read builds an exact, bounded preview; no mutation runs here. */
    public function propose(Site $site, string $name, array $input, array $seen): array
    {
        try {
            $spec = SiteAgentExtendedCatalogue::actions()[$name];
            if (! $this->toolbox->siteHas($site, $spec['read']) || ! $this->toolbox->siteHas($site, $spec['write'])) {
                throw new InvalidArgumentException('הפעולה דורשת עדכון של תוסף הסוכן באתר.');
            }
            if (! app(SiteAgentPermissions::class)->allowsTool($name)) {
                throw new InvalidArgumentException(SiteAgentPermissions::refusal());
            }
            $args = $this->identity($spec, $input);
            $values = $this->values($spec, $input['values'] ?? null);
            $create = $name === 'propose_cct_create';
            $cct = str_starts_with($name, 'propose_cct_');

            if ($cct) {
                $reference = $create ? 'cct-type:'.$args['type'] : 'cct:'.$args['type'].':'.$args['id'];
                $this->requireSeen($reference, $seen);
            } elseif (isset($args['id'])) {
                $this->requireSeen($args['id'], $seen);
            }
            // Additional identities (parent, link target, media terms) are also
            // chosen from this turn's live results, not invented by the model.
            foreach (['parent', 'parent_id', 'target_id', 'page_on_front', 'page_for_posts'] as $key) {
                if (isset($values[$key]) && $values[$key] !== 0) {
                    $this->requireSeen($values[$key], $seen);
                }
            }
            foreach ((array) ($values['terms'] ?? []) as $ids) {
                foreach ((array) $ids as $id) {
                    $this->requireSeen($id, $seen);
                }
            }
            if ($name === 'propose_theme_switch') {
                $this->requireSeen('theme:'.($values['stylesheet'] ?? ''), $seen);
            }

            if ($create) {
                $schema = $this->cctSchema($site, $args['type']);
                $values += ['cct_status' => 'draft'];
                $values = $this->validateCct($schema, $values, true);
                $record = ['label' => $schema['label'] ?? $args['type'], 'values' => []];
            } else {
                $readArgs = $args;
                if ($name === 'propose_seo_update') {
                    $providers = array_values(array_filter(['yoast', 'rank_math'], fn (string $provider): bool => in_array('seo:'.$provider.':'.$args['id'], $seen, true)));
                    if (count($providers) === 1) {
                        $readArgs['provider'] = $providers[0];
                    }
                }
                $record = $this->call($site, $spec['read'], $readArgs);
                if (! is_array($record['values'] ?? null)) {
                    throw new InvalidArgumentException('לא התקבלו מהאתר ערכים תקינים לעריכה.');
                }
                if (isset($args['id']) && (int) ($record['id'] ?? 0) !== $args['id']) {
                    throw new InvalidArgumentException('האתר החזיר פריט אחר. יש לחפש שוב.');
                }
                if ($cct) {
                    if (($record['type'] ?? null) !== $args['type']) {
                        throw new InvalidArgumentException('סוג רשומת ה־CCT אינו תואם.');
                    }
                    $values = $this->validateCct($this->cctSchema($site, $args['type']), $values, false, $record['values']);
                }
            }

            if ($name === 'propose_seo_update') {
                $provider = $record['provider'] ?? '';
                if (! in_array($provider, ['yoast', 'rank_math'], true)) {
                    throw new InvalidArgumentException('נדרש תוסף SEO נתמך ופעיל.');
                }
                $args['provider'] = $provider;
            }
            if ($name === 'propose_optimole_update') {
                if (empty($record['active']) || empty($record['connected'])) {
                    throw new InvalidArgumentException('Optimole צריך להיות פעיל ומחובר לפני שינוי ההגדרות.');
                }
                if (array_diff(array_keys($values), (array) ($record['editable_fields'] ?? []))) {
                    throw new InvalidArgumentException('אחת מהגדרות Optimole אינה ניתנת לשינוי באתר הזה.');
                }
                $args['id'] = 1;
            }

            $current = $record['values'];
            $internalLink = $name === 'propose_internal_link';
            if (! $create && ! $internalLink) {
                if (array_diff(array_keys($values), array_keys($current))) {
                    throw new InvalidArgumentException('הבקשה כוללת שדה שאינו ניתן לעריכה.');
                }
                $values = array_filter($values, fn ($value, $key): bool => $value !== $current[$key], ARRAY_FILTER_USE_BOTH);
                if ($values === []) {
                    throw new InvalidArgumentException('הערכים כבר במצב המבוקש; אין מה לשנות.');
                }
            }
            $expected = $internalLink ? ['content' => $current['content']] : array_intersect_key($current, $values);
            if ($name === 'propose_content_manage' && (isset($values['date']) || isset($values['status']))) {
                $expected += array_intersect_key($current, array_flip(['date', 'date_gmt', 'status']));
            }
            if ($name === 'propose_content_manage' && isset($values['date'])) {
                $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $values['date'], new \DateTimeZone((string) ($record['timezone'] ?? '')));
                if (! $local || $local->format('Y-m-d H:i:s') !== $values['date']) {
                    throw new InvalidArgumentException('מועד הפרסום אינו תקין באזור הזמן של האתר.');
                }
                // Bind approval to an exact instant, even if the zone changes.
                $values['date_gmt'] = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if ($name === 'propose_site_settings' && array_intersect(array_keys($values), ['show_on_front', 'page_on_front', 'page_for_posts'])) {
                $expected += array_intersect_key($current, array_flip(['show_on_front', 'page_on_front', 'page_for_posts']));
            }
            if (isset($values['terms'])) {
                $expected['terms'] = array_intersect_key((array) ($current['terms'] ?? []), $values['terms']);
            }

            $label = (string) ($record['label'] ?? $site->domain);
            $lines = [$spec['title'].' — '.$label, 'אתר: '.$site->domain];
            if (! $cct && isset($args['id'])) {
                $lines[] = 'מזהה הפריט: #'.$args['id'];
            }
            if ($cct) {
                $lines[] = 'סוג: '.$args['type'].(isset($args['id']) ? ' · רשומה #'.$args['id'] : ' · רשומה חדשה');
            }
            if ($internalLink) {
                $target = $this->call($site, 'wp_content_get', ['id' => $values['target_id']]);
                if ((int) ($target['id'] ?? 0) !== $values['target_id']) {
                    throw new InvalidArgumentException('עמוד היעד לא נמצא.');
                }
                $lines[] = 'קישור מהטקסט: '.$values['text'];
                $lines[] = 'אל: '.(string) ($target['title'] ?? $target['label'] ?? '').' (#'.$values['target_id'].')';
            } else {
                foreach ($values as $key => $value) {
                    if ($key === 'date_gmt') {
                        continue;
                    }
                    $lines[] = $this->label($key).': '.($create ? '' : $this->display($expected[$key] ?? null).' ← ').$this->display($value);
                }
            }
            if (isset($values['date'])) {
                $lines[] = 'אזור הזמן באתר: '.(string) ($record['timezone'] ?? '');
            }
            if ($create) {
                $lines[] = 'הרשומה תישמר במערכת. ביטול פרסום יחזיר אותה לטיוטה בלבד; שאילתות מותאמות עשויות להציג גם טיוטות.';
            }
            $preview = implode("\n", $lines);
            if (mb_strlen($preview) > 3500) {
                throw new InvalidArgumentException('השינוי ארוך מדי לתצוגת אישור מלאה בוואטסאפ. יש לפצל אותו לשינויים קצרים יותר.');
            }

            return ['plan' => ['operation' => $spec['operation'], 'action' => $name, 'arguments' => $args,
                'values' => $values, 'expected' => $expected, 'target_id' => $args['id'] ?? null,
                'target_title' => $label, 'summary' => $spec['title'].' — '.$label], 'preview' => $preview];
        } catch (\Throwable $e) {
            return ['error' => Str::limit($e->getMessage(), 400)];
        }
    }

    public function apply(Site $site, SiteAgentRequest $request): array
    {
        try {
            $plan = (array) $request->plan;
            $name = (string) ($plan['action'] ?? '');
            $spec = SiteAgentExtendedCatalogue::actions()[$name] ?? null;
            if (! $spec || $spec['operation'] !== $request->operation || ($plan['operation'] ?? '') !== $request->operation) {
                throw new InvalidArgumentException('הצעת השינוי אינה תקינה.');
            }
            if (! app(SiteAgentPermissions::class)->allowsOperation($request->operation)) {
                throw new InvalidArgumentException(SiteAgentPermissions::refusal());
            }
            $args = $this->writeIdentity($spec, (array) ($plan['arguments'] ?? []), $name);
            $values = $this->values($spec, $plan['values'] ?? null, true);
            $create = $name === 'propose_cct_create';
            $result = $this->call($site, $spec['write'], $args + ['values' => $values, 'expected' => (array) ($plan['expected'] ?? [])]);
            if (($result['changed'] ?? false) !== true) {
                throw new InvalidArgumentException(SiteChangeApplier::STALE);
            }
            if ($create) {
                if (empty($result['id']) || ($result['type'] ?? '') !== $args['type'] || ! is_array($result['values'] ?? null)) {
                    throw new InvalidArgumentException('לא התקבל אישור תקין ליצירת הרשומה. בדקו באתר לפני ניסיון נוסף.');
                }
                $restore = ($result['values']['cct_status'] ?? 'draft') === 'publish'
                    ? ['kind' => 'extended_cct_created', 'type' => $args['type'], 'id' => (int) $result['id'], 'after' => $result['values']]
                    : null;
                $request->plan = $plan + ['created_id' => (int) $result['id']];

                return $this->ok($restore, 'הרשומה נוצרה. היא נשמרת במערכת גם אם מחזירים אותה לטיוטה.');
            }
            if (! is_array($result['before'] ?? null) || ! is_array($result['after'] ?? null)
                || $result['before'] === [] || array_diff_key($result['before'], $result['after']) || array_diff_key($result['after'], $result['before'])) {
                throw new InvalidArgumentException('האתר לא החזיר תמונת שחזור תקינה. בדקו את השינוי באתר לפני ניסיון נוסף.');
            }

            return $this->ok(['kind' => 'extended', 'action' => $name, 'arguments' => $args,
                'before' => $result['before'], 'after' => $result['after']]);
        } catch (\Throwable $e) {
            return $this->refuse(Str::limit($e->getMessage(), 400));
        }
    }

    public function revert(Site $site, array $restore): array
    {
        try {
            if (($restore['kind'] ?? '') === 'extended_cct_created') {
                $args = ['type' => $restore['type'], 'id' => (int) $restore['id']];
                $record = $this->call($site, 'jet_cct_get', $args);
                if (($record['values'] ?? null) !== ($restore['after'] ?? null)) {
                    throw new InvalidArgumentException(SiteChangeApplier::STALE);
                }
                $result = $this->call($site, 'jet_cct_update', $args + ['values' => ['cct_status' => 'draft'], 'expected' => $restore['after']]);
            } else {
                $name = (string) ($restore['action'] ?? '');
                $spec = SiteAgentExtendedCatalogue::actions()[$name] ?? null;
                if (! $spec || $name === 'propose_cct_create') {
                    throw new InvalidArgumentException('אין תמונת שחזור תקינה.');
                }
                $args = $this->writeIdentity($spec, (array) ($restore['arguments'] ?? []), $name);
                $result = $this->call($site, $spec['write'], $args + ['values' => (array) ($restore['before'] ?? []), 'expected' => (array) ($restore['after'] ?? [])]);
            }

            return ($result['changed'] ?? false) === true ? $this->ok(null) : $this->refuse(SiteChangeApplier::STALE);
        } catch (\Throwable $e) {
            return $this->refuse(Str::limit($e->getMessage(), 400));
        }
    }

    private function identity(array $spec, array $input): array
    {
        $out = [];
        foreach ($spec['identity'] as $key) {
            $value = $input[$key] ?? null;
            if ($key === 'id') {
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException('נדרש מזהה תקין מקריאה של האתר.');
                }
            } elseif (! is_string($value) || ! preg_match('/^[a-z0-9_-]{1,64}$/D', $value)) {
                throw new InvalidArgumentException('נדרש סוג CCT רשום באתר.');
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function writeIdentity(array $spec, array $input, string $name): array
    {
        $args = $this->identity($spec, $input);
        if ($name === 'propose_seo_update') {
            if (! in_array($input['provider'] ?? '', ['yoast', 'rank_math'], true)) {
                throw new InvalidArgumentException('חסר תוסף SEO לקריאה ולשחזור.');
            }
            $args['provider'] = $input['provider'];
        }
        if ($name === 'propose_optimole_update') {
            $args['id'] = 1;
        }

        return $args;
    }

    private function values(array $spec, mixed $values, bool $trusted = false): array
    {
        $fields = $spec['fields'];
        if ($trusted && $spec['operation'] === 'content_manage') {
            $fields[] = 'date_gmt';
        }
        if (! is_array($values) || $values === [] || array_is_list($values) || count($values) > 100
            || ($fields !== ['*'] && array_diff(array_keys($values), $fields))) {
            throw new InvalidArgumentException('יש לציין רק שדות נתמכים בתוך values.');
        }
        foreach ((array) ($spec['properties']['values']['required'] ?? []) as $key) {
            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException('חסר שדה חובה: '.$key);
            }
        }
        // Plugin validators repeat the exact domain constraints on confirmation.
        // Here reject malformed shapes and schema types before consent is requested.
        $schema = $spec['properties']['values']['properties'] ?? [];
        foreach ($values as $key => $value) {
            if (isset($schema[$key])) {
                $types = (array) ($schema[$key]['type'] ?? []);
                $actual = match (true) {
                    $value === null => 'null', is_int($value) => 'integer', is_float($value) => 'number',
                    is_bool($value) => 'boolean', is_string($value) => 'string', is_array($value) => 'object', default => 'invalid',
                };
                if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
                    throw new InvalidArgumentException('סוג הערך אינו תקין עבור '.$key.'.');
                }
                if (isset($schema[$key]['enum']) && ! in_array($value, $schema[$key]['enum'], true)) {
                    throw new InvalidArgumentException('הערך אינו מותר עבור '.$key.'.');
                }
                if (is_int($value) || is_float($value)) {
                    $minimum = $schema[$key]['minimum'] ?? null;
                    $maximum = $schema[$key]['maximum'] ?? null;
                    if (! is_finite((float) $value) || ($minimum !== null && $value < $minimum) || ($maximum !== null && $value > $maximum)) {
                        $range = $minimum !== null && $maximum !== null ? ' ('.$minimum.'–'.$maximum.')' : '';
                        throw new InvalidArgumentException('הערך מחוץ לטווח המותר עבור '.$key.$range.'.');
                    }
                }
                if (is_string($value)) {
                    // Profile fields and slugs use native byte limits; media
                    // editors use Unicode character counts like their schema.
                    $length = in_array($spec['operation'], ['user_profile', 'content_manage'], true)
                        ? strlen($value) : mb_strlen($value, 'UTF-8');
                    if ((isset($schema[$key]['minLength']) && mb_strlen($value, 'UTF-8') < $schema[$key]['minLength'])
                        || (isset($schema[$key]['maxLength']) && $length > $schema[$key]['maxLength'])) {
                        throw new InvalidArgumentException('אורך הטקסט אינו תקין עבור '.$key.'.');
                    }
                }
            } elseif ($spec['fields'] === ['*'] && ! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('שדות CCT מורכבים אינם נתמכים עדיין.');
            }
        }

        return $values;
    }

    private function cctSchema(Site $site, string $type): array
    {
        $types = $this->call($site, 'jet_cct_types', []);
        foreach ((array) ($types['types'] ?? []) as $schema) {
            if (is_array($schema) && ($schema['type'] ?? $schema['slug'] ?? '') === $type && ! empty($schema['writable'])) {
                return $schema;
            }
        }
        throw new InvalidArgumentException('סוג ה־CCT אינו זמין לעריכה באתר הזה.');
    }

    /** Validate against the live field schema before approval; preserve native switcher representation. */
    private function validateCct(array $schema, array $values, bool $create, array $current = []): array
    {
        if ($create && empty($schema['create_supported'])) {
            throw new InvalidArgumentException('יצירה בסוג ה־CCT הזה אינה נתמכת.');
        }
        $fields = collect((array) ($schema['fields'] ?? []))->keyBy('key')->all();
        $fields['cct_status'] ??= ['writable' => true, 'type' => 'select', 'choices' => ['publish', 'draft'], 'required' => true];
        foreach ($values as $key => $value) {
            if (empty($fields[$key]['writable']) || ($key === 'cct_status' && ! in_array($value, ['publish', 'draft'], true))) {
                throw new InvalidArgumentException('השדה '.$key.' מוגן או אינו נתמך.');
            }
            $field = $fields[$key];
            if ($value === null && empty($field['required'])) {
                continue;
            }
            $type = $field['type'] ?? '';
            if (! is_scalar($value) || (! empty($field['required']) && $value === '')) {
                throw new InvalidArgumentException('חסר ערך תקין לשדה '.($field['label'] ?? $key).'.');
            }
            if ($type === 'switcher') {
                if (! in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                    throw new InvalidArgumentException('שדה '.($field['label'] ?? $key).' מחייב true או false.');
                }
                $enabled = in_array($value, [true, 1, '1', 'true'], true);
                $values[$key] = in_array($current[$key] ?? null, ['true', 'false'], true)
                    ? ($enabled ? 'true' : 'false') : $enabled;

                continue;
            }
            if ($type === 'number') {
                if (is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value)
                    || (isset($field['min']) && is_numeric($field['min']) && (float) $value < (float) $field['min'])
                    || (isset($field['max']) && is_numeric($field['max']) && (float) $value > (float) $field['max'])) {
                    throw new InvalidArgumentException('השדה '.($field['label'] ?? $key).' מחייב מספר בטווח שהוגדר באתר.');
                }

                continue;
            }
            if (! is_string($value) || strlen($value) > 50000) {
                throw new InvalidArgumentException('השדה '.($field['label'] ?? $key).' מחייב טקסט עד 50,000 בתים.');
            }
            if (in_array($type, ['select', 'radio'], true) && ! in_array($value, (array) ($field['choices'] ?? []), true)) {
                throw new InvalidArgumentException('הערך אינו אחת האפשרויות המוגדרות לשדה '.($field['label'] ?? $key).'.');
            }
            if (in_array($type, ['date', 'datetime-local', 'time'], true) && $value !== '') {
                $format = ['date' => 'Y-m-d', 'datetime-local' => 'Y-m-d\\TH:i', 'time' => 'H:i'][$type];
                $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
                if (! $date || $date->format($format) !== $value) {
                    throw new InvalidArgumentException('תאריך או שעה אינם תקינים בשדה '.($field['label'] ?? $key).'.');
                }
            }
            if ($type === 'colorpicker' && $value !== '' && ! preg_match('/^#[a-fA-F0-9]{6}$/D', $value)) {
                throw new InvalidArgumentException('צבע חייב להיות בפורמט #RRGGBB.');
            }
        }
        if ($create) {
            foreach ($fields as $key => $field) {
                if (! empty($field['required']) && ! array_key_exists($key, $values)) {
                    throw new InvalidArgumentException('חסר שדה חובה: '.($field['label'] ?? $key));
                }
            }
        }

        return $values;
    }

    private function requireSeen(mixed $id, array $seen): void
    {
        if (! in_array($id, $seen, true)) {
            throw new InvalidArgumentException('יש לקרוא את הפריט והסוג המדויקים בכלי קריאה בסבב הנוכחי לפני הכנת הצעה.');
        }
    }

    private function call(Site $site, string $tool, array $args): array
    {
        $result = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $args)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new InvalidArgumentException('האתר החזיר תשובה לא תקינה.');
        }

        return $result;
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => 'ברירת מחדל', $value === '' => '(ריק)', is_bool($value) => $value ? 'כן' : 'לא',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), default => (string) $value,
        };
    }

    private function label(string $key): string
    {
        return ['title' => 'כותרת', 'description' => 'תיאור', 'caption' => 'כיתוב', 'alt' => 'טקסט חלופי',
            'status' => 'מצב', 'cct_status' => 'מצב', 'date' => 'מועד פרסום', 'parent' => 'עמוד אב',
            'parent_id' => 'פריט משויך', 'menu_order' => 'סדר', 'slug' => 'כתובת קצרה', 'terms' => 'שיוך לקטגוריות',
            'stylesheet' => 'תבנית', 'blogname' => 'שם האתר', 'blogdescription' => 'תיאור האתר',
            'display_name' => 'שם תצוגה', 'first_name' => 'שם פרטי', 'last_name' => 'שם משפחה',
            'quality' => 'איכות תמונה'][$key] ?? $key;
    }

    private function ok(?array $restore, ?string $done = null): array
    {
        return array_filter(['ok' => true, 'reason' => null, 'message' => null, 'restore' => $restore, 'done' => $done],
            fn ($value, $key): bool => $key !== 'done' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $reason, 'restore' => null];
    }
}
