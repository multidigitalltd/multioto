<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Verified LearnDash reads and narrowly scoped, reversible native enrollment changes. */
class Multioto_Agent_LearnDash
{
    private const LIMIT = 200;

    private const MAX_BYTES = 350000;

    private const READS = ['ld_capabilities', 'ld_courses_list', 'ld_course_get', 'ld_student_course_get', 'ld_groups_list', 'ld_group_get'];

    private const WRITES = ['ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert'];

    private static $activeLocks = [];

    public static function active(): bool
    {
        return defined('LEARNDASH_VERSION') || function_exists('learndash_get_setting') || function_exists('ld_update_course_access');
    }

    public static function definitions(): array
    {
        $selector = ['user_id' => ['type' => 'integer', 'minimum' => 1], 'kind' => ['type' => 'string', 'enum' => ['course', 'group']], 'target_id' => ['type' => 'integer', 'minimum' => 1]];
        $definitions = [];
        foreach (array_merge(self::READS, self::WRITES) as $name) {
            $properties = [];
            $required = [];
            if (strpos($name, 'ld_membership_') === 0) {
                $properties = $selector;
                $required = ['user_id', 'kind', 'target_id'];
                if ($name !== 'ld_membership_get') {
                    $properties['expected'] = ['type' => 'object'];
                    $required[] = 'expected';
                }
                if ($name === 'ld_membership_prepare') {
                    $properties['action'] = ['type' => 'string', 'enum' => ['add', 'remove']];
                    $required[] = 'action';
                }
                foreach (['ld_membership_apply' => 'prepared', 'ld_membership_revert' => 'restore'] as $tool => $key) {
                    if ($name === $tool) {
                        $properties[$key] = ['type' => 'object'];
                        $required[] = $key;
                    }
                }
            } elseif (in_array($name, ['ld_courses_list', 'ld_groups_list', 'ld_group_get'], true)) {
                $properties = ['page' => ['type' => 'integer', 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'search' => ['type' => 'string']];
                if ($name === 'ld_group_get') {
                    $properties['group_id'] = ['type' => 'integer', 'minimum' => 1];
                    $required[] = 'group_id';
                }
            } elseif (in_array($name, ['ld_course_get', 'ld_student_course_get'], true)) {
                $properties['course_id'] = ['type' => 'integer', 'minimum' => 1];
                $required[] = 'course_id';
                if ($name === 'ld_student_course_get') {
                    $properties['user_id'] = ['type' => 'integer', 'minimum' => 1];
                    $required[] = 'user_id';
                }
            }
            $definitions[] = ['name' => $name, 'description' => 'LearnDash: קריאה מובנית וניהול הרשמה ישירה לקורס או חברות בקבוצה בלבד. אין איפוס התקדמות, ציונים או ניסיונות מבחן.', 'annotations' => ['readOnlyHint' => ! in_array($name, ['ld_membership_apply', 'ld_membership_revert'], true), 'destructiveHint' => false], 'inputSchema' => ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false]];
        }

        return $definitions;
    }

    public static function handles(string $name): bool
    {
        return in_array($name, array_merge(self::READS, self::WRITES), true);
    }

    public static function call(string $name, array $args): array
    {
        if ($name === 'ld_capabilities') {
            return self::capabilities();
        }
        if (! self::handles($name) || ! self::active()) {
            self::fail('LearnDash אינו פעיל או שהכלי אינו מוכר.');
        }
        if ($name === 'ld_courses_list' || $name === 'ld_groups_list') {
            return self::catalogue($name === 'ld_courses_list' ? 'course' : 'group', $args);
        }
        if ($name === 'ld_course_get') {
            return self::course(self::positive($args['course_id'] ?? null));
        }
        if ($name === 'ld_group_get') {
            return self::group(self::positive($args['group_id'] ?? null), $args);
        }
        if ($name === 'ld_student_course_get') {
            return self::studentCourse(self::positive($args['user_id'] ?? null), self::positive($args['course_id'] ?? null));
        }
        $selector = self::selector($args);
        if ($name === 'ld_membership_get') {
            $snapshot = self::capture($selector);

            return self::visible($snapshot) + ['snapshot' => self::seal($snapshot)];
        }
        $expected = self::open($args['expected'] ?? []);
        self::bind($expected, $selector);
        if ($name === 'ld_membership_prepare') {
            return self::prepare($selector, $expected, $args);
        }
        $result = self::withUserLock($selector['user_id'], static function () use ($name, $selector, $expected, $args): array {
            if ($name === 'ld_membership_apply') {
                $proposal = self::open($args['prepared'] ?? []);
                self::bind($proposal, $selector);
                if (($proposal['purpose'] ?? '') !== 'proposal' || ($proposal['expected_version'] ?? '') !== ($args['expected']['version'] ?? null)) {
                    self::fail('ההצעה אינה תואמת לצילום ההרשמה שאושר.');
                }

                return self::apply($selector, $expected, $proposal);
            }
            $restore = self::open($args['restore'] ?? []);
            self::bind($restore, $selector);

            return self::revert($selector, $expected, $restore);
        });

        return $result + ['selector' => $selector, 'expected' => $args['expected']];
    }

