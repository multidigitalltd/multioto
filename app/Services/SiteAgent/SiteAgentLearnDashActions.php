<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** LearnDash membership changes preserve access sources, progress and sealed undo state. */
class SiteAgentLearnDashActions
{
    public const TOOL = 'propose_ld_membership';

    public const CATALOG_READS = ['get_ld_capabilities', 'find_ld_courses', 'get_ld_course', 'find_ld_groups'];

    public const STUDENT_READS = ['get_ld_student_course', 'get_ld_group', 'get_ld_membership'];

    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    public static function selectors(): array
    {
        return ['user_id' => ['type' => 'integer', 'minimum' => 1],
            'kind' => ['type' => 'string', 'enum' => ['course', 'group']],
            'target_id' => ['type' => 'integer', 'minimum' => 1]];
    }

    public static function reads(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $page = ['page' => ['type' => 'integer', 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]];

        return [
            'get_ld_capabilities' => ['ld_capabilities', 'יכולות LearnDash באתר ומגבלות הגרסה. יש לבדוק לפני הצעת פעולות רישום. אין איפוס התקדמות או שינוי תשלומי קורס.', [], []],
            'find_ld_courses' => ['ld_courses_list', 'חיפוש קורסי LearnDash: מזהים, כותרות ומצב. יש לדפדף לפי has_more ולא להסיק שהעמוד הראשון מכיל את כל הקורסים.', $page + ['search' => ['type' => 'string']], []],
            'get_ld_course' => ['ld_course_get', 'קורס LearnDash ומבנה השיעורים והנושאים שלו. לא משנה תוכן או רישום תלמידים.', ['course_id' => $id], ['course_id']],
            'get_ld_student_course' => ['ld_student_course_get', 'מצב הגישה וההתקדמות של תלמיד בקורס, לקריאה בלבד. user_id מתוך find_users; אין להציג אימיילים או סודות.', ['user_id' => $id, 'course_id' => $id], ['user_id', 'course_id']],
            'find_ld_groups' => ['ld_groups_list', 'קבוצות LearnDash, מזהים, כותרות ומצב. אין פרטי תלמידים ברשימה. יש לעקוב אחרי has_more.', $page + ['search' => ['type' => 'string']], []],
            'get_ld_group' => ['ld_group_get', 'קבוצה, הקורסים המשויכים ורשימת תלמידים מדופדפת ללא אימיילים. שיוך קורסים לקבוצה הוא לקריאה בלבד.', ['group_id' => $id] + $page, ['group_id']],
            'get_ld_membership' => ['ld_membership_get', 'רישום ישיר של תלמיד לקורס או חברות בקבוצה, כולל מקורות גישה נוספים, השפעה על קורסים והאם עריכה אפשרית. חובה לקרוא את אותו תלמיד ויעד בסבב הנוכחי לפני הצעה.', self::selectors(), ['user_id', 'kind', 'target_id']],
        ];
    }

    public static function pluginTools(): array
    {
        return ['ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert'];
    }

    public static function reference(array $selector): string
    {
        return 'ld-membership:'.($selector['kind'] ?? '').':'.($selector['target_id'] ?? '').':'.($selector['user_id'] ?? '');
    }

    public function definitions(Site $site): array
    {
        if (! $this->available($site) || ! app(SiteAgentPermissions::class)->allowsTool(self::TOOL)) {
            return [];
        }

        return [['name' => self::TOOL,
            'description' => 'הצעה להוסיף או להסיר רישום ישיר לקורס LearnDash או חברות בקבוצה. קראו get_ld_membership לאותו תלמיד, סוג ויעד בסבב הנוכחי. action=add/remove. הסרת רישום ישיר אינה מסירה גישה שמגיעה מקבוצה או ממקור אחר. אין איפוס התקדמות, תשלומים, מבחנים או שיוך קורס לקבוצה.',
            'input_schema' => ['type' => 'object', 'properties' => self::selectors() + ['action' => ['type' => 'string', 'enum' => ['add', 'remove']]],
                'required' => ['user_id', 'kind', 'target_id', 'action'], 'additionalProperties' => false]]];
    }

