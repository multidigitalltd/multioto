<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Native ACF edits are validated and sealed on WordPress before asking for consent. */
class SiteAgentAcfActions
{
    public const TOOL = 'propose_acf_update';

    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    public static function selectors(): array
    {
        return ['context' => ['type' => 'string', 'enum' => ['post', 'user', 'term', 'options'], 'description' => 'שדה בעמוד/פוסט/מוצר/מדיה: post עם id. ״בבית״ או ״בדף הבית״ פירושם post עם page_on_front מקריאת get_site_settings; אין לנחש מזהה. options רק עבור עמוד אפשרויות גלובלי רשום, לא עבור דף הבית או כל עמוד תוכן אחר.'],
            'id' => ['type' => 'integer', 'minimum' => 1], 'options_page' => ['type' => 'string'],
            'field_key' => ['type' => 'string', 'description' => 'מפתח field_ המדויק מתוך סכמת ACF.']];
    }

    public static function reads(): array
    {
        return [
            'acf_schema' => ['wp_acf_schema', 'סכמת ACF ו-ACF Pro במיקום העריכה: פוסט/מוצר/מדיה (post), משתמש (user), מונח (term) או עמוד אפשרויות רשום (options). לשדה בעמוד מסוים מצאו את מזהה העמוד וקראו context=post,id; אין צורך לחפש בעמודי האפשרויות. כוללת שדות מקוננים, פריסות, Clone ואפשרויות חוקיות. ניתן לצמצם לשדה אחד באמצעות field_key.', self::selectors(), ['context']],
            'get_acf' => ['wp_acf_get', 'ערכי ACF גולמיים וסכמת השדות לפי מפתחות field_; סיסמאות מוסתרות. שורות ממוספרות מאפס. שדה בעמוד, לרבות ״בבית״/״בדף הבית״, נקרא עם context=post,id; אין צורך בקריאת list_acf_options. דף הבית מזוהה לפי page_on_front מתוך get_site_settings. ניתן לקרוא את כל השדות בלי field_key ולבחור מהתוצאה; התוצאה כבר כוללת סכמה ולכן אין צורך בקריאת acf_schema נוספת. אחרי שזוהו השדה והערך המבוקש קראו מיד propose_acf_update; אם חסר הערך שאלו רק אותו.', self::selectors(), ['context']],
            'list_acf_options' => ['wp_acf_options_pages', 'עמודי אפשרויות ACF גלובליים הרשומים באתר. קראו רק כשהבקשה נוגעת להגדרות גלובליות/עמוד אפשרויות ולפני בחירת options_page. לשדות של עמוד תוכן, לרבות דף הבית, השתמשו ב-context=post ובמזהה העמוד; כלי זה אינו נדרש.', [], []],
        ];
    }

