<?php

/** Isolated WordPress/LearnDash interoperability model; no Laravel or real site. */
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['ld_missing_functions'] = $GLOBALS['ld_missing_functions'] ?? [];
if (! in_array('LEARNDASH_VERSION', $GLOBALS['ld_missing_functions'], true)) {
    define('LEARNDASH_VERSION', '4.25.0');
}

class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}

class LearnDashFixtureUser
{
    public $ID;

    public $display_name;

    public $user_email;

    public function __construct($id, $name)
    {
        $this->ID = $id;
        $this->display_name = $name;
        $this->user_email = 'private'.$id.'@example.test';
    }

    public function has_cap($cap)
    {
        return in_array($cap, $GLOBALS['ld']['caps'][$this->ID] ?? [], true);
    }
}

class LearnDashFixtureDatabase
{
    public $options = 'wp_options';

    public $usermeta = 'wp_usermeta';

    public function esc_like($value)
    {
        return addcslashes($value, '_%\\');
    }

    public function prepare($query, ...$arguments)
    {
        return ['query' => $query, 'arguments' => $arguments];
    }

    public function get_results($prepared, $format)
    {
        $expected = 'SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = %d AND meta_key LIKE %s ORDER BY umeta_id ASC LIMIT 201';
        if (! is_array($prepared) || $prepared['query'] !== $expected || $format !== ARRAY_A
            || count($prepared['arguments']) !== 2 || ! is_int($prepared['arguments'][0])
            || $prepared['arguments'][1] !== 'learndash\\_group\\_users\\_%') {
            throw new RuntimeException('Only the bounded, prepared native group membership query is supported.');
        }
        $GLOBALS['ld']['group_meta_queries'][] = $prepared;
        if (array_key_exists('group_meta_result', $GLOBALS['ld'])) {
            return $GLOBALS['ld']['group_meta_result'];
        }
        $rows = [];
        foreach ($GLOBALS['ld']['meta'][$prepared['arguments'][0]] ?? [] as $key => $values) {
            if (strpos($key, 'learndash_group_users_') === 0) {
                foreach ($values as $value) {
                    $rows[] = ['meta_key' => $key, 'meta_value' => $value];
                }
            }
        }

        return array_slice($rows, 0, 201);
    }

    public function delete($table, $where)
    {
        $key = $where['option_name'];
        if (array_key_exists($key, $GLOBALS['ld']['options']) && maybe_serialize($GLOBALS['ld']['options'][$key]) === $where['option_value']) {
            unset($GLOBALS['ld']['options'][$key]);

            return 1;
        }

        return 0;
    }
}

function ld_fixture_reset(): void
{
    $GLOBALS['ld'] = [
        'posts' => [], 'users' => [], 'meta' => [], 'options' => [], 'caps' => [],
        'group_courses' => [20 => [10, 11]], 'settings' => [], 'modes' => [],
        'expired' => [], 'expiry' => [], 'legacy' => false, 'native_calls' => [],
        'user_queries' => [], 'expired_calls' => [], 'population' => null,
        'steps' => [10 => [100, 101, 102], 11 => [100]],
        'lessons' => [10 => [100], 11 => [100]], 'topics' => ['10:100' => [101], '11:100' => []],
        'progress' => ['7:10' => ['total' => 3, 'completed' => 1]],
        'attempts' => ['7:102' => [['score' => 82, 'completed_at' => 1700000000]]],
        'home' => 'https://learning.example.test/', 'blog' => 1, 'super_admins' => [],
        'non_members' => [], 'effective' => [],
    ];
    foreach ([10 => ['sfwd-courses', 'Course A'], 11 => ['sfwd-courses', 'Course B'], 20 => ['groups', 'Team A'], 100 => ['sfwd-lessons', 'Shared lesson'], 101 => ['sfwd-topic', 'Topic A'], 102 => ['sfwd-quiz', 'Quiz A']] as $id => $row) {
        $GLOBALS['ld']['posts'][$id] = (object) ['ID' => $id, 'post_type' => $row[0], 'post_title' => $row[1], 'post_status' => 'publish', 'post_parent' => 999];
    }
    foreach ([7 => 'Student One', 8 => 'Student Two', 9 => 'Administrator'] as $id => $name) {
        $GLOBALS['ld']['users'][$id] = new LearnDashFixtureUser($id, $name);
    }
    $GLOBALS['ld']['caps'][9] = ['manage_options'];
    $GLOBALS['wpdb'] = new LearnDashFixtureDatabase;
}