    private static function capabilities(): array
    {
        $version = defined('LEARNDASH_VERSION') && is_string(LEARNDASH_VERSION) ? LEARNDASH_VERSION : null;

        return ['active' => self::active(), 'version' => $version, 'course_membership' => ['available' => self::runtimeReason('course') === null, 'reason' => self::runtimeReason('course')], 'group_membership' => ['available' => self::runtimeReason('group') === null, 'reason' => self::runtimeReason('group')], 'progress_available' => function_exists('learndash_user_get_course_progress'), 'hierarchy_available' => self::hierarchyAvailable(), 'limitations' => ['אין איפוס התקדמות, ציונים או ניסיונות מבחן.', 'קשרים בין קורס לקבוצה ומבנה הקורס מוצגים לקריאה בלבד.', 'הרשמה במצב אחסון ישן או עם מדיניות תפוגה אינה נערכת ללא צילום מאומת.']];
    }

    private static function runtimeReason(string $kind): ?string
    {
        if (! self::active()) {
            return 'LearnDash אינו פעיל.';
        }
        if (function_exists('is_multisite') && is_multisite()) {
            return 'פעולות תלמידים אינן זמינות ברשת אתרים עד שאחסון ההרשמה לכל אתר יאומת.';
        }
        if (! defined('LEARNDASH_VERSION') || ! is_string(LEARNDASH_VERSION) || ! preg_match('/^\d+\.\d+/', LEARNDASH_VERSION)) {
            return 'לא ניתן לאמת את גרסת LearnDash.';
        }
        $required = ['learndash_get_setting', 'learndash_use_legacy_course_access_list', 'ld_course_access_expires_on', 'sfwd_lms_has_access', 'learndash_get_users_group_ids', 'learndash_group_enrolled_courses', $kind === 'group' ? 'ld_update_group_access' : 'ld_update_course_access'];
        foreach ($required as $function) {
            if (! function_exists($function)) {
                return 'ממשק LearnDash הנדרש אינו זמין בגרסה זו: '.$function;
            }
        }
        if (learndash_use_legacy_course_access_list()) {
            return 'האתר משתמש ברשימת גישה ישנה שלא ניתן לשחזר במדויק דרך כלי זה.';
        }

        return null;
    }

    private static function catalogue(string $kind, array $args): array
    {
        [$page, $limit] = self::page($args);
        $search = $args['search'] ?? '';
        if (! is_string($search) || strlen($search) > 200) {
            self::fail('מחרוזת החיפוש אינה תקינה.');
        }
        $posts = get_posts(['s' => self::text($search), 'post_type' => self::postType($kind), 'post_status' => ['publish', 'draft', 'private', 'pending'], 'numberposts' => $limit + 1, 'offset' => ($page - 1) * $limit, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false]);
        $rows = [];
        foreach (array_slice($posts, 0, $limit) as $post) {
            $rows[] = self::postPublic($post);
        }

        return [$kind === 'course' ? 'courses' : 'groups' => $rows, 'page' => $page, 'limit' => $limit, 'has_more' => count($posts) > $limit];
    }

    private static function course(int $id): array
    {
        $post = self::post($id, 'course');
        $out = ['course' => self::postPublic($post), 'hierarchy_available' => self::hierarchyAvailable()];
        if (! $out['hierarchy_available']) {
            return $out + ['hierarchy' => null, 'reason' => 'ממשקי היררכיית LearnDash אינם זמינים; לא נעשה שימוש ב-post_parent לניחוש המבנה.'];
        }
        $steps = self::ids(learndash_get_course_steps($id));
        $nodes = [];
        foreach ($steps as $stepId) {
            $step = get_post($stepId);
            if ($step && in_array($step->post_type, ['sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'], true) && ! in_array($step->post_status, ['trash', 'auto-draft'], true)) {
                $nodes[] = self::postPublic($step);
            }
        }
        $lessons = learndash_get_lesson_list($id, ['num' => self::LIMIT + 1]);
        self::boundList($lessons);
        $hierarchy = [];
        $count = 0;
        foreach ($lessons as $lesson) {
            if (! is_object($lesson) || ! in_array((int) $lesson->ID, $steps, true)) {
                self::fail('מבנה השיעורים שהחזיר LearnDash אינו תואם לקורס.');
            }
            $topics = learndash_get_topic_list((int) $lesson->ID, $id);
            self::boundList($topics);
            $safeTopics = [];
            foreach ($topics as $topic) {
                if (! is_object($topic) || ! in_array((int) $topic->ID, $steps, true)) {
                    self::fail('מבנה הנושאים שהחזיר LearnDash אינו תואם לקורס.');
                }
                $safeTopics[] = self::postPublic($topic);
            }
            $count += 1 + count($safeTopics);
            if ($count > self::LIMIT) {
                self::fail('הקורס מכיל יותר מ־200 שלבים. יש לעבוד עם קורס מצומצם יותר.');
            }
            $hierarchy[] = self::postPublic($lesson) + ['topics' => $safeTopics];
        }

        return $out + ['hierarchy' => ['lessons' => $hierarchy, 'steps' => $nodes], 'reason' => null];
    }

    private static function hierarchyAvailable(): bool
    {
        foreach (['learndash_get_course_steps', 'learndash_get_lesson_list', 'learndash_get_topic_list'] as $name) {
            if (! function_exists($name)) {
                return false;
            }
        }

        return true;
    }

