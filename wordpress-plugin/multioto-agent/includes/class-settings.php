<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Connection settings for the Multi Digital platform:
 *  - mcp_secret:    the shared secret the platform presents when it calls us.
 *  - update_token:  the token we present to the platform for self-updates.
 *
 * Secrets are stored non-autoloaded and never printed back into the form.
 *
 * The panel address is NOT one of them. It is the same address for every
 * installation, so it is a constant here rather than a field: a value that never
 * differs is a field whose only possible use is to be filled in wrong.
 */
class Multioto_Agent_Settings
{
    private const OPTION = 'multioto_agent_settings';

    /**
     * The Multi Digital panel address. It is always the same, so the plugin
     * ships with it pre-configured — a manager never has to enter or copy it.
     */
    public const DEFAULT_PLATFORM_URL = 'https://app.multidigital.co.il';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'register']);

        // Codes saved for the first time, or changed: tell the panel at once.
        add_action('add_option_'.self::OPTION, [$this, 'afterSave'], 10, 0);
        add_action('update_option_'.self::OPTION, [$this, 'afterSave'], 10, 0);
    }

    public function afterSave(): void
    {
        Multioto_Agent_Updater::checkInNow();
    }

    /** @return array{platform_url:string,mcp_secret:string,update_token:string} */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return [
            // Always the constant, never what the option happens to hold.
            //
            // Deliberately ignoring a stored value rather than preferring it:
            // the field that used to write one is gone, so anything still in
            // there was typed by somebody or left by an older version, and the
            // update token is presented to whatever this address names. A value
            // we no longer offer any way to correct must not be the one the
            // plugin trusts.
            'platform_url' => self::platformUrl(),
            'mcp_secret' => (string) ($stored['mcp_secret'] ?? ''),
            'update_token' => (string) ($stored['update_token'] ?? ''),
        ];
    }

    /**
     * The panel this installation talks to.
     *
     * The constant, unless wp-config.php defines MULTIOTO_PLATFORM_URL — the one
     * escape hatch, for pointing a staging site at a staging panel. It lives in
     * wp-config rather than in the database on purpose: editing it needs file
     * access to the server, so no admin session and no stored option can redirect
     * where the update token is sent. Anything that is not an http(s) address is
     * ignored outright.
     */
    public static function platformUrl(): string
    {
        if (defined('MULTIOTO_PLATFORM_URL')) {
            $override = untrailingslashit(trim((string) constant('MULTIOTO_PLATFORM_URL')));

            if (preg_match('#^https?://#i', $override) === 1 && esc_url_raw($override) === $override) {
                return $override;
            }
        }

        return self::DEFAULT_PLATFORM_URL;
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

        $secret = trim((string) ($input['mcp_secret'] ?? ''));
        $token = trim((string) ($input['update_token'] ?? ''));

        // `platform_url` is not read from the input at all, and is not written
        // back either. Dropping only the form row would leave the key still
        // accepted here, so a crafted POST to options.php could point the plugin
        // — and the update token it presents — at an address of its own choosing.
        return [
            'mcp_secret' => $secret !== '' ? $secret : $current['mcp_secret'],
            'update_token' => $token !== '' ? $token : $current['update_token'],
        ];
    }

    /**
     * What the last contact with the panel says, in words an owner can act on.
     *
     * "מחובר" used to mean only that a key had been pasted — a key nobody had
     * yet checked. Now it means the panel answered.
     */
    private function statusLine(bool $hasSecret): string
    {
        if (! $hasSecret) {
            return '⚠️ לא מחובר — הדביקו את הקודים מהאזור האישי ושמרו.';
        }

        $last = Multioto_Agent_Updater::lastCheckIn();
        $status = $last !== null ? (string) $last['status'] : '';

        if ($status === 'ok') {
            return '✅ מחובר לפאנל';
        }

        if ($status === 'rejected') {
            return '⚠️ הפאנל לא זיהה את טוקן העדכון. העתיקו אותו שוב מהאזור האישי, בלי רווחים, ושמרו.';
        }

        if ($status === 'unreachable') {
            return '⚠️ לא הצלחנו להגיע לפאנל מהשרת של האתר. ייתכן שחומת אש או תוסף אבטחה חוסמים בקשות יוצאות.';
        }

        if ($status === 'no_token') {
            return '⚠️ מפתח MCP נשמר, אבל חסר טוקן עדכון — בלעדיו הפאנל לא יודע שהאתר הותקן.';
        }

        // Saved by a version before the check-in on save existed: the next
        // scheduled update check reports in by itself.
        return '⏳ הקודים נשמרו. החיבור ייבדק אוטומטית בשעות הקרובות.';
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $current = self::get();

        // The secret alone. The panel address is a constant now, so including it
        // in the test was asking whether a hard-coded string is non-empty — a
        // condition that cannot fail, and so says nothing.
        $connected = $current['mcp_secret'] !== '';
        ?>
        <div class="wrap" dir="rtl" style="text-align:right;">
            <h1>Multi Digital Agent</h1>
            <p>חיבור האתר לפאנל התפעול של Multi Digital. הערכים מתקבלים מהצוות בעת חיבור האתר.</p>
            <p><strong>סטטוס:</strong> <?php echo esc_html($this->statusLine($connected)); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
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