    /** The model receives safe public facts, never native restoration tokens or private mail fields. */
    public static function modelRead(string $tool, mixed $data, array $arguments): array
    {
        try {
            if (! is_array($data)) {
                throw new InvalidArgumentException('האתר החזיר מידע LearnDash לא תקין.');
            }
            if (($tool === 'ld_capabilities' && ! is_bool($data['active'] ?? null))
                || ($tool === 'ld_courses_list' && (! is_array($data['courses'] ?? null) || ! array_is_list($data['courses'])))
                || ($tool === 'ld_groups_list' && (! is_array($data['groups'] ?? null) || ! array_is_list($data['groups'])))) {
                throw new InvalidArgumentException('האתר החזיר תשובת LearnDash חסרה.');
            }
            $ids = [];
            if ($tool === 'ld_membership_get') {
                $selector = self::selector($arguments);
                if (! self::matches($data, $selector)) {
                    throw new InvalidArgumentException('האתר החזיר תלמיד או יעד שאינם תואמים לבקשה.');
                }
                $ids[] = self::reference($selector);
            } elseif ($tool === 'ld_courses_list' || $tool === 'ld_groups_list') {
                $key = $tool === 'ld_courses_list' ? 'courses' : 'groups';
                foreach ((array) ($data[$key] ?? []) as $item) {
                    if (is_array($item) && is_int($item['id'] ?? null) && $item['id'] > 0) {
                        $ids[] = ($key === 'courses' ? 'ld-course:' : 'ld-group:').$item['id'];
                    }
                }
            } elseif ($tool === 'ld_course_get' || $tool === 'ld_student_course_get') {
                if (! is_int($arguments['course_id'] ?? null) || ($data['course']['id'] ?? null) !== $arguments['course_id']
                    || ($tool === 'ld_student_course_get' && (! is_int($arguments['user_id'] ?? null) || ($data['user']['id'] ?? null) !== $arguments['user_id']))) {
                    throw new InvalidArgumentException('האתר החזיר תלמיד או קורס שאינם תואמים לבקשה.');
                }
                if ($tool === 'ld_course_get') {
                    $ids[] = 'ld-course:'.$arguments['course_id'];
                }
            } elseif ($tool === 'ld_group_get') {
                if (! is_int($arguments['group_id'] ?? null) || ($data['group']['id'] ?? null) !== $arguments['group_id']) {
                    throw new InvalidArgumentException('האתר החזיר קבוצה שאינה תואמת לבקשה.');
                }
                $ids[] = 'ld-group:'.$arguments['group_id'];
            }
            $text = json_encode(self::publicData($tool, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (mb_strlen($text) > 24000) {
                throw new InvalidArgumentException('מידע LearnDash גדול מדי לתצוגה מלאה. צמצמו את הקריאה או את מספר הפריטים בעמוד.');
            }

            return ['content' => $text, 'is_error' => false, 'ids' => $ids];
        } catch (\Throwable $e) {
            return ['content' => Str::limit($e->getMessage(), 300), 'is_error' => true, 'ids' => []];
        }
    }

    private static function publicData(string $tool, array $data): array
    {
        $post = ['id' => 'int', 'title' => 'string', 'type' => 'string', 'status' => 'string'];
        $user = ['id' => 'int', 'display_name' => 'string'];
        $state = ['direct_member' => 'bool?', 'effective_access' => 'bool?', 'access_sources' => ['*' => 'string'], 'access_from' => 'int?', 'expires_at' => 'int?'];
        $pagination = ['page' => 'int', 'limit' => 'int', 'has_more' => 'bool'];
        $schema = match ($tool) {
            'ld_capabilities' => ['active' => 'bool', 'version' => 'string?', 'course_membership' => ['available' => 'bool', 'reason' => 'string?'],
                'group_membership' => ['available' => 'bool', 'reason' => 'string?'], 'progress_available' => 'bool', 'hierarchy_available' => 'bool', 'limitations' => ['*' => 'string']],
            'ld_courses_list' => ['courses' => ['*' => $post]] + $pagination,
            'ld_groups_list' => ['groups' => ['*' => $post]] + $pagination,
            'ld_course_get' => ['course' => $post, 'hierarchy_available' => 'bool', 'reason' => 'string?',
                'hierarchy' => ['lessons' => ['*' => $post + ['topics' => ['*' => $post]]], 'steps' => ['*' => $post]]],
            'ld_student_course_get' => ['user' => $user, 'course' => $post, 'progress' => ['total' => 'int', 'completed' => 'int', 'percentage' => 'number'], 'access' => $state],
            'ld_group_get' => ['group' => $post, 'courses' => ['*' => $post], 'members' => ['*' => $user], 'members_available' => 'bool', 'reason' => 'string?'] + $pagination,
            'ld_membership_get' => ['selector' => ['user_id' => 'int', 'kind' => 'string', 'target_id' => 'int'], 'user' => $user, 'target' => $post, 'state' => $state,
                'writable' => 'bool', 'reason' => 'string?', 'impacts' => ['*' => ['course_id' => 'int', 'title' => 'string', 'effective_access' => 'bool?']]],
            default => throw new InvalidArgumentException('כלי LearnDash לא מוכר.'),
        };

        return self::project($data, $schema);
    }

    /** Only documented public keys and their expected types may leave the native boundary. */
    private static function project(mixed $value, array|string $schema, int $depth = 0): mixed
    {
        if ($depth > 12) {
            throw new InvalidArgumentException('תשובת LearnDash מורכבת מדי.');
        }
        if (is_string($schema)) {
            if ($value === null && str_ends_with($schema, '?')) {
                return null;
            }
            $valid = match (rtrim($schema, '?')) {
                'int' => is_int($value), 'bool' => is_bool($value), 'string' => is_string($value),
                'number' => is_int($value) || is_float($value), default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('האתר החזיר מידע LearnDash מסוג שאינו תקין.');
            }

            return is_string($value) ? self::safeText($value) : $value;
        }
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('האתר החזיר מבנה LearnDash לא תקין.');
        }
        if (array_key_exists('*', $schema)) {
            if (! array_is_list($value) || count($value) > 200) {
                throw new InvalidArgumentException('רשימת LearnDash אינה תקינה או גדולה מדי.');
            }

            return array_map(fn ($row) => self::project($row, $schema['*'], $depth + 1), $value);
        }
        $out = [];
        foreach ($schema as $key => $type) {
            if (array_key_exists($key, $value)) {
                $out[$key] = self::project($value[$key], $type, $depth + 1);
            }
        }

        return $out;
    }

    public function propose(Site $site, array $input, array $seen): array
    {
        try {
            $this->assertAllowed($site);
            $selector = self::selector($input);
            $action = $input['action'] ?? null;
            if (! in_array($action, ['add', 'remove'], true)) {
                throw new InvalidArgumentException('יש לבחור add או remove.');
            }
            if (! in_array(self::reference($selector), $seen, true)) {
                throw new InvalidArgumentException('קראו קודם get_ld_membership עבור אותו תלמיד ואותו יעד בסבב הנוכחי.');
            }
            $read = $this->call($site, 'ld_membership_get', $selector);
            if (! self::matches($read, $selector) || ! $this->snapshot($read['snapshot'] ?? null)) {
                throw new InvalidArgumentException('לא התקבל צילום מצב תואם של רישום התלמיד.');
            }
            if (($read['writable'] ?? false) !== true || ! $this->state($read['state'] ?? null)) {
                throw new InvalidArgumentException(is_string($read['reason'] ?? null) ? Str::limit(self::safeText($read['reason']), 300) : 'מצב הרישום הזה זמין לקריאה בלבד ואינו ניתן לשינוי בטוח דרך הבוט.');
            }
            if ($read['state']['direct_member'] === ($action === 'add')) {
                throw new InvalidArgumentException('התלמיד כבר במצב הרישום הישיר המבוקש.');
            }
            $offer = $this->call($site, 'ld_membership_prepare', $selector + ['action' => $action, 'expected' => $read['snapshot']]);
            if (($offer['changed'] ?? false) !== true || ! self::matches($offer, $selector)
                || ($offer['expected'] ?? null) !== $read['snapshot'] || ! $this->snapshot($offer['prepared'] ?? null)
                || ! $this->state($offer['before'] ?? null) || ! $this->state($offer['after'] ?? null)
                || $offer['before'] !== $read['state'] || $offer['after']['direct_member'] !== ($action === 'add')
                || $offer['after']['expires_at'] !== $offer['before']['expires_at']) {
                throw new InvalidArgumentException('הצעת רישום התלמיד אינה תואמת למצב שנקרא.');
            }
            $this->validateImpacts($read, $offer);
            $user = self::safeText((string) ($read['user']['display_name'] ?? '#'.$selector['user_id']));
            $target = self::safeText((string) ($read['target']['title'] ?? '#'.$selector['target_id']));
            $preview = $this->preview($site, $selector, $action, $user, $target, $offer);
            if (mb_strlen($preview) > 3500) {
                throw new InvalidArgumentException('ההשפעה על הקורסים גדולה מדי להצעת אישור מלאה בוואטסאפ. יש לבצע את שינוי החברות דרך ניהול LearnDash.');
            }

            return ['plan' => ['operation' => SiteAgentRequest::OP_LEARNDASH_MEMBERSHIP, 'arguments' => $selector,
                'expected' => $offer['expected'], 'prepared' => $offer['prepared'], 'action' => $action,
                'summary' => ($action === 'add' ? 'הוספת' : 'הסרת').' רישום LearnDash: '.$user.' — '.$target,
                'target_title' => $target, 'student_name' => $user], 'preview' => $preview];
        } catch (\Throwable $e) {
            return ['error' => Str::limit($e->getMessage(), 400)];
        }
    }

    public function apply(Site $site, SiteAgentRequest $request): array
    {
        try {
            $this->assertAllowed($site);
            $plan = (array) $request->plan;
            if (($plan['operation'] ?? null) !== SiteAgentRequest::OP_LEARNDASH_MEMBERSHIP
                || ! $this->snapshot($plan['expected'] ?? null) || ! $this->snapshot($plan['prepared'] ?? null)) {
                throw new InvalidArgumentException('הצעת LearnDash אינה תקינה.');
            }
            $selector = self::selector((array) ($plan['arguments'] ?? []));
            $result = $this->call($site, 'ld_membership_apply', $selector + ['expected' => $plan['expected'], 'prepared' => $plan['prepared']]);
            if (($result['changed'] ?? false) !== true || ! self::sameSelector($result['selector'] ?? null, $selector)
                || ($result['expected'] ?? null) !== $plan['expected']
                || ! $this->snapshot($result['before'] ?? null) || ! $this->snapshot($result['after'] ?? null)) {
                throw new InvalidArgumentException('לא התקבל אישור שינוי LearnDash עם מידע תקין לשחזור.');
            }

            return ['ok' => true, 'reason' => null, 'message' => null,
                'restore' => ['kind' => 'learndash_membership', 'arguments' => $selector, 'before' => $result['before'], 'after' => $result['after']]];
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function revert(Site $site, array $restore): array
    {
        try {
            $this->assertAllowed($site);
            if (! $this->snapshot($restore['before'] ?? null) || ! $this->snapshot($restore['after'] ?? null)) {
                throw new InvalidArgumentException('אין צילום שחזור תקין לרישום התלמיד.');
            }
            $selector = self::selector((array) ($restore['arguments'] ?? []));
            $result = $this->call($site, 'ld_membership_revert', $selector + ['expected' => $restore['after'], 'restore' => $restore['before']]);
            if (($result['changed'] ?? false) !== true || ! self::sameSelector($result['selector'] ?? null, $selector)
                || ($result['expected'] ?? null) !== $restore['after']) {
                throw new InvalidArgumentException(SiteChangeApplier::STALE);
            }

            return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => null];
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private static function selector(array $input): array
    {
        if (! is_int($input['user_id'] ?? null) || $input['user_id'] < 1
            || ! is_int($input['target_id'] ?? null) || $input['target_id'] < 1
            || ! in_array($input['kind'] ?? null, ['course', 'group'], true)) {
            throw new InvalidArgumentException('נדרשים מזהי תלמיד ויעד חיוביים, וסוג course או group.');
        }

        return ['user_id' => $input['user_id'], 'kind' => $input['kind'], 'target_id' => $input['target_id']];
    }

    private static function sameSelector(mixed $value, array $selector): bool
    {
        return is_array($value) && ($value['user_id'] ?? null) === $selector['user_id']
            && ($value['kind'] ?? null) === $selector['kind'] && ($value['target_id'] ?? null) === $selector['target_id'];
    }

    private static function matches(array $data, array $selector): bool
    {
        return self::sameSelector($data['selector'] ?? null, $selector)
            && ($data['user']['id'] ?? null) === $selector['user_id']
            && ($data['target']['id'] ?? null) === $selector['target_id']
            && ($data['target']['type'] ?? null) === $selector['kind'];
    }

    private function state(mixed $value): bool
    {
        if (! is_array($value) || ! is_bool($value['direct_member'] ?? null)
            || ! array_key_exists('effective_access', $value) || ($value['effective_access'] !== null && ! is_bool($value['effective_access']))
            || ! is_array($value['access_sources'] ?? null) || ! array_is_list($value['access_sources'])) {
            return false;
        }
        foreach (['access_from', 'expires_at'] as $key) {
            if (! array_key_exists($key, $value) || ($value[$key] !== null && (! is_int($value[$key]) || $value[$key] < 0))) {
                return false;
            }
        }
        foreach ($value['access_sources'] as $source) {
            if (! is_string($source) || ! preg_match('/^(direct|open|other|group:[1-9][0-9]*)$/D', $source)) {
                return false;
            }
        }

        return count($value['access_sources']) === count(array_unique($value['access_sources']));
    }

    private function preview(Site $site, array $selector, string $action, string $user, string $target, array $offer): string
    {
        $course = $selector['kind'] === 'course';
        $lines = ['🎓 '.($course ? 'רישום ישיר לקורס' : 'חברות בקבוצת LearnDash'), 'אתר: '.$site->domain,
            'תלמיד: '.$user.' (#'.$selector['user_id'].')', ($course ? 'קורס: ' : 'קבוצה: ').$target.' (#'.$selector['target_id'].')',
            'פעולה: '.($action === 'add' ? 'הוספה' : 'הסרה'),
            ($course ? 'רישום ישיר' : 'חברות בקבוצה').': '.$this->yesNo($offer['before']['direct_member']).' ← '.$this->yesNo($offer['after']['direct_member'])];
        if ($course) {
            $lines[] = 'גישה לקורס: '.$this->yesNo($offer['before']['effective_access']).' ← '.$this->yesNo($offer['after']['effective_access']);
            $lines[] = 'מקורות גישה: '.$this->sources($offer['before']['access_sources']).' ← '.$this->sources($offer['after']['access_sources']);
            if ($offer['before']['access_from'] !== null) {
                $lines[] = 'תחילת הרישום הקיימת: '.gmdate('Y-m-d H:i', $offer['before']['access_from']).' UTC; בביטול הפעולה ישוחזר התאריך המקורי.';
            }
            if ($action === 'add') {
                $lines[] = 'תחילת הרישום הישיר תיקבע באתר בעת ביצוע האישור.';
            }
            if ($action === 'remove' && $offer['after']['effective_access'] !== false) {
                $lines[] = $offer['after']['effective_access'] === true
                    ? 'לתלמיד תישאר גישה לקורס ממקור אחר; ההסרה מתייחסת לרישום הישיר בלבד.'
                    : 'לא ניתן לקבוע שהגישה תיחסם; ההסרה מתייחסת לרישום הישיר בלבד.';
            }
        }
        foreach ((array) ($offer['impacts'] ?? []) as $impact) {
            if (! is_array($impact) || ! is_int($impact['course_id'] ?? null) || ! is_string($impact['title'] ?? null)
                || ! array_key_exists('before_access', $impact) || ! array_key_exists('after_access', $impact)
                || ($impact['before_access'] !== null && ! is_bool($impact['before_access']))
                || ($impact['after_access'] !== null && ! is_bool($impact['after_access']))) {
                throw new InvalidArgumentException('לא התקבל פירוט מלא של ההשפעה על הקורסים.');
            }
            $lines[] = 'גישה ל־'.self::safeText($impact['title']).' (#'.$impact['course_id'].'): '.$this->yesNo($impact['before_access']).' ← '.$this->yesNo($impact['after_access']);
        }
        $lines[] = 'התקדמות הלימוד אינה מאופסת.';
        $lines[] = 'האתר עשוי לשלוח הודעות ולהפעיל אוטומציות בעקבות הרישום. שחזור הרישום אינו מבטל הודעות או פעולות במערכות חיצוניות.';
        foreach ((array) ($offer['notes'] ?? []) as $note) {
            if (is_string($note)) {
                $lines[] = self::safeText($note);
            }
        }

        return implode("\n", $lines);
    }

    private function validateImpacts(array $read, array $offer): void
    {
        $old = [];
        foreach ((array) ($read['impacts'] ?? []) as $row) {
            if (! is_array($row) || ! is_int($row['course_id'] ?? null) || $row['course_id'] < 1
                || isset($old[$row['course_id']]) || ! is_string($row['title'] ?? null)
                || ! array_key_exists('effective_access', $row)
                || ($row['effective_access'] !== null && ! is_bool($row['effective_access']))) {
                throw new InvalidArgumentException('לא התקבלה רשימת קורסים מלאה ואמינה.');
            }
            $old[$row['course_id']] = $row;
        }
        $seen = [];
        foreach ((array) ($offer['impacts'] ?? []) as $row) {
            $id = is_array($row) ? ($row['course_id'] ?? null) : null;
            if (! is_int($id) || ! isset($old[$id]) || isset($seen[$id])
                || ($row['title'] ?? null) !== $old[$id]['title'] || ! array_key_exists('before_access', $row)
                || $row['before_access'] !== $old[$id]['effective_access']) {
                throw new InvalidArgumentException('ההשפעה על הקורסים אינה תואמת למצב שנקרא.');
            }
            $seen[$id] = true;
        }
        if (count($seen) !== count($old)) {
            throw new InvalidArgumentException('חסר פירוט ההשפעה על חלק מהקורסים.');
        }
    }

    private static function safeText(string $text): string
    {
        return preg_replace('/[\w.+\-]+@[\w\-]+\.[\w.\-]+/u', '[אימייל מוסתר]', strip_tags($text));
    }

    private function sources(array $sources): string
    {
        return $sources === [] ? 'אין מקור ידוע' : implode(', ', array_map(fn (string $source): string => match ($source) {
            'direct' => 'רישום ישיר', 'open' => 'קורס פתוח', 'other' => 'מקור נוסף',
            default => 'קבוצה #'.substr($source, 6),
        }, $sources));
    }

    private function yesNo(?bool $value): string
    {
        return $value === null ? 'לא ניתן לקבוע' : ($value ? 'כן' : 'לא');
    }

    private function available(Site $site): bool
    {
        foreach (['ld_membership_get', ...self::pluginTools()] as $tool) {
            if (! $this->toolbox->siteHas($site, $tool)) {
                return false;
            }
        }

        return true;
    }

    private function assertAllowed(Site $site): void
    {
        if (! $this->available($site)) {
            throw new InvalidArgumentException('שינוי רישום LearnDash דורש תוסף סוכן מעודכן ויכולות LearnDash זמינות.');
        }
        if (! app(SiteAgentPermissions::class)->allowsTool(self::TOOL)
            || ! app(SiteAgentPermissions::class)->allowsOperation(SiteAgentRequest::OP_LEARNDASH_MEMBERSHIP)) {
            throw new InvalidArgumentException(SiteAgentPermissions::refusal());
        }
    }

    private function snapshot(mixed $value): bool
    {
        return is_array($value) && count($value) === 2 && is_string($value['version'] ?? null) && $value['version'] !== ''
            && is_string($value['token'] ?? null) && $value['token'] !== '' && strlen($value['token']) < 1000000;
    }

    private function call(Site $site, string $tool, array $args): array
    {
        try {
            $data = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $args)), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            // Remote errors can contain arbitrary metadata or serialized receipts.
            // Never copy their text into a model result or WhatsApp transcript.
            throw new InvalidArgumentException(str_contains($error->getMessage(), SiteChangeApplier::STALE)
                ? SiteChangeApplier::STALE : 'לא ניתן להשלים את בקשת LearnDash באתר. קראו את המצב מחדש לפני הצעה נוספת.');
        }
        if (! is_array($data)) {
            throw new InvalidArgumentException('האתר החזיר מידע LearnDash לא תקין.');
        }

        return $data;
    }

    private function failure(\Throwable $error): array
    {
        $message = Str::limit($error->getMessage(), 400);

        return ['ok' => false, 'reason' => $message, 'message' => $message, 'restore' => null];
    }
}