    private static function group(int $id, array $args): array
    {
        $post = self::post($id, 'group');
        [$page, $limit] = self::page($args);
        $courseIds = self::groupCourses($id);
        $courses = [];
        foreach ($courseIds as $courseId) {
            $courses[] = self::postPublic(self::post($courseId, 'course'));
        }
        $out = ['group' => self::postPublic($post), 'courses' => $courses, 'members' => [], 'page' => $page, 'limit' => $limit];
        if (function_exists('is_multisite') && is_multisite()) {
            return $out + ['members_available' => false, 'has_more' => false, 'reason' => 'חברי הקבוצה אינם מוצגים ברשת אתרים עד שאחסון החברות לכל אתר יאומת.'];
        }
        $ids = get_users(['blog_id' => get_current_blog_id(), 'meta_key' => 'learndash_group_users_'.$id, 'meta_value' => (string) $id, 'number' => $limit + 1, 'offset' => ($page - 1) * $limit, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']);
        if (! is_array($ids) || count($ids) > $limit + 1) {
            self::fail('רשימת חברי הקבוצה חורגת מגודל העמוד המבוקש.');
        }
        foreach (array_slice($ids, 0, $limit) as $userId) {
            $user = get_userdata((int) $userId);
            if ($user && self::belongsToSite((int) $user->ID) && ! self::privileged($user)) {
                $out['members'][] = self::userPublic($user);
            }
        }

        return $out + ['members_available' => true, 'has_more' => count($ids) > $limit, 'reason' => 'משתמשים בעלי הרשאות ניהול אינם מוצגים ברשימת התלמידים.'];
    }

    private static function studentCourse(int $userId, int $courseId): array
    {
        $snapshot = self::capture(['user_id' => $userId, 'kind' => 'course', 'target_id' => $courseId]);
        $progress = null;
        if (function_exists('learndash_user_get_course_progress')) {
            $raw = learndash_user_get_course_progress($userId, $courseId, 'summary');
            if (is_array($raw) && isset($raw['total'], $raw['completed']) && is_numeric($raw['total']) && is_numeric($raw['completed'])) {
                $total = max(0, (int) $raw['total']);
                $completed = max(0, min($total, (int) $raw['completed']));
                $progress = ['total' => $total, 'completed' => $completed, 'percentage' => $total > 0 ? (int) round(100 * $completed / $total) : 0];
            }
        }

        return ['user' => $snapshot['user'], 'course' => ['id' => $snapshot['target']['id'], 'title' => $snapshot['target']['title']], 'progress' => $progress, 'access' => $snapshot['state']];
    }

    private static function capture(array $selector): array
    {
        if (function_exists('is_multisite') && is_multisite()) {
            self::fail('קריאת נתוני תלמידים ועריכת הרשמה אינן זמינות ברשת אתרים עד שאחסון ההרשמה לכל אתר יאומת.');
        }
        $user = self::user($selector['user_id']);
        $post = self::post($selector['target_id'], $selector['kind']);
        $reason = self::runtimeReason($selector['kind']);
        $expiredState = null;
        if ($selector['kind'] === 'course') {
            $expiredState = self::expiredState($selector['user_id'], $selector['target_id']);
            if ($expiredState['reason'] !== null && $reason === null) {
                $reason = $expiredState['reason'];
            }
        }
        $nativeGroups = function_exists('learndash_get_users_group_ids') ? self::ids(learndash_get_users_group_ids($user->ID)) : [];
        $groupStorage = self::groupMembershipState($user->ID, $nativeGroups);
        $groups = $groupStorage['ids'];
        if ($groupStorage['reason'] !== null && $reason === null) {
            $reason = $groupStorage['reason'];
        }
        $groupCourses = [];
        $edges = 0;
        foreach ($groups as $groupId) {
            $groupCourses[$groupId] = self::groupCourses($groupId);
            $edges += count($groupCourses[$groupId]);
            if ($edges > self::LIMIT) {
                self::fail('לתלמיד יותר מ־200 קשרי קבוצה–קורס. יש לצמצם את בדיקת הגישה לפני עריכה.');
            }
        }
        $courseIds = $selector['kind'] === 'course' ? [$selector['target_id']] : self::groupCourses($selector['target_id']);
        $courses = [];
        foreach ($courseIds as $courseId) {
            $courses[$courseId] = self::courseAccess($user->ID, $courseId, $groups, $groupCourses);
            if ($courses[$courseId]['reason'] !== null && $reason === null) {
                $reason = $courses[$courseId]['reason'];
            }
        }
        $key = self::metaKey($selector);
        $raw = get_user_meta($user->ID, $key, false);
        $valid = self::validRows($raw, $selector['kind'], $selector['target_id']);
        if (! $valid && $reason === null) {
            $reason = 'אחסון החברות מכיל ערך לא תקין או יותר משורה אחת.';
        }
        if ($post->post_status !== 'publish' && $reason === null) {
            $reason = 'ניתן לשנות הרשמה רק לקורס או קבוצה מפורסמים.';
        }
        $direct = $valid && self::modern() ? count($raw) === 1 : null;
        if ($selector['kind'] === 'course') {
            $state = $courses[$selector['target_id']]['state'];
        } else {
            $effective = in_array($selector['target_id'], $groups, true);
            $state = ['direct_member' => $direct, 'effective_access' => $effective, 'access_sources' => $effective ? ['group:'.$selector['target_id']] : [], 'access_from' => null, 'expires_at' => null];
            if ($direct !== null && $direct !== $effective && $reason === null) {
                $reason = 'רשימת הקבוצות ואחסון החברות אינם תואמים.';
            }
        }
        $impacts = [];
        foreach ($courses as $courseId => $course) {
            $impacts[] = ['course_id' => $courseId, 'title' => $course['title'], 'effective_access' => $course['state']['effective_access']];
        }

        return ['purpose' => 'state', 'scope' => self::scope(), 'selector' => $selector, 'user' => self::userPublic($user), 'target' => ['id' => (int) $post->ID, 'title' => self::text($post->post_title), 'type' => $selector['kind']], 'state' => $state, 'writable' => $reason === null, 'reason' => $reason, 'impacts' => $impacts, 'primary' => ['key' => $key, 'rows' => $raw], 'expired_state' => $expiredState, 'groups' => $groups, 'group_rows' => $groupStorage['rows'], 'group_courses' => $groupCourses, 'courses' => $courses, 'version' => defined('LEARNDASH_VERSION') ? LEARNDASH_VERSION : null];
    }

    /** Read only verified group keys, bypassing a possibly stale LearnDash inverse cache. */
    private static function groupMembershipState(int $userId, array $nativeIds): array
    {
        global $wpdb;
        $prefix = 'learndash_group_users_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY umeta_id ASC LIMIT 201",
            $userId,
            $wpdb->esc_like($prefix).'%'
        ), ARRAY_A);
        $invalid = ['ids' => [], 'rows' => [], 'reason' => 'לא ניתן לאמת את אחסון הקבוצות בתחום של עד 200 קבוצות לתלמיד.'];
        if (! is_array($rows) || count($rows) > self::LIMIT) {
            return $invalid;
        }
        $verified = [];
        foreach ($rows as $row) {
            $key = $row['meta_key'] ?? null;
            $value = $row['meta_value'] ?? null;
            if (! is_string($key) || ! preg_match('/^learndash_group_users_([1-9][0-9]*)$/D', $key, $match)
                || ! is_string($value) || $value !== $match[1] || (string) (int) $match[1] !== $match[1]) {
                return $invalid;
            }
            $id = (int) $match[1];
            if (isset($verified[$id])) {
                return $invalid;
            }
            $verified[$id] = ['meta_key' => $key, 'meta_value' => $value];
        }
        ksort($verified, SORT_NUMERIC);
        $ids = array_keys($verified);

        return ['ids' => $ids, 'rows' => array_values($verified), 'reason' => $ids === $nativeIds ? null : 'רשימת הקבוצות של LearnDash אינה תואמת לאחסון העדכני. יש לרענן את נתוני הגישה באתר לפני שינוי הרשמה.'];
    }

