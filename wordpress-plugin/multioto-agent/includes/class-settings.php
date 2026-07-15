<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connection settings for the Multi Digital platform:
 *  - platform_url:  the panel base URL (for self-updates).
 *  - mcp_secret:    the shared secret the platform presents when it calls us.
 *  - update_token:  the token we present to the platform for self-updates.
 *
 * Secrets are stored non-autoloaded and never printed back into the form.
 */
class Multioto_Agent_Settings
{
    private const OPTION = 'multioto_agent_settings';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'register']);
    }

    /** @return array{platform_url:string,mcp_secret:string,update_token:string} */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return [
            'platform_url' => (string) ($stored['platform_url'] ?? ''),
            'mcp_secret' => (string) ($stored['mcp_secret'] ?? ''),
            'update_token' => (string) ($stored['update_token'] ?? ''),
        ];
    }

    public function addMenu(): void
    {
        add_options_page(
            'Multi Digital Agent',
            'Multi Digital Agent',
            'manage_options',
            self::OPTION,
            [$this, 'render'],
        );
    }

    public function register(): void
    {
        register_setting(self::OPTION, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    /**
     * Trim + validate. A blank secret/token field means "keep the current
     * value" so the stored secrets are never wiped by re-saving the page.
     *
     * @param  mixed  $input
     * @return array<string,string>
     */
    public function sanitize($input): array
    {
        $current = self::get();
        $input = is_array($input) ? $input : [];

        $url = esc_url_raw(trim((string) ($input['platform_url'] ?? '')));
        $secret = trim((string) ($input['mcp_secret'] ?? ''));
        $token = trim((string) ($input['update_token'] ?? ''));

        return [
            'platform_url' => $url !== '' ? untrailingslashit($url) : $current['platform_url'],
            'mcp_secret' => $secret !== '' ? $secret : $current['mcp_secret'],
            'update_token' => $token !== '' ? $token : $current['update_token'],
        ];
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $current = self::get();
        $connected = $current['mcp_secret'] !== '' && $current['platform_url'] !== '';
        ?>
        <div class="wrap" dir="rtl" style="text-align:right;">
            <h1>Multi Digital Agent</h1>
            <p>חיבור האתר לפאנל התפעול של Multi Digital. הערכים מתקבלים מהצוות בעת חיבור האתר.</p>
            <p><strong>סטטוס:</strong> <?php echo $connected ? '✅ מחובר' : '⚠️ לא מחובר'; ?></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mo_url">כתובת הפאנל</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION); ?>[platform_url]" id="mo_url" type="url"
                                   class="regular-text" value="<?php echo esc_attr($current['platform_url']); ?>"
                                   placeholder="https://panel.multidigital.co.il"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mo_secret">מפתח MCP</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION); ?>[mcp_secret]" id="mo_secret" type="password"
                                   class="regular-text" autocomplete="new-password" placeholder="<?php echo $current['mcp_secret'] !== '' ? '•••••••• (נשמר)' : ''; ?>">
                            <p class="description">משמש לאימות בקשות מהפאנל. השאירו ריק כדי לא לשנות.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mo_token">טוקן עדכון</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION); ?>[update_token]" id="mo_token" type="password"
                                   class="regular-text" autocomplete="new-password" placeholder="<?php echo $current['update_token'] !== '' ? '•••••••• (נשמר)' : ''; ?>">
                            <p class="description">משמש לעדכון עצמי של התוסף מהפאנל. השאירו ריק כדי לא לשנות.</p></td>
                    </tr>
                </table>
                <?php submit_button('שמירה'); ?>
            </form>
            <p class="description">גרסת התוסף: <?php echo esc_html(MULTIOTO_AGENT_VERSION); ?></p>
        </div>
        <?php
    }
}
