<?php

/** Isolated WordPress user API fixture exercising the shipped native handler. */
define('ABSPATH', __DIR__);

class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}

class WP_User
{
    public string $user_login = 'riki';

    public string $user_email = 'riki@example.test';

    public string $display_name = 'Riki';

    public string $user_registered = '2026-01-01 00:00:00';

    public string $user_pass = 'PRIVATE-PASSWORD-HASH';

    public function __construct(public int $ID, public array $roles) {}
}

$GLOBALS['users'] = [1 => new WP_User(1, ['administrator']), 7 => new WP_User(7, ['editor']),
    8 => new WP_User(8, ['author', 'subscriber']), 100001 => new WP_User(100001, ['customer'])];
$GLOBALS['lookups'] = [];

function get_user_by($field, $id)
{
    if ($field !== 'id') {
        throw new RuntimeException('Exact lookup required');
    }
    $GLOBALS['lookups'][] = $id;

    return $GLOBALS['users'][$id] ?? false;
}
function get_current_blog_id()
{
    return 2;
}
function is_user_member_of_blog($id, $blog)
{
    return $blog === 2 && $id !== 100001;
}
function get_user_meta($id)
{
    return ['approval_status' => ['PRIVATE-META-VALUE'], 'wp_capabilities' => ['PRIVATE-CAPABILITIES']];
}
function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags | JSON_THROW_ON_ERROR);
}

require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-users.php';

$out = [];
foreach ([1, 7, 8] as $id) {
    $out['users'][$id] = json_decode(Multioto_Agent_Users::getUser(['user_id' => $id]), true, 512, JSON_THROW_ON_ERROR);
}
foreach (['missing' => [], 'negative' => ['user_id' => -1], 'fractional' => ['user_id' => 1.5], 'text' => ['user_id' => '1'],
    'unknown' => ['user_id' => 99], 'other_site' => ['user_id' => 100001]] as $key => $args) {
    try {
        Multioto_Agent_Users::getUser($args);
        $out['errors'][$key] = null;
    } catch (Multioto_Agent_Rpc_Error $e) {
        $out['errors'][$key] = ['code' => $e->getCode(), 'message' => $e->getMessage()];
    }
}
$out['lookups'] = $GLOBALS['lookups'];
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