    /** The verified expired-state reader is course-wide; prove a small global user bound first. */
    private static function expiredState(int $userId, int $courseId): array
    {
        if (! function_exists('learndash_get_course_expired_access_from_meta')) {
            return ['expired' => null, 'reason' => 'אין ממשק מאומת לבדיקת סימון תפוגת ההרשמה הישירה.'];
        }
        $population = get_users(['blog_id' => 0, 'number' => 1001, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']);
        if (! is_array($population) || count($population) > 1000) {
            return ['expired' => null, 'reason' => 'באתר עם יותר מ־1000 משתמשים נדרש ממשק תפוגה ממוקד לתלמיד לפני עריכת הרשמה ישירה.'];
        }
        $expired = learndash_get_course_expired_access_from_meta($courseId);
        if (! is_array($expired) || count($expired) > 1000) {
            return ['expired' => null, 'reason' => 'רשימת תפוגת הגישה אינה ניתנת לאימות בתחום הבדיקה.'];
        }
        foreach ($expired as $id) {
            if (! (is_int($id) || is_string($id) && ctype_digit($id))) {
                return ['expired' => null, 'reason' => 'ממשק תפוגת הגישה החזיר מזהה לא תקין.'];
            }
        }
        $present = in_array($userId, array_map('intval', $expired), true);

        return ['expired' => $present, 'reason' => $present ? 'הרשמת התלמיד מסומנת כפגת תוקף. אין לשנות אותה בלי תמיכה בשחזור סימון התפוגה.' : null];
    }

    private static function courseAccess(int $userId, int $courseId, array $groups, array $groupCourses): array
    {
        $post = self::post($courseId, 'course');
        $rows = get_user_meta($userId, 'course_'.$courseId.'_access_from', false);
        $valid = self::validRows($rows, 'course', $courseId);
        $direct = $valid && self::modern() ? count($rows) === 1 : null;
        $settings = get_post_meta($courseId, '_sfwd-courses', true);
        if ($settings === '' || $settings === null) {
            $settings = [];
        }
        $reason = null;
        if (! is_array($settings) || ! $valid) {
            $reason = 'לא ניתן לאמת את הגדרות הגישה או את תאריך ההרשמה לקורס.';
        }
        $flag = is_array($settings) ? ($settings['sfwd-courses_expire_access'] ?? '') : null;
        if (! in_array($flag, ['', false, null, 0, '0', 'off'], true)) {
            $reason = 'בקורס מוגדרת תפוגת גישה; עריכה זו דורשת תמיכה נפרדת במדיניות ובסימוני התפוגה.';
        }
        $expiry = function_exists('ld_course_access_expires_on') ? ld_course_access_expires_on($courseId, $userId) : null;
        if ($expiry !== null && $expiry !== false && $expiry !== '' && $expiry !== 0 && $expiry !== '0') {
            if (! is_numeric($expiry) || (int) $expiry < 0) {
                $reason = 'LearnDash החזיר מצב תפוגה שלא ניתן לאמת.';
                $expiry = null;
            } else {
                $expiry = (int) $expiry;
                $reason = 'לתלמיד יש תאריך תפוגת גישה בקורס. עריכת הרשמה מוגבלת עד שניתן לשחזר גם את מדיניות התפוגה.';
            }
        } else {
            $expiry = null;
        }
        $mode = function_exists('learndash_get_setting') ? learndash_get_setting($courseId, 'course_price_type') : null;
        $sources = [];
        if ($direct) {
            $sources[] = 'direct';
        }
        foreach ($groups as $groupId) {
            if (in_array($courseId, $groupCourses[$groupId] ?? [], true)) {
                $sources[] = 'group:'.$groupId;
            }
        }
        if ($mode === 'open') {
            $sources[] = 'open';
        }
        $effective = function_exists('sfwd_lms_has_access') ? (bool) sfwd_lms_has_access($courseId, $userId) : null;
        if (! in_array($mode, ['open', 'free', 'paynow', 'subscribe', 'closed'], true)) {
            $reason = 'סוג הגישה לקורס אינו מוכר ולכן לא ניתן לחזות את תוצאת שינוי ההרשמה.';
        }
        if ($effective !== null && $effective !== ($sources !== [])) {
            $sources[] = 'other';
            $reason = 'גישה לקורס נקבעת גם על ידי מדיניות נוספת; לא ניתן להבטיח שינוי הרשמה הפיך במצב זה.';
        }

        return ['title' => self::text($post->post_title), 'status' => $post->post_status, 'mode' => $mode, 'settings' => $settings, 'rows' => $rows, 'reason' => $reason, 'state' => ['direct_member' => $direct, 'effective_access' => $effective, 'access_sources' => $sources, 'access_from' => $direct ? (int) $rows[0] : null, 'expires_at' => $expiry]];
    }

    private static function prepare(array $selector, array $expected, array $args): array
    {
        self::assertFresh($selector, $expected);
        if (! $expected['writable']) {
            self::fail($expected['reason'] ?? 'ההרשמה אינה ניתנת לעריכה במצב זה.');
        }
        $action = $args['action'] ?? '';
        if (! in_array($action, ['add', 'remove'], true)) {
            self::fail('פעולת החברות אינה תקינה.');
        }
        $member = $action === 'add';
        $after = self::predict($expected, $member);
        $proposal = ['purpose' => 'proposal', 'scope' => self::scope(), 'selector' => $selector, 'expected_version' => $args['expected']['version'], 'member' => $member, 'nonce' => bin2hex(random_bytes(16)), 'after' => $after];
        $notes = ['הפעולה אינה מאפסת התקדמות, ציונים או ניסיונות מבחן.', 'LearnDash ותוספי האתר עשויים להפעיל הודעות או אוטומציות בעת שינוי חברות; שחזור החברות אינו מחזיר הודעות שכבר נשלחו.'];
        if ($selector['kind'] === 'course' && ! $member && $after['state']['effective_access']) {
            $notes[] = 'תוסר ההרשמה הישירה בלבד. הגישה לקורס תישאר דרך קבוצה או קורס פתוח.';
        }
        if ($selector['kind'] === 'course' && $member) {
            $notes[] = 'תאריך תחילת ההרשמה הישירה ייקבע על ידי LearnDash בזמן ביצוע האישור.';
        }

        return ['selector' => $selector, 'user' => $expected['user'], 'target' => $expected['target'], 'before' => $expected['state'], 'after' => $after['state'], 'impacts' => $after['impacts'], 'notes' => $notes, 'expected' => $args['expected'], 'prepared' => self::seal($proposal), 'changed' => $expected['state']['direct_member'] !== $member];
    }

    private static function predict(array $snapshot, bool $member): array
    {
        $selector = $snapshot['selector'];
        $state = $snapshot['state'];
        $state['direct_member'] = $member;
        $state['access_from'] = $selector['kind'] === 'course' && $member ? null : $state['access_from'];
        if ($selector['kind'] === 'course' && ! $member) {
            $state['access_from'] = null;
        }
        $impacts = [];
        foreach ($snapshot['courses'] as $courseId => $course) {
            $sources = $course['state']['access_sources'];
            $affectedSource = $selector['kind'] === 'course' ? 'direct' : 'group:'.$selector['target_id'];
            $sources = array_values(array_filter($sources, static function ($source) use ($affectedSource) {
                return $source !== $affectedSource;
            }));
            if ($member) {
                $sources[] = $affectedSource;
            }
            $access = $sources !== [];
            $impacts[] = ['course_id' => (int) $courseId, 'title' => $course['title'], 'before_access' => $course['state']['effective_access'], 'after_access' => $access];
            if ($selector['kind'] === 'course') {
                $state['access_sources'] = $sources;
                $state['effective_access'] = $access;
            }
        }
        if ($selector['kind'] === 'group') {
            $state['effective_access'] = $member;
            $state['access_sources'] = $member ? ['group:'.$selector['target_id']] : [];
        }

        return ['state' => $state, 'impacts' => $impacts];
    }

    private static function apply(array $selector, array $expected, array $proposal): array
    {
        $changeId = 'ld_'.$proposal['nonce'];
        $journalKey = self::journalKey($changeId);
        $journal = get_option($journalKey, null);
        if (is_array($journal)) {
            if (($journal['status'] ?? '') !== 'applied' || ($journal['selector'] ?? null) !== $selector) {
                self::fail('הצעת החברות כבר טופלה. יש ליצור הצעה חדשה.');
            }
            $after = self::open($journal['after']);
            self::assertFresh($selector, $after);

            return ['changed' => true, 'before' => $journal['before'], 'after' => $journal['after'], 'already_applied' => true];
        }
        self::assertFresh($selector, $expected);
        if (! $expected['writable']) {
            self::fail($expected['reason'] ?? 'ההרשמה אינה ניתנת לעריכה.');
        }
        if ($expected['state']['direct_member'] === $proposal['member']) {
            return ['changed' => false];
        }
        $before = $expected;
        $before['change_id'] = $changeId;
        $attemptedRows = null;
        try {
            self::writeNative($selector, $proposal['member'], $expected['primary']['rows']);
            $attemptedRows = get_user_meta($selector['user_id'], $expected['primary']['key'], false);
            $after = self::capture($selector);
            self::assertOutcome($after, $proposal['after'], $proposal['member']);
            // Group membership must not quietly change direct course registrations.
            self::assertUnrelatedDirectDates($expected, $after);
            $after['change_id'] = $changeId;
            $beforeSeal = self::seal($before);
            $afterSeal = self::seal($after);
            self::assertOwnedLock($selector['user_id']);
            if (! add_option($journalKey, ['status' => 'applied', 'selector' => $selector, 'before' => $beforeSeal, 'after' => $afterSeal], '', false)) {
                self::fail('לא ניתן לשמור אישור פעולה לצורך שחזור.');
            }

            return ['changed' => true, 'before' => $beforeSeal, 'after' => $afterSeal];
        } catch (Throwable $error) {
            self::compensate($selector, $expected['primary']['rows'], $attemptedRows);
            self::fail('עדכון החברות לא הושלם; החברות הישירה הקודמת שוחזרה. יש לקרוא את הגישה שוב לפני ניסיון נוסף.');
        }
    }

    private static function revert(array $selector, array $expected, array $restore): array
    {
        $changeId = $expected['change_id'] ?? '';
        if (! is_string($changeId) || ! preg_match('/^ld_[a-f0-9]{32}$/D', $changeId) || $changeId !== ($restore['change_id'] ?? null)) {
            self::fail('צילום שחזור החברות אינו תקין.');
        }
        $journalKey = self::journalKey($changeId);
        $journal = get_option($journalKey, null);
        if (! is_array($journal) || ($journal['selector'] ?? null) !== $selector || ($journal['before']['version'] ?? '') !== self::seal($restore)['version'] || ($journal['after']['version'] ?? '') !== self::seal($expected)['version']) {
            self::fail('צילום השחזור אינו תואם לפעולה שבוצעה.');
        }
        if (($journal['status'] ?? '') === 'reverted') {
            self::assertFresh($selector, $restore);

            return ['changed' => true, 'already_reverted' => true, 'before' => $journal['after'], 'after' => $journal['before']];
        }
        if (($journal['status'] ?? '') !== 'applied') {
            self::fail('פעולת החברות אינה זמינה לשחזור.');
        }
        self::assertFresh($selector, $expected);
        if (! $expected['writable'] || ! $restore['writable']) {
            self::fail('מדיניות הגישה השתנתה ולא ניתן לשחזר בבטחה.');
        }
        $attemptedRows = null;
        try {
            self::restoreRows($selector, $restore['primary']['rows'], $expected['primary']['rows']);
            $attemptedRows = get_user_meta($selector['user_id'], $restore['primary']['key'], false);
            self::assertFresh($selector, $restore);
            $journal['status'] = 'reverted';
            self::assertOwnedLock($selector['user_id']);
            update_option($journalKey, $journal, false);
            $savedJournal = get_option($journalKey, null);
            if (! is_array($savedJournal) || ($savedJournal['status'] ?? '') !== 'reverted') {
                self::fail('לא ניתן לאשר את שמירת השחזור.');
            }

            return ['changed' => true, 'before' => $journal['after'], 'after' => $journal['before']];
        } catch (Throwable $error) {
            self::compensate($selector, $expected['primary']['rows'], $attemptedRows);
            self::fail('השחזור לא הושלם. החברות נשמרה במצב שקדם לניסיון השחזור.');
        }
    }

    private static function assertOutcome(array $after, array $prediction, bool $member): void
    {
        if (! $after['writable'] || $after['state']['direct_member'] !== $member || $after['state']['effective_access'] !== $prediction['state']['effective_access']) {
            self::fail('תוצאת הרשמת LearnDash אינה תואמת להצעה שאושרה.');
        }
        foreach ($prediction['impacts'] as $impact) {
            $course = $after['courses'][$impact['course_id']] ?? null;
            if (! $course || $course['state']['effective_access'] !== $impact['after_access']) {
                self::fail('תוצאת הגישה לאחד הקורסים אינה תואמת להצעה.');
            }
        }
    }

    private static function assertUnrelatedDirectDates(array $before, array $after): void
    {
        foreach ($before['courses'] as $id => $course) {
            if ($before['selector']['kind'] === 'course' && $before['selector']['target_id'] === (int) $id) {
                continue;
            }
            if (($after['courses'][$id]['rows'] ?? null) !== $course['rows']) {
                self::fail('אוטומציה נוספת באתר שינתה גם הרשמה ישירה לקורס.');
            }
        }
    }

    private static function writeNative(array $selector, bool $member, array $expectedRows): void
    {
        self::user($selector['user_id']);
        self::post($selector['target_id'], $selector['kind']);
        $reason = self::runtimeReason($selector['kind']);
        if ($reason !== null || get_user_meta($selector['user_id'], self::metaKey($selector), false) !== $expectedRows) {
            self::fail($reason ?? 'נתוני ההרשמה השתנו לפני שמירת הפעולה.');
        }
        self::assertOwnedLock($selector['user_id']);
        if ($selector['kind'] === 'course') {
            ld_update_course_access($selector['user_id'], $selector['target_id'], ! $member);
        } else {
            ld_update_group_access($selector['user_id'], $selector['target_id'], ! $member);
        }
        $rows = get_user_meta($selector['user_id'], self::metaKey($selector), false);
        if (! self::validRows($rows, $selector['kind'], $selector['target_id']) || (count($rows) === 1) !== $member) {
            self::fail('LearnDash לא שמר את שינוי החברות.');
        }
    }

    private static function restoreRows(array $selector, array $desiredRows, array $expectedRows): void
    {
        if (! self::validRows($desiredRows, $selector['kind'], $selector['target_id'])) {
            self::fail('צילום תאריך ההרשמה אינו תקין.');
        }
        self::writeNative($selector, count($desiredRows) === 1, $expectedRows);
        $key = self::metaKey($selector);
        $nativeRows = get_user_meta($selector['user_id'], $key, false);
        if ($desiredRows === []) {
            if ($nativeRows !== []) {
                self::fail('LearnDash לא הסיר את החברות.');
            }
        } elseif ($nativeRows !== $desiredRows) {
            // Native re-enrollment creates today's timestamp; preserve the exact original drip date.
            self::assertOwnedLock($selector['user_id']);
            update_user_meta($selector['user_id'], $key, wp_slash($desiredRows[0]), $nativeRows[0]);
        }
        if (get_user_meta($selector['user_id'], $key, false) !== $desiredRows) {
            self::fail('תאריך תחילת הגישה המקורי לא שוחזר במדויק.');
        }
    }

    private static function compensate(array $selector, array $beforeRows, ?array $attemptedRows): void
    {
        $current = get_user_meta($selector['user_id'], self::metaKey($selector), false);
        if ($current === $beforeRows) {
            return;
        }
        if ($attemptedRows === null || $current !== $attemptedRows) {
            self::fail('החברות השתנתה במהלך הפעולה. השינוי החיצוני לא נדרס; נדרשת בדיקה בלוח הבקרה.');
        }
        try {
            self::assertOwnedLock($selector['user_id']);
            self::restoreRows($selector, $beforeRows, $current);
        } catch (Throwable $error) {
            self::fail('שינוי החברות נכשל והשחזור האוטומטי לא הושלם. יש לבדוק את הרשמת התלמיד בלוח הבקרה.');
        }
    }

    private static function assertFresh(array $selector, array $expected): void
    {
        $current = self::capture($selector);
        unset($expected['change_id']);
        if (self::json($current) !== self::json($expected)) {
            self::fail('החברות, מקורות הגישה או הגדרות הקורס השתנו מאז ההצעה. יש להפיק הצעה חדשה.');
        }
    }

    private static function visible(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(['selector', 'user', 'target', 'state', 'writable', 'reason', 'impacts']));
    }

    private static function modern(): bool
    {
        return function_exists('learndash_use_legacy_course_access_list') && ! learndash_use_legacy_course_access_list();
    }

    private static function groupCourses(int $id): array
    {
        if (! function_exists('learndash_group_enrolled_courses')) {
            self::fail('ממשק קריאת קורסי הקבוצה אינו זמין.');
        }

        return self::ids(learndash_group_enrolled_courses($id, true));
    }

    private static function validRows($rows, string $kind, int $id): bool
    {
        if (! is_array($rows) || count($rows) > 1) {
            return false;
        }
        if ($rows === []) {
            return true;
        }
        $value = $rows[0];

        return (is_int($value) || is_string($value) && ctype_digit($value)) && (int) $value > 0 && ($kind !== 'group' || (int) $value === $id);
    }

    private static function metaKey(array $selector): string
    {
        return $selector['kind'] === 'course' ? 'course_'.$selector['target_id'].'_access_from' : 'learndash_group_users_'.$selector['target_id'];
    }

    private static function selector(array $args): array
    {
        $kind = $args['kind'] ?? null;
        if (! in_array($kind, ['course', 'group'], true)) {
            self::fail('יש לבחור קורס או קבוצה.');
        }

        return ['user_id' => self::positive($args['user_id'] ?? null), 'kind' => $kind, 'target_id' => self::positive($args['target_id'] ?? null)];
    }

    private static function positive($value): int
    {
        if (! is_int($value) || $value < 1) {
            self::fail('נדרש מזהה מספרי חיובי.');
        }

        return $value;
    }

    private static function postType(string $kind): string
    {
        return $kind === 'course' ? 'sfwd-courses' : 'groups';
    }

    private static function post(int $id, string $kind)
    {
        $post = get_post($id);
        if (! $post || $post->post_type !== self::postType($kind) || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            self::fail('קורס או קבוצה זמינים לא נמצאו.');
        }

        return $post;
    }

    private static function postPublic($post): array
    {
        return ['id' => (int) $post->ID, 'title' => self::text($post->post_title), 'type' => (string) $post->post_type, 'status' => (string) $post->post_status];
    }

    private static function user(int $id)
    {
        $user = get_userdata($id);
        if (! $user || ! self::belongsToSite($id) || self::privileged($user)) {
            self::fail('תלמיד זמין לעריכה לא נמצא; משתמשים בעלי הרשאות ניהול מוגנים.');
        }

        return $user;
    }

    private static function belongsToSite(int $id): bool
    {
        if (! function_exists('is_multisite') || ! is_multisite()) {
            return true;
        }

        return function_exists('is_user_member_of_blog') && is_user_member_of_blog($id, get_current_blog_id());
    }

    private static function privileged($user): bool
    {
        if (function_exists('is_super_admin') && is_super_admin($user->ID)) {
            return true;
        }
        foreach (['manage_options', 'edit_users', 'promote_users', 'delete_users', 'manage_network'] as $cap) {
            if ($user->has_cap($cap)) {
                return true;
            }
        }

        return false;
    }

    private static function userPublic($user): array
    {
        $label = self::text((string) $user->display_name);
        if ($label === '' || filter_var($label, FILTER_VALIDATE_EMAIL) || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $label)) {
            $label = 'תלמיד #'.$user->ID;
        }

        return ['id' => (int) $user->ID, 'display_name' => $label];
    }

