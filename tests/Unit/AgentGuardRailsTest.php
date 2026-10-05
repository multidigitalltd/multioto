<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * הגבולות שהתוסף אוכף על רשימת ה"מחק מיד" שהפאנל שולח לו.
 *
 * מ-1.7.0 הפאנל יכול לבקש מאתר למחוק שם שלא היה מקובע בקוד, וזה ויתור על הבטחה
 * שהקובץ הזה נתן קודם: שפאנל פרוץ לחלוטין אינו יכול להורות על מחיקה אחת שלא
 * נכתבה מראש. מה שהוחלף במקומה נאכף **באתר**, במקום שאף הוראה אינה מגיעה אליו —
 * ולכן הוא נבדק כאן בהרצה אמיתית ולא בקריאת הקוד כטקסט. בדיקה שקוראת מקור
 * ומאשרת ש"הקובץ מזכיר את הרשימה האסורה" עוברת בשמחה גם כשהתנאי עצמו יושב
 * מאחורי if שאיש לא מגיע אליו.
 *
 * הסביבה היא מינימום וורדפרס מזויף (tests/Support/wordpress-guard-stubs.php):
 * אפשר להרכיב אתר, להריץ את השומר עליו, ולקרוא מה הוא עשה.
 */
class AgentGuardRailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Support/wordpress-guard-stubs.php';

        multioto_guard_reset();
    }

    private function guard(): \Multioto_Agent_Guard
    {
        return new \Multioto_Agent_Guard;
    }

    private function user(int $id, string $login, array $roles = ['subscriber']): void
    {
        $GLOBALS['mg_users'][] = new \WP_User($id, $login, $roles);
    }

    /** An installed plugin: a directory, and a header the plugin list reports. */
    private function installPlugin(string $slug): void
    {
        @mkdir(WP_PLUGIN_DIR.'/'.$slug, 0777, true);
        file_put_contents(WP_PLUGIN_DIR.'/'.$slug.'/'.$slug.'.php', "<?php\n");
        $GLOBALS['mg_plugins'][$slug.'/'.$slug.'.php'] = ['Name' => $slug];
    }

    /** @return list<array<string, mixed>> */
    private function log(): array
    {
        return array_values((array) get_option(\Multioto_Agent_Guard::LOG_OPTION, []));
    }

    // ---- the list the panel sends -------------------------------------------

    /** מה שהפאנל שולח נשמר, ומה שהוא שולח ואסור — נדחה ומדווח. */
    public function test_a_pushed_list_is_stored_and_the_rest_is_refused(): void
    {
        $result = $this->guard()->setPushedRules(
            ['Intruder', 'bad user', '', 'intruder'],
            ['wp-shell-kit', '../../../etc', 'a/b', 'WP-Backdoor'],
        );

        $this->assertSame(['intruder'], $result['users']);
        $this->assertSame(['wp-shell-kit', 'wp-backdoor'], $result['plugins']);

        // Named, not silently dropped: a name that will never be enforced must
        // not sit on the panel's screen looking as though it is.
        $this->assertContains('bad user', $result['refused']);
        $this->assertContains('../../../etc', $result['refused']);
        $this->assertContains('a/b', $result['refused']);
    }

    /** הרשימה מוגבלת בגודל, והעודף נדחה במקום להישמר. */
    public function test_the_list_is_capped(): void
    {
        $many = [];
        for ($i = 0; $i < \Multioto_Agent_Guard::PUSHED_MAX + 10; $i++) {
            $many[] = 'plugin-'.$i;
        }

        $result = $this->guard()->setPushedRules([], $many);

        $this->assertCount(\Multioto_Agent_Guard::PUSHED_MAX, $result['plugins']);
        $this->assertCount(10, $result['refused']);
    }

    /**
     * רשימה שנערכה ישירות במסד הנתונים אינה נקראת על אמון.
     *
     * זו שורה ב-wp_options, כלומר כל מי שיש לו גישה למסד — פורץ כולל — יכול
     * לכתוב אותה. הרשימה הזאת מחליטה מה נמחק, ולכן היא נבדקת גם בקריאה.
     */
    public function test_a_tampered_option_is_re_validated_on_the_way_out(): void
    {
        update_option(\Multioto_Agent_Guard::PUSHED_OPTION, [
            'users' => ['owner'],
            'plugins' => ['../../wp-content', 'multioto-agent'],
        ], false);

        $this->installPlugin('wp-content');
        $this->user(5, 'owner', ['administrator']);
        $this->user(6, 'second', ['administrator']);

        $actions = $this->guard()->sweep();

        // 'owner' is a validly shaped login, so it is enforced: deciding to
        // delete an account by name is the panel's call to make. What the rail
        // stops is the entry that is not a name at all — it never becomes a rule,
        // so no action mentions it and the directory it points at is untouched.
        $this->assertSame([5], array_column($GLOBALS['mg_deleted'], 'id'));
        $this->assertDirectoryExists(WP_PLUGIN_DIR.'/wp-content');
        $this->assertNotContains('../../wp-content', array_column($actions, 'target'));

        // And the agent's own slug, written in by hand, is refused as always.
        $this->assertNotContains('removed', array_column(
            array_values(array_filter($actions, static fn (array $a): bool => $a['target'] === 'multioto-agent')),
            'result',
        ));
    }

    // ---- the never-lists ----------------------------------------------------

    /** סוכן הניטור עצמו אינו נמחק, גם כשהפאנל מבקש במפורש. */
    public function test_the_agent_plugin_is_never_removed(): void
    {
        $this->installPlugin('multioto-agent');
        $this->guard()->setPushedRules([], ['multioto-agent']);

        $this->guard()->sweep();

        $this->assertDirectoryExists(WP_PLUGIN_DIR.'/multioto-agent');
        $this->assertSame([], $GLOBALS['mg_deactivated']);

        $entry = $this->log()[0] ?? null;
        $this->assertNotNull($entry);
        $this->assertSame('skipped', $entry['result']);
        $this->assertStringContainsString('סוכן הניטור עצמו', $entry['detail']);
    }

    /** המשתמש הראשון באתר אינו נמחק מכלל שהגיע מהפאנל. */
    public function test_the_first_account_survives_a_panel_rule(): void
    {
        $this->user(1, 'owner', ['administrator']);
        $this->user(2, 'editor', ['editor']);
        $this->guard()->setPushedRules(['owner'], []);

        $this->guard()->sweep();

        $this->assertSame([], $GLOBALS['mg_deleted']);

        $entry = $this->log()[0] ?? null;
        $this->assertSame('skipped', $entry['result']);
        $this->assertSame('panel', $entry['source']);
        $this->assertStringContainsString('החשבון הראשון', $entry['detail']);
    }

    /**
     * ומנגד — אותו גבול אינו מרפה את הרשימה המקובעת.
     *
     * ההגנה על חשבון #1 היא נגד הוראה שהגיעה ברשת. `sys_maint` כחשבון #1 הוא
     * אתר שנבנה סביב חשבון של פורץ, והרשימה המקובעת היא בדיוק המקרה שבו אין
     * ספק.
     */
    public function test_the_built_in_list_is_not_weakened_by_that_rail(): void
    {
        $this->user(1, 'sys_maint', ['administrator']);
        $this->user(2, 'owner', ['administrator']);

        $this->guard()->sweep();

        $this->assertSame([1], array_column($GLOBALS['mg_deleted'], 'id'));
        $this->assertSame('builtin', $this->log()[0]['source']);
    }

    /** המנהל האחרון אינו נמחק, מאיזו רשימה שלא יהיה. */
    public function test_the_last_administrator_is_never_deleted(): void
    {
        $this->user(7, 'intruder', ['administrator']);
        $this->guard()->setPushedRules(['intruder'], []);

        $this->guard()->sweep();

        $this->assertSame([], $GLOBALS['mg_deleted']);
        $this->assertStringContainsString('מנהל האתר היחיד', $this->log()[0]['detail']);
    }

    /**
     * הוראה למחוק הכול מקבלת כמה ודיווח, ולא אתר.
     *
     * הגבול הזה אינו הגנה מפני הוראה ממוקדת — שום דבר כאן אינו — אבל המקרה
     * הקטסטרופלי הוא דווקא הסיטוני: פאנל שהשתלטו עליו ומורה למחוק את כל
     * החשבונות באתר.
     */
    public function test_a_bulk_order_is_capped_per_sweep(): void
    {
        $logins = [];
        for ($i = 1; $i <= 12; $i++) {
            $this->user(100 + $i, 'victim'.$i, ['editor']);
            $logins[] = 'victim'.$i;
        }
        $this->user(1, 'owner', ['administrator']);

        $this->guard()->setPushedRules($logins, []);
        $this->guard()->sweep();

        $this->assertCount(\Multioto_Agent_Guard::PUSHED_PER_SWEEP, $GLOBALS['mg_deleted']);

        // And it says so, naming what it did not get to.
        $stopped = array_values(array_filter($this->log(), static fn (array $e): bool => $e['result'] === 'skipped'));
        $this->assertNotEmpty($stopped);
        $this->assertStringContainsString('הסריקה עצרה', $stopped[0]['detail']);
    }

    /**
     * והמובנים מטופלים לפני כל זה.
     *
     * אחרת הוראה עם חמישים שמות הייתה דוחקת את ההסרה שהקובץ הזה נכתב בשבילה אל
     * מחוץ לתקציב — פורץ שמוסיף שמות לרשימה היה קונה לעצמו את sys_maint.
     */
    public function test_the_built_ins_are_never_crowded_out_by_the_cap(): void
    {
        $this->user(1, 'owner', ['administrator']);
        $this->user(2, 'sys_maint', ['administrator']);

        $logins = [];
        for ($i = 1; $i <= 20; $i++) {
            $this->user(200 + $i, 'victim'.$i, ['editor']);
            $logins[] = 'victim'.$i;
        }

        $this->guard()->setPushedRules($logins, []);
        $this->guard()->sweep();

        $this->assertContains(2, array_column($GLOBALS['mg_deleted'], 'id'), 'sys_maint must still be removed');
    }

    // ---- what the panel reads back ------------------------------------------

    /** מה שהאתר מחזיק, ומה שנמצא בו, כולל כללי הפאנל. */
    public function test_the_status_reports_the_stored_list_and_what_is_present(): void
    {
        $this->installPlugin('wp-shell-kit');
        $this->user(1, 'owner', ['administrator']);
        $this->user(9, 'intruder', ['editor']);

        $this->guard()->setPushedRules(['intruder'], ['wp-shell-kit']);

        $status = $this->guard()->status();

        $this->assertSame(['users' => ['intruder'], 'plugins' => ['wp-shell-kit']], $status['panel_rules']);
        $this->assertSame(['users' => ['sys_maint'], 'plugins' => ['wp-file-manager']], $status['quarantine']);
        $this->assertContains('intruder', $status['present']['users']);
        $this->assertContains('wp-shell-kit', $status['present']['plugins']);
        $this->assertSame(\Multioto_Agent_Guard::PUSHED_MAX, $status['panel_limits']['max']);
    }

    /** כלל מהפאנל שנמחק — נרשם, ומסומן שהפאנל הורה עליו. */
    public function test_a_panel_removal_is_logged_as_coming_from_the_panel(): void
    {
        $this->installPlugin('wp-shell-kit');
        $this->guard()->setPushedRules([], ['wp-shell-kit']);

        $this->guard()->sweep();

        $entry = $this->log()[0];
        $this->assertSame('removed', $entry['result']);
        $this->assertSame('panel', $entry['source']);
        $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR.'/wp-shell-kit');
    }

    /** רשימה ריקה מבטלת — מה שהוסר מהפאנל מפסיק להיאכף באתר. */
    public function test_an_empty_push_clears_the_list(): void
    {
        $this->guard()->setPushedRules(['intruder'], ['wp-shell-kit']);
        $this->guard()->setPushedRules([], []);

        $status = $this->guard()->status();

        $this->assertSame(['users' => [], 'plugins' => []], $status['panel_rules']);
    }

    /** תוסף שמופעל ושמו ברשימת הפאנל מוסר באותה בקשה — זה ה"מיד". */
    public function test_activating_a_listed_plugin_removes_it_on_the_spot(): void
    {
        $this->installPlugin('wp-shell-kit');
        $this->guard()->setPushedRules([], ['wp-shell-kit']);

        $this->guard()->onPluginActivated('wp-shell-kit/wp-shell-kit.php');

        $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR.'/wp-shell-kit');
    }

    /** ומשתמש שנרשם ושמו ברשימה — אותו דבר. */
    public function test_registering_a_listed_login_removes_it_on_the_spot(): void
    {
        $this->user(1, 'owner', ['administrator']);
        $this->user(42, 'intruder', ['subscriber']);
        $this->guard()->setPushedRules(['intruder'], []);

        $this->guard()->onUserRegister(42);

        $this->assertSame([42], array_column($GLOBALS['mg_deleted'], 'id'));
    }
}
