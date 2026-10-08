<?php

/** Minimal WordPress surfaces for the administration adapter's behavior tests. */
define('ABSPATH', '/tmp/multioto-administration-test/');

class Multioto_Agent_Rpc_Error extends Exception
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}

class WP_User
{
    public int $ID = 7;

    public array $roles = ['editor'];

    public array $caps = [];

    public string $display_name = 'Editor';

    public string $first_name = 'First';

    public string $last_name = 'Last';

    public string $description = 'Bio';

    public string $user_email = 'private@example.test';

    public string $user_pass = 'never-return-this';
}

class WP_Theme
{
    public bool $installed = true;

    public bool $broken = false;

    public bool $allowed = true;

    public ?WP_Theme $parentTheme = null;

    public array $headers = [];

    public function exists(): bool
    {
        return $this->installed;
    }

    public function errors(): bool
    {
        return $this->broken;
    }

    public function parent(): WP_Theme|false
    {
        return $this->parentTheme ?? false;
    }

    public function get(string $header): string
    {
        return $this->headers[$header] ?? '';
    }

    public function is_allowed(): bool
    {
        return $this->allowed;
    }
}

function get_user_by($field, $id): WP_User|false
{
    return $GLOBALS['ma_users'][(int) $id] ?? false;
}

function user_can($user, $capability): bool
{
    return in_array($capability, $user->caps, true);
}

function is_multisite(): bool
{
    return $GLOBALS['ma_multisite'];
}

function is_super_admin($id): bool
{
    return in_array($id, $GLOBALS['ma_superadmins'], true);
}

function wp_kses_data($value): string
{
    return strip_tags($value, '<b><strong><i><em>');
}

function sanitize_text_field($value): string
{
    return trim(strip_tags($value));
}

function wp_update_user($values): int
{
    $GLOBALS['ma_user_writes'][] = $values;
    $user = $GLOBALS['ma_users'][$values['ID']];
    foreach ($values as $field => $value) {
        $user->$field = $value;
    }
    if ($GLOBALS['ma_filter_description']) {
        $user->description = strtoupper($user->description);
    }

    return $values['ID'];
}

function is_wp_error($value): bool
{
    return false;
}

function get_stylesheet(): string
{
    return $GLOBALS['ma_stylesheet'];
}

function wp_get_theme($stylesheet = ''): WP_Theme
{
    if (isset($GLOBALS['ma_themes'][$stylesheet])) {
        return $GLOBALS['ma_themes'][$stylesheet];
    }
    $missing = new WP_Theme;
    $missing->installed = false;

    return $missing;
}

function switch_theme($stylesheet): void
{
    $GLOBALS['ma_theme_writes'][] = $stylesheet;
    if ($GLOBALS['ma_switch_succeeds']) {
        $GLOBALS['ma_stylesheet'] = $stylesheet;
    }
}

function get_bloginfo($name): string
{
    return '6.9';
}

require_once __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-site-administration.php';
