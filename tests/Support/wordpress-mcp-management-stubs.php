<?php

/** Run the actual plugin bootstrap and MCP dispatch without a WordPress DB. */
define('ABSPATH', '/tmp/multioto-mcp-test/');

class WP_REST_Request
{
    public function __construct(private array $body, private string $authorization = '') {}

    public function get_json_params(): array
    {
        return $this->body;
    }

    public function get_header($name): string
    {
        return $name === 'authorization' ? $this->authorization : '';
    }
}

class WP_REST_Response
{
    public function __construct(private ?array $data, private int $status = 200) {}

    public function get_data(): ?array
    {
        return $this->data;
    }
}

function plugin_dir_path($file): string
{
    return dirname($file).'/';
}

function add_action($hook, $callback, $priority = 10, $args = 1): bool
{
    return true;
}

function did_action($hook): int
{
    return 0;
}

function register_rest_route($namespace, $route, $options): void
{
    $GLOBALS['mm_routes'][$namespace.$route] = $options;
}

function get_option($name, $default = false)
{
    return $GLOBALS['mm_options'][$name] ?? $default;
}

function update_option($name, $value): bool
{
    $GLOBALS['mm_options'][$name] = $value;

    return true;
}

function sanitize_text_field($value): string
{
    return trim(strip_tags($value));
}

function wp_json_encode($data, $options = 0): string
{
    return json_encode($data, $options | JSON_THROW_ON_ERROR);
}

function get_stylesheet(): string
{
    return 'original';
}

function wp_get_theme($stylesheet)
{
    return new class
    {
        public function get($name): string
        {
            return 'Original';
        }
    };
}

function wc_get_product($id)
{
    return null;
}

require_once __DIR__.'/../../wordpress-plugin/multioto-agent/multioto-agent.php';
