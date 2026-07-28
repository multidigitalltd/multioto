<?php
/**
 * Plugin Name:       Multi Digital Agent
 * Plugin URI:        https://multidigital.co.il
 * Description:        מחבר את האתר לפאנל התפעול של Multi Digital: נקודת קצה MCP מאובטחת לאבחון ותיקון מרחוק.
 * Version:           1.0.15
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Multi Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       multioto-agent
 *
 * The agent NEVER acts on its own: this plugin only exposes a small, fixed set
 * of allow-listed tools that the Multi Digital platform calls after a manager
 * approved the exact action. Every request is authenticated with a per-site
 * shared secret. There is no arbitrary code/SQL/file execution.
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('MULTIOTO_AGENT_VERSION', '1.0.15');
define('MULTIOTO_AGENT_FILE', __FILE__);
define('MULTIOTO_AGENT_SLUG', 'multioto-agent');
define('MULTIOTO_AGENT_DIR', plugin_dir_path(__FILE__));

/*
 * Stop before loading anything on an unsupported PHP.
 *
 * A parse error inside an included file is fatal at compile time: it takes the
 * whole SITE down — front end and wp-admin alike — not just this plugin, and
 * the customer cannot even log in to deactivate it. WordPress honours the
 * "Requires PHP" header on activation and auto-update, but a site whose host
 * downgrades PHP afterwards gets no such protection. So the guard lives here,
 * in the only file that must always parse everywhere, and it is deliberately
 * written in the most conservative PHP possible.
 *
 * The agent simply goes quiet; the site keeps working.
 */
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', 'multioto_agent_php_notice');

    if (! function_exists('multioto_agent_php_notice')) {
        function multioto_agent_php_notice()
        {
            echo '<div class="notice notice-error"><p>'
                .'Multi Digital Agent requires PHP 7.4 or newer. This site runs PHP '
                .esc_html(PHP_VERSION).', so the plugin is idle. Ask your host to upgrade PHP.'
                .'</p></div>';
        }
    }

    return;
}

require_once MULTIOTO_AGENT_DIR.'includes/class-settings.php';
require_once MULTIOTO_AGENT_DIR.'includes/class-mcp-server.php';
require_once MULTIOTO_AGENT_DIR.'includes/class-updater.php';

add_action('plugins_loaded', static function (): void {
    (new Multioto_Agent_Settings)->boot();
    (new Multioto_Agent_Mcp_Server)->boot();
    (new Multioto_Agent_Updater)->boot();
});