function get_post($id)
{
    return $GLOBALS['ld']['posts'][$id] ?? null;
}

function get_posts($args)
{
    $GLOBALS['ld']['post_query'] = $args;
    $rows = array_filter($GLOBALS['ld']['posts'], static function ($post) use ($args) {
        return $post->post_type === $args['post_type'] && in_array($post->post_status, $args['post_status'], true)
            && (($args['s'] ?? '') === '' || stripos($post->post_title, $args['s']) !== false);
    });
    ksort($rows);

    return array_slice(array_values($rows), $args['offset'] ?? 0, $args['numberposts']);
}

function get_userdata($id)
{
    return $GLOBALS['ld']['users'][$id] ?? null;
}

function get_users($args)
{
    $GLOBALS['ld']['user_queries'][] = $args;
    $ids = $GLOBALS['ld']['population'] ?? array_keys($GLOBALS['ld']['users']);
    if (! is_array($ids)) {
        return $ids;
    }
    $key = $args['meta_key'] ?? ($args['meta_query'][0]['key'] ?? null);
    $value = $args['meta_value'] ?? ($args['meta_query'][0]['value'] ?? null);
    if ($key !== null) {
        $ids = array_values(array_filter($ids, static function ($id) use ($key, $value) {
            $rows = get_user_meta($id, $key, false);

            return $rows !== [] && ($value === null || in_array((string) $value, $rows, true));
        }));
    }
    sort($ids);
    $ids = array_slice($ids, $args['offset'] ?? 0, $args['number'] ?? count($ids));

    return ($args['fields'] ?? '') === 'ID' ? $ids : array_map('get_userdata', $ids);
}

function get_user_meta($id, $key, $single = false)
{
    $rows = $GLOBALS['ld']['meta'][$id][$key] ?? [];

    return $single ? ($rows[0] ?? '') : $rows;
}

function update_user_meta($id, $key, $value, $previous = '')
{
    if (isset($GLOBALS['ld']['before_meta_update'])) {
        call_user_func($GLOBALS['ld']['before_meta_update'], $id, $key, $value, $previous);
    }
    $rows = get_user_meta($id, $key, false);
    $previous = is_scalar($previous) ? (string) $previous : $previous;
    if ($previous !== '' && $rows !== [$previous]) {
        return false;
    }
    if (! empty($GLOBALS['ld']['fail_meta_update'])) {
        return false;
    }
    $GLOBALS['ld']['meta'][$id][$key] = [is_scalar($value) ? (string) $value : $value];

    return true;
}

function get_post_meta($id, $key, $single = false)
{
    return $key === '_sfwd-courses' ? ($GLOBALS['ld']['settings'][$id] ?? []) : '';
}

function is_super_admin($id)
{
    return in_array($id, $GLOBALS['ld']['super_admins'], true);
}

function is_user_member_of_blog($id, $blog = 0)
{
    return ! in_array($id, $GLOBALS['ld']['non_members'], true);
}

function is_multisite()
{
    return $GLOBALS['ld']['multisite'] ?? false;
}

function get_option($key, $default = false)
{
    return $GLOBALS['ld']['options'][$key] ?? $default;
}

function add_option($key, $value, $deprecated = '', $autoload = true)
{
    if (isset($GLOBALS['ld']['before_add_option'])) {
        call_user_func($GLOBALS['ld']['before_add_option'], $key, $value);
    }
    if (array_key_exists($key, $GLOBALS['ld']['options']) || (! empty($GLOBALS['ld']['fail_journal_add']) && strpos($key, 'multioto_ld_change_') === 0)) {
        return false;
    }
    $GLOBALS['ld']['options'][$key] = $value;

    return true;
}