    private static function text($value): string
    {
        return trim(strip_tags((string) $value));
    }

    private static function page(array $args): array
    {
        $page = $args['page'] ?? 1;
        $limit = $args['limit'] ?? 50;
        if (! is_int($page) || $page < 1 || $page > 10000 || ! is_int($limit) || $limit < 1 || $limit > 100) {
            self::fail('מספר העמוד או גודל העמוד אינם תקינים.');
        }

        return [$page, $limit];
    }

    private static function ids($values): array
    {
        self::boundList($values);
        $ids = [];
        foreach ($values as $value) {
            if (! (is_int($value) || is_string($value) && ctype_digit($value)) || (int) $value < 1) {
                self::fail('LearnDash החזיר מזהה לא תקין.');
            }
            $ids[] = (int) $value;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private static function boundList($values): void
    {
        if (! is_array($values) || count($values) > self::LIMIT) {
            self::fail('LearnDash החזיר יותר מ־200 פריטים או מבנה לא תקין. יש לצמצם את הפעולה.');
        }
    }

    private static function withUserLock(int $id, callable $callback)
    {
        $key = '_multioto_ld_write_'.$id;
        $value = ['owner' => bin2hex(random_bytes(16)), 'expires' => time() + 300];
        $old = get_option($key, null);
        if (is_array($old) && (int) ($old['expires'] ?? 0) < time()) {
            self::deleteOwnedOption($key, $old);
        }
        if (! add_option($key, $value, '', false)) {
            self::fail('פעולת הרשמה אחרת מתבצעת כעת לתלמיד זה. יש לנסות שוב מאוחר יותר.');
        }
        self::$activeLocks[$id] = $value;
        try {
            return $callback();
        } finally {
            unset(self::$activeLocks[$id]);
            self::deleteOwnedOption($key, $value);
        }
    }

    private static function assertOwnedLock(int $id): void
    {
        $expected = self::$activeLocks[$id] ?? null;
        if (! is_array($expected) || ($expected['expires'] ?? 0) <= time() || get_option('_multioto_ld_write_'.$id, null) !== $expected) {
            self::fail('נעילת ההרשמה פגה או הוחלפה. לא יבוצעו שינויים נוספים; יש לקרוא את החברות מחדש.');
        }
    }

    private static function deleteOwnedOption(string $key, array $value): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => maybe_serialize($value)]);
        wp_cache_delete($key, 'options');
    }

    private static function journalKey(string $id): string
    {
        return 'multioto_ld_change_'.$id;
    }

    private static function scope(): string
    {
        return home_url('/').':'.get_current_blog_id();
    }

    private static function bind(array $state, array $selector): void
    {
        if (($state['scope'] ?? '') !== self::scope() || ($state['selector'] ?? null) !== $selector) {
            self::fail('צילום החברות אינו תואם לתלמיד, ליעד או לאתר שנבחרו.');
        }
    }

    private static function seal(array $payload): array
    {
        if (! function_exists('openssl_encrypt') || ! function_exists('wp_salt')) {
            self::fail('אין מנגנון הצפנה זמין לצילום הרשמה.');
        }
        $plain = self::json($payload);
        if (strlen($plain) > self::MAX_BYTES) {
            self::fail('צילום הגישה גדול מדי לפעולה אחת.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag, 'multioto-learndash-v1');
        if ($encrypted === false) {
            self::fail('הצפנת צילום ההרשמה נכשלה.');
        }

        return ['version' => hash_hmac('sha256', $plain, wp_salt('auth')), 'token' => base64_encode($iv.$tag.$encrypted)];
    }

    private static function open($snapshot): array
    {
        if (! is_array($snapshot) || ! is_string($snapshot['token'] ?? null) || ! is_string($snapshot['version'] ?? null) || strlen($snapshot['token']) > self::MAX_BYTES * 2 || ! function_exists('openssl_decrypt') || ! function_exists('wp_salt')) {
            self::fail('צילום הרשמה חסר או לא תקין.');
        }
        $bytes = base64_decode($snapshot['token'], true);
        if ($bytes === false || strlen($bytes) < 29) {
            self::fail('צילום הרשמה אינו תקין.');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'multioto-learndash-v1');
        if ($plain === false || ! hash_equals(hash_hmac('sha256', $plain, wp_salt('auth')), $snapshot['version'])) {
            self::fail('אימות צילום ההרשמה נכשל.');
        }
        $payload = json_decode($plain, true);
        if (! is_array($payload)) {
            self::fail('תוכן צילום ההרשמה אינו תקין.');
        }

        return $payload;
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 32);
        if ($json === false) {
            self::fail('מבנה הגישה אינו ניתן לצילום.');
        }

        return $json;
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }
}