    public function definitions(Site $site): array
    {
        if (! $this->available($site)) {
            return [];
        }

        return [['name' => self::TOOL, 'description' => 'מכין הצעת עריכת ACF ו-ACF Pro ומחזיר תצוגה מאומתת לאישור, בלי לבצע את השינוי. לאחר get_acf קראו לכלי זה מיד; אין לבקש אישור לפני הקריאה. path יחסי לשדה שנבחר ב-field_key ואינו כולל את אותו מפתח שוב. דוגמאות: טקסט/מספר: {"op":"set","path":[],"value":0}; תא Repeater/Flexible: {"op":"set","path":[0,"field_title"],"value":"חדש"}; Clone/Group: {"op":"set","path":["field_phone"],"value":"03-1234567"}; הוספת שורה/תמונת גלריה: {"op":"insert","path":[],"index":1,"value":91}; הסרה: {"op":"remove","path":[],"index":0}; סידור: {"op":"move","path":[],"index":1,"to":0}. ב-Flexible שורה חדשה כוללת acf_fc_layout בשם הפריסה מתוך הסכמה. להסרת כל תמונות הגלריה השתמשו ב-clear עם path=[]; הדבר אינו מוחק קבצי מדיה. סיסמאות מוסתרות; Tab/Accordion/Message הם מבנה טופס בלבד.',
            'input_schema' => ['type' => 'object', 'properties' => self::selectors() + [
                'operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => [
                    'type' => 'object', 'properties' => [
                        'op' => ['type' => 'string', 'enum' => ['set', 'clear', 'insert', 'remove', 'move']],
                        'path' => ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]], 'description' => '[] לשדה הראשי, ללא field_key. set/clear על תא: [0,"field_title"]. בפעולות insert/remove/move הנתיב מצביע לרשימה, ואת מספר השורה מציינים ב-index בנפרד; רשימה ראשית היא path=[].'],
                        'value' => ['description' => 'ערך מהסוג שהסכמה דורשת. שדות ילד לפי מפתח field_, לא לפי תווית.'],
                        'index' => ['type' => 'integer', 'minimum' => 0, 'description' => 'חובה ב-insert/remove/move: אינדקס שורה/תמונה מאפס בתוך הרשימה שב-path. להוספה בסוף, index שווה למספר הפריטים שנקראו.'],
                        'to' => ['type' => 'integer', 'minimum' => 0, 'description' => 'חובה ב-move: האינדקס החדש של השורה לאחר העברתה, מאפס.'],
                    ], 'required' => ['op', 'path'], 'additionalProperties' => false,
                ]],
            ], 'required' => ['context', 'field_key', 'operations'], 'additionalProperties' => false]]];
    }

    public static function pluginTools(): array
    {
        return ['wp_acf_prepare', 'wp_acf_update'];
    }

    public static function reference(array $target, string $field): string
    {
        return 'acf:'.($target['context'] ?? '').':'.(($target['context'] ?? '') === 'options' ? ($target['options_page'] ?? '') : ($target['id'] ?? '')).':'.$field;
    }

    public function propose(Site $site, array $input, array $seen): array
    {
        try {
            if (! $this->available($site)) {
                throw new InvalidArgumentException('עריכת ACF דורשת עדכון של תוסף הסוכן באתר.');
            }
            $args = $this->selector($input);
            if (! in_array(self::reference($args, $args['field_key']), $seen, true)) {
                throw new InvalidArgumentException('קראו קודם get_acf עבור המיקום והשדה המדויקים בסבב הנוכחי.');
            }
            $operations = $input['operations'] ?? null;
            if (! is_array($operations) || ! array_is_list($operations) || count($operations) < 1 || count($operations) > 30) {
                throw new InvalidArgumentException('נדרשות 1–30 פעולות ממוקדות.');
            }
            $operations = array_map(function ($operation) use ($args): array {
                if (! is_array($operation) || ! in_array($operation['op'] ?? null, ['set', 'clear', 'insert', 'remove', 'move'], true)
                    || ! is_array($operation['path'] ?? null) || ! array_is_list($operation['path']) || count($operation['path']) > 16) {
                    throw new InvalidArgumentException('פעולת השדה או הנתיב אינם תקינים.');
                }

                return $this->normalizeOperation($operation, $args['field_key']);
            }, $operations);
            $read = $this->call($site, 'wp_acf_get', $args);
            $field = collect($read['fields'] ?? [])->firstWhere('key', $args['field_key']);
            $expected = $read['snapshots'][$args['field_key']] ?? null;
            if (! is_array($field) || ! $this->snapshot($expected)
                || self::reference((array) ($read['target'] ?? []), $args['field_key']) !== self::reference($args, $args['field_key'])) {
                throw new InvalidArgumentException('האתר לא החזיר שדה וצילום מצב תואמים.');
            }
            $offer = $this->call($site, 'wp_acf_prepare', $args + ['operations' => $operations, 'expected' => $expected]);
            if (($offer['changed'] ?? false) !== true) {
                throw new InvalidArgumentException('השדה כבר במצב המבוקש.');
            }
            if (! $this->snapshot($offer['prepared'] ?? null) || ($offer['expected'] ?? null) !== $expected
                || ($offer['field_key'] ?? null) !== $args['field_key']
                || self::reference((array) ($offer['target'] ?? []), $args['field_key']) !== self::reference($args, $args['field_key'])) {
                throw new InvalidArgumentException('לא התקבלה הצעה חתומה תקינה.');
            }
            $label = (string) ($read['target']['label'] ?? $site->domain);
            $title = (string) ($field['label'] ?? $args['field_key']);
            $changes = [];
            $labels = [];
            $this->fieldLabels($field, $labels);
            $this->differences($offer['before'] ?? null, $offer['after'] ?? null, $title, $changes, $labels);
            if ($changes === []) {
                $changes[] = $title.': עדכון ערך מוסתר.';
            }
            $lines = ['עדכון ACF — '.$label, 'אתר: '.$site->domain, ...$changes];
            foreach ((array) ($offer['notes'] ?? []) as $note) {
                if (is_string($note)) {
                    $lines[] = $note;
                }
            }
            $preview = implode("\n", $lines);
            if (mb_strlen($preview) > 3500) {
                throw new InvalidArgumentException('השינוי גדול מדי לתצוגת אישור מלאה. פצלו אותו לשינוי של שדה או שורה אחת בכל פעם.');
            }

            return ['plan' => ['operation' => SiteAgentRequest::OP_ACF, 'arguments' => $args,
                'expected' => $offer['expected'], 'prepared' => $offer['prepared'],
                'summary' => 'עדכון '.$title.' ב'.$label, 'target_title' => $label,
                'acf_field_label' => $title,
                'fields' => ['content' => is_string($offer['writing_text'] ?? null) ? $offer['writing_text'] : '']], 'preview' => $preview];
        } catch (\Throwable $e) {
            return ['error' => Str::limit($e->getMessage(), 400)];
        }
    }

    /** Accept unambiguous model addressing aliases; WordPress still validates the schema and seals the exact preview. */
    private function normalizeOperation(array $operation, string $fieldKey): array
    {
        $operation = array_intersect_key($operation, array_flip(['op', 'path', 'value', 'index', 'to']));
        if (($operation['path'][0] ?? null) === $fieldKey) {
            array_shift($operation['path']);
        }
        if (in_array($operation['op'], ['insert', 'remove', 'move'], true)) {
            if (! array_key_exists('index', $operation) && is_int(end($operation['path']))) {
                $operation['index'] = array_pop($operation['path']);
            }
            if (! is_int($operation['index'] ?? null) || $operation['index'] < 0) {
                throw new InvalidArgumentException('ב-insert/remove/move חובה לציין index מאפס. path מצביע לרשימה ([] לרשימה הראשית), ולא לשורה.');
            }
            if ($operation['op'] === 'move' && (! is_int($operation['to'] ?? null) || $operation['to'] < 0)) {
                throw new InvalidArgumentException('ב-move חובה לציין to: אינדקס היעד החדש מאפס.');
            }
        }

        return $operation;
    }

    public function apply(Site $site, SiteAgentRequest $request): array
    {
        try {
            if (! $this->available($site)) {
                throw new InvalidArgumentException('עריכת ACF דורשת עדכון של תוסף הסוכן באתר.');
            }
            if (! app(SiteAgentPermissions::class)->allowsOperation(SiteAgentRequest::OP_ACF)) {
                throw new InvalidArgumentException(SiteAgentPermissions::refusal());
            }
            $plan = (array) $request->plan;
            if (($plan['operation'] ?? null) !== SiteAgentRequest::OP_ACF || ! $this->snapshot($plan['expected'] ?? null) || ! $this->snapshot($plan['prepared'] ?? null)) {
                throw new InvalidArgumentException('הצעת ACF אינה תקינה.');
            }
            $args = $this->selector((array) ($plan['arguments'] ?? []));
            $result = $this->call($site, 'wp_acf_update', $args + ['expected' => $plan['expected'], 'prepared' => $plan['prepared']]);
            if (($result['changed'] ?? false) !== true || ! $this->snapshot($result['before'] ?? null) || ! $this->snapshot($result['after'] ?? null)) {
                throw new InvalidArgumentException('לא התקבל אישור שינוי עם מידע תקין לשחזור. יש לקרוא את השדה מחדש.');
            }

            return $this->ok(['kind' => 'acf', 'arguments' => $args, 'before' => $result['before'], 'after' => $result['after']]);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function revert(Site $site, array $restore): array
    {
        try {
            if (! $this->available($site)) {
                throw new InvalidArgumentException('עריכת ACF דורשת עדכון של תוסף הסוכן באתר.');
            }
            if (! app(SiteAgentPermissions::class)->allowsOperation(SiteAgentRequest::OP_ACF)) {
                throw new InvalidArgumentException(SiteAgentPermissions::refusal());
            }
            if (! $this->snapshot($restore['before'] ?? null) || ! $this->snapshot($restore['after'] ?? null)) {
                throw new InvalidArgumentException('אין צילום שחזור תקין לשדה.');
            }
            $args = $this->selector((array) ($restore['arguments'] ?? []));
            $result = $this->call($site, 'wp_acf_update', $args + ['expected' => $restore['after'], 'restore' => $restore['before']]);
            if (($result['changed'] ?? false) !== true) {
                throw new InvalidArgumentException(SiteChangeApplier::STALE);
            }

            return $this->ok(null);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private function selector(array $input): array
    {
        $context = $input['context'] ?? null;
        $key = $input['field_key'] ?? null;
        if (! in_array($context, ['post', 'user', 'term', 'options'], true) || ! is_string($key) || ! preg_match('/^field_[a-zA-Z0-9_-]{1,190}$/D', $key)) {
            throw new InvalidArgumentException('נדרשים מיקום ומפתח שדה תקינים.');
        }
        $out = ['context' => $context, 'field_key' => $key];
        if ($context === 'options') {
            if (! is_string($input['options_page'] ?? null) || $input['options_page'] === '' || strlen($input['options_page']) > 190) {
                throw new InvalidArgumentException('יש לבחור עמוד אפשרויות רשום.');
            }
            $out['options_page'] = $input['options_page'];
        } else {
            if (! is_int($input['id'] ?? null) || $input['id'] < 1) {
                throw new InvalidArgumentException('חסר מזהה פריט תקין.');
            }
            $out['id'] = $input['id'];
        }

        return $out;
    }

    private function available(Site $site): bool
    {
        foreach (['wp_acf_get', ...self::pluginTools()] as $tool) {
            if (! $this->toolbox->siteHas($site, $tool)) {
                return false;
            }
        }

        return true;
    }

    private function snapshot(mixed $value): bool
    {
        return is_array($value) && is_string($value['version'] ?? null) && is_string($value['token'] ?? null)
            && $value['version'] !== '' && $value['token'] !== '' && strlen($value['token']) < 1000000;
    }

    private function call(Site $site, string $tool, array $args): array
    {
        $value = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $args)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new InvalidArgumentException('האתר החזיר תשובה לא תקינה.');
        }

        return $value;
    }

    /** Show every changed leaf; never silently truncate the approved change. */
    private function differences(mixed $before, mixed $after, string $path, array &$lines, array $labels, int $depth = 0): void
    {
        if ($before === $after) {
            return;
        }
        if ($depth > 16 || count($lines) > 50) {
            throw new InvalidArgumentException('יש לפצל את השינוי הגדול לשינויים ממוקדים.');
        }
        if (is_array($before) && is_array($after) && ! isset($before['redacted']) && ! isset($after['redacted'])) {
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                $this->differences($before[$key] ?? null, $after[$key] ?? null, $path.' / '.(is_int($key) ? 'שורה '.($key + 1) : ($labels[$key] ?? ($key === 'acf_fc_layout' ? 'פריסה' : $key))), $lines, $labels, $depth + 1);
            }

            return;
        }
        $lines[] = $path.': '.$this->display($before).' ← '.$this->display($after);
    }

    private function fieldLabels(array $field, array &$labels, int $depth = 0): void
    {
        if ($depth > 16) {
            return;
        }
        if (is_string($field['key'] ?? null) && is_string($field['label'] ?? null)) {
            $labels[$field['key']] = $field['label'];
        }
        foreach ((array) ($field['sub_fields'] ?? []) as $child) {
            if (is_array($child)) {
                $this->fieldLabels($child, $labels, $depth + 1);
            }
        }
        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            if (is_array($layout)) {
                $this->fieldLabels($layout, $labels, $depth + 1);
            }
        }
    }

    private function display(mixed $value): string
    {
        if (is_array($value) && isset($value['redacted'])) {
            return '(ערך מוסתר)';
        }

        return match (true) {
            $value === null => '(אין ערך)', $value === '' => '(ריק)', is_bool($value) => $value ? 'כן' : 'לא',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), default => (string) $value,
        };
    }

    private function ok(?array $restore): array
    {
        return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => $restore];
    }

    private function failure(\Throwable $error): array
    {
        $message = Str::limit($error->getMessage(), 400);

        return ['ok' => false, 'reason' => $message, 'message' => $message, 'restore' => null];
    }
}