function update_option($key, $value, $autoload = null)
{
    if (! empty($GLOBALS['ld']['fail_journal_update']) && strpos($key, 'multioto_ld_change_') === 0) {
        return false;
    }
    $GLOBALS['ld']['options'][$key] = $value;

    return true;
}

function maybe_serialize($value)
{
    return is_array($value) || is_object($value) ? serialize($value) : $value;
}

function wp_cache_delete($key, $group = '') {}
function wp_slash($value)
{
    return $value;
}
function wp_salt($scheme = '')
{
    return 'fixture-site-secret-'.$GLOBALS['ld']['blog'];
}
function home_url($path = '')
{
    return $GLOBALS['ld']['home'];
}
function get_current_blog_id()
{
    return $GLOBALS['ld']['blog'];
}

if (! in_array('learndash_get_setting', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_setting($id, $name)
    {
        if ($name !== 'course_price_type') {
            throw new RuntimeException('Unverified LearnDash setting requested.');
        }

        return $GLOBALS['ld']['modes'][$id] ?? 'closed';
    }
}
if (! in_array('learndash_use_legacy_course_access_list', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_use_legacy_course_access_list()
    {
        return $GLOBALS['ld']['legacy'];
    }
}
if (! in_array('ld_course_access_expires_on', $GLOBALS['ld_missing_functions'], true)) {
    function ld_course_access_expires_on($course, $user)
    {
        return $GLOBALS['ld']['expiry'][$user.':'.$course] ?? 0;
    }
}
if (! in_array('learndash_get_users_group_ids', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_users_group_ids($user)
    {
        if (func_num_args() !== 1) {
            throw new RuntimeException('Only the one-argument group API was verified.');
        }
        $groups = [];
        foreach ($GLOBALS['ld']['meta'][$user] ?? [] as $key => $rows) {
            if (preg_match('/^learndash_group_users_(\d+)$/', $key, $match) && $rows !== []) {
                $groups[] = (int) $match[1];
            }
        }

        return $GLOBALS['ld']['group_override'][$user] ?? $groups;
    }
}
if (! in_array('learndash_group_enrolled_courses', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_group_enrolled_courses($group, $bypass = false)
    {
        return $GLOBALS['ld']['group_courses'][$group] ?? [];
    }
}
if (! in_array('learndash_get_groups_user_ids', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_groups_user_ids($group, $bypass = false)
    {
        $GLOBALS['ld']['unbounded_group_reads'] = ($GLOBALS['ld']['unbounded_group_reads'] ?? 0) + 1;

        return array_values(array_filter(array_keys($GLOBALS['ld']['users']), static function ($id) use ($group) {
            return get_user_meta($id, 'learndash_group_users_'.$group, false) !== [];
        }));
    }
}
if (! in_array('sfwd_lms_has_access', $GLOBALS['ld_missing_functions'], true)) {
    function sfwd_lms_has_access($course, $user)
    {
        if (array_key_exists($user.':'.$course, $GLOBALS['ld']['effective'])) {
            return $GLOBALS['ld']['effective'][$user.':'.$course];
        }
        $access = ($GLOBALS['ld']['modes'][$course] ?? 'closed') === 'open'
            || get_user_meta($user, 'course_'.$course.'_access_from', false) !== [];
        foreach (function_exists('learndash_get_users_group_ids') ? learndash_get_users_group_ids($user) : [] as $group) {
            $access = $access || in_array($course, $GLOBALS['ld']['group_courses'][$group] ?? [], true);
        }

        return $access;
    }
}
if (! in_array('learndash_get_course_expired_access_from_meta', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_course_expired_access_from_meta($course)
    {
        $GLOBALS['ld']['expired_calls'][] = $course;

        return $GLOBALS['ld']['expired'][$course] ?? [];
    }
}
function ld_fixture_native($kind, $user, $target, $remove): void
{
    $GLOBALS['ld']['native_calls'][] = [$kind, $user, $target, $remove];
    if (! empty($GLOBALS['ld']['native_noop'])) {
        return;
    }
    $key = $kind === 'course' ? 'course_'.$target.'_access_from' : 'learndash_group_users_'.$target;
    if ($remove) {
        unset($GLOBALS['ld']['meta'][$user][$key]);
    } else {
        $GLOBALS['ld']['meta'][$user][$key] = [(string) ($kind === 'course' ? time() : $target)];
    }
    if (isset($GLOBALS['ld']['after_native'])) {
        call_user_func($GLOBALS['ld']['after_native'], $kind, $user, $target, $remove);
    }
}
if (! in_array('ld_update_course_access', $GLOBALS['ld_missing_functions'], true)) {
    function ld_update_course_access($user, $course, $remove = false)
    {
        ld_fixture_native('course', $user, $course, $remove);
    }
}
if (! in_array('ld_update_group_access', $GLOBALS['ld_missing_functions'], true)) {
    function ld_update_group_access($user, $group, $remove = false)
    {
        ld_fixture_native('group', $user, $group, $remove);
    }
}
if (! in_array('learndash_user_get_course_progress', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_user_get_course_progress($user, $course, $scope)
    {
        if ($scope !== 'summary') {
            throw new RuntimeException('Only the verified progress summary is supported.');
        }

        return $GLOBALS['ld']['progress'][$user.':'.$course] ?? ['total' => 0, 'completed' => 0];
    }
}
if (! in_array('learndash_get_course_steps', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_course_steps($course)
    {
        return $GLOBALS['ld']['steps'][$course] ?? [];
    }
}
if (! in_array('learndash_get_lesson_list', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_lesson_list($course, $args)
    {
        return array_map('get_post', $GLOBALS['ld']['lessons'][$course] ?? []);
    }
}
if (! in_array('learndash_get_topic_list', $GLOBALS['ld_missing_functions'], true)) {
    function learndash_get_topic_list($lesson, $course)
    {
        return array_map('get_post', $GLOBALS['ld']['topics'][$course.':'.$lesson] ?? []);
    }
}

ld_fixture_reset();
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-learndash.php';

/** Real provider output consumed by the Laravel contract test in a subprocess. */
function ld_fixture_export(): array
{
    $call = static function ($tool, $args = []) {
        return Multioto_Agent_LearnDash::call($tool, $args);
    };
    $selector = ['user_id' => 7, 'kind' => 'course', 'target_id' => 10];
    $out = [
        'selector' => $selector, 'capabilities' => $call('ld_capabilities'),
        'courses' => $call('ld_courses_list'), 'groups' => $call('ld_groups_list'),
        'course' => $call('ld_course_get', ['course_id' => 10]),
        'group' => $call('ld_group_get', ['group_id' => 20]),
        'student' => $call('ld_student_course_get', ['user_id' => 7, 'course_id' => 10]),
    ];
    $out['membership'] = $call('ld_membership_get', $selector);
    $out['prepared'] = $call('ld_membership_prepare', $selector + ['action' => 'add', 'expected' => $out['membership']['snapshot']]);
    $out['applied'] = $call('ld_membership_apply', $selector + ['expected' => $out['prepared']['expected'], 'prepared' => $out['prepared']['prepared']]);
    $out['reverted'] = $call('ld_membership_revert', $selector + ['expected' => $out['applied']['after'], 'restore' => $out['applied']['before']]);
    $group = ['user_id' => 7, 'kind' => 'group', 'target_id' => 20];
    $GLOBALS['ld']['meta'][7]['learndash_group_users_20'] = ['20'];
    $out['group_selector'] = $group;
    $out['group_membership'] = $call('ld_membership_get', $group);
    $out['group_prepared'] = $call('ld_membership_prepare', $group + ['action' => 'remove', 'expected' => $out['group_membership']['snapshot']]);
    $out['group_applied'] = $call('ld_membership_apply', $group + ['expected' => $out['group_prepared']['expected'], 'prepared' => $out['group_prepared']['prepared']]);
    $out['group_reverted'] = $call('ld_membership_revert', $group + ['expected' => $out['group_applied']['after'], 'restore' => $out['group_applied']['before']]);

    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && in_array($argv[1] ?? '', ['export', 'app_contract'], true)) {
    echo json_encode(ld_fixture_export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
