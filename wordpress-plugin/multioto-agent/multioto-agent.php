<?php
/**
 * Plugin Name:       Multi Digital Agent
 * Plugin URI:        https://multidigital.co.il
 * Description:        מחבר את האתר לפאנל התפעול של Multi Digital: נקודת קצה MCP מאובטחת לאבחון ותיקון מרחוק.
 * Version:           1.0.11
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

define('MULTIOTO_AGENT_VERSION', '1.0.11');
define('MULTIOTO_AGENT_FILE', __FILE__);
define('MULTIOTO_AGENT_SLUG', 'multioto-agent');
define('MULTIOTO_AGENT_DIR', plugin_dir_path(__FILE__));

require_once MULTIOTO_AGENT_DIR.'includes/class-settings.php';
require_once MULTIOTO_AGENT_DIR.'includes/class-mcp-server.php';
require_once MULTIOTO_AGENT_DIR.'includes/class-updater.php';

add_action('plugins_loaded', static function (): void {
    (new Multioto_Agent_Settings)->boot();
    (new Multioto_Agent_Mcp_Server)->boot();
    (new Multioto_Agent_Updater)->boot();
});
