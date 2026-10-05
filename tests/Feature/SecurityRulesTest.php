<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\SecurityPosture;
use App\Jobs\PurgeSiteThreatsJob;
use App\Models\SecurityRule;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\ThreatQuarantine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * כללי מעקב שהצוות מוסיף מהפאנל.
 *
 * שני השמות שנולדו עם המערכת נמצאו בדרך הקשה ומקובעים בקוד. כל מה שהצוות לומד
 * אחר כך — התוסף שצץ אצל שני לקוחות בחודש שעבר, שם המשתמש שתוקף אוהב — לא היה
 * לו לאן ללכת חוץ מקובץ הגדרות וגרסה, ובפועל זה אומר שהוא לא נרשם בכלל.
 *
 * הקו שאסור לחצות: מחיקה אוטומטית נקבעת בתוסף שבאתר, מרשימה מקובעת שם, כדי
 * ששום דבר שנשלח ברשת לא יוכל להרחיב את מה שאתר מוחק. כלל שנוסף כאן מוצא
 * ומדווח — ולא מוחק.
 */
class SecurityRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function save(array $rules): void
    {
        Livewire::test(SecurityPosture::class)
            ->callAction('manageRules', data: ['rules' => $rules])
            ->assertHasNoActionErrors();
    }

    public function test_a_rule_added_in_the_panel_is_watched_for(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'נמצא אצל שני לקוחות', 'enabled' => true],
        ]);

        $this->assertContains('wp-shell-kit', ThreatQuarantine::plugins());
        // The built-ins are never replaced by what the team adds.
        $this->assertContains('wp-file-manager', ThreatQuarantine::plugins());
    }

    /**
     * הקו שאסור לחצות, בבדיקה.
     *
     * הרשימה שמפעילה מחיקה אוטומטית היא זו שבתוסף, והיא נקראת כאן מהקונפיג.
     * אם כלל שנוסף בפאנל היה נכנס אליה, פאנל שנפרץ היה יכול להורות למחוק את
     * חשבונות המנהל של כל הלקוחות.
     */
    public function test_a_panel_rule_never_widens_what_a_site_deletes_by_itself(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::USER, 'value' => 'admin', 'note' => 'בדיקה', 'enabled' => true],
        ]);

        $this->assertNotContains('admin', ThreatQuarantine::builtInUsers());
        $this->assertSame(['sys_maint'], ThreatQuarantine::builtInUsers());
    }

    /** מסך הכללים אומר במפורש שכלל שנוסף אינו נמחק אוטומטית. */
    public function test_the_screen_says_a_added_rule_is_reported_not_deleted(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);

        $own = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'תוספים שהוספתם למעקב');

        $this->assertNotNull($own);
        $this->assertStringContainsString('מדווח בלבד', $own['detail']);
        $this->assertStringNotContainsString('(מחק מיד)', $own['detail']);
    }

    /**
     * הכרטיס שמבטיח מחיקה אוטומטית מונה רק את המובנים.
     *
     * זו הטעות שהכי קל לעשות כאן: לצרף את הכללים של הצוות לרשימה שכתוב מעליה
     * "נמחקים בלי לבקש אישור". צוות שיקרא את זה יוסיף כלל ויפסיק לחפש.
     */
    public function test_the_automatic_deletion_card_lists_only_the_built_ins(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::USER, 'value' => 'intruder']);

        $card = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'משתמשים שנמחקים מיד עם הופעתם');

        $this->assertStringContainsString('sys_maint', $card['detail']);
        $this->assertStringNotContainsString('intruder', $card['detail']);
    }

    /**
     * ערך נשמר מנורמל.
     *
     * קוראי המלאי מחזירים שמות באותיות קטנות, ולכן כלל שנשמר כ-"WP-File-Manager"
     * פשוט לא היה תואם לכלום — כלל שנראה קיים במסך ולא קיים בפועל.
     */
    public function test_a_value_is_stored_the_way_the_matcher_reads_it(): void
    {
        $this->actingAs($this->admin());

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => '  WP-Shell-Kit  ', 'note' => null, 'enabled' => true],
        ]);

        $this->assertSame('wp-shell-kit', SecurityRule::firstOrFail()->value);
        $this->assertContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /** כלל מושהה אינו נבדק. */
    public function test_a_disabled_rule_is_not_watched_for(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'enabled' => false]);

        $this->assertNotContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /** כלל שהוסר מהטופס נמחק. */
    public function test_removing_a_rule_from_the_form_deletes_it(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);

        $this->save([]);

        $this->assertSame(0, SecurityRule::count());
        $this->assertNotContains('wp-shell-kit', ThreatQuarantine::plugins());
    }

    /**
     * אותו שם פעמיים הוא עריכה, לא שורה שנייה.
     *
     * על העמודות יש אינדקס ייחודי, והוספה עיוורת הייתה מפילה את כל השמירה על
     * שגיאת בסיס נתונים — כלומר גם את שאר הכללים שבאותו טופס.
     */
    public function test_the_same_name_twice_is_an_edit_and_never_breaks_the_save(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'ישן']);

        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'חדש', 'enabled' => true],
            ['type' => SecurityRule::USER, 'value' => 'intruder', 'note' => null, 'enabled' => true],
        ]);

        $this->assertSame(2, SecurityRule::count());
        $this->assertSame('חדש', SecurityRule::where('value', 'wp-shell-kit')->firstOrFail()->note);
    }

    /**
     * שורה ריקה נדחית בטופס, ולא נבלעת בשקט.
     *
     * הבליעה השקטה הייתה גרועה יותר: מי שהקליד שם ולחץ שמירה היה מקבל "נשמר"
     * על כלל שלא נשמר, ומפסיק לחפש. הטיפול בצד השרת נשאר כשכבה שנייה למקרה
     * ששורה כזאת בכל זאת עוברת.
     */
    public function test_a_blank_row_is_refused_rather_than_silently_dropped(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SecurityPosture::class)
            ->callAction('manageRules', data: [
                'rules' => [['type' => SecurityRule::USER, 'value' => '   ', 'note' => null, 'enabled' => true]],
            ])
            ->assertHasActionErrors();

        $this->assertSame(0, SecurityRule::count());
    }

    /** מי שאין לו מודול הניהול אינו מגיע למסך בכלל. */
    public function test_the_screen_stays_behind_its_module(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['support'],
        ]));

        $this->assertFalse(SecurityPosture::canAccess());
    }

    /**
     * מי שהוסיף כלל נשאר מי שהוסיף אותו.
     *
     * כל שמירה שולחת את כל השורות שעל המסך, ולכן כתיבת "מי הוסיף" בכל שמירה
     * הייתה הופכת את מי שפתח את החלון אחרון למי שהוסיף את כל הכללים — ודווקא
     * את הפרט הזה אי אפשר לשחזר אחר כך.
     */
    public function test_the_author_of_a_rule_survives_somebody_elses_save(): void
    {
        $author = $this->admin();
        SecurityRule::create([
            'type' => SecurityRule::PLUGIN,
            'value' => 'wp-shell-kit',
            'created_by' => $author->id,
        ]);

        $this->actingAs($this->admin());
        $this->save([
            ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => 'הערה חדשה', 'enabled' => true],
        ]);

        $rule = SecurityRule::firstOrFail();
        $this->assertSame($author->id, $rule->created_by);
        $this->assertSame('הערה חדשה', $rule->note);
    }

    /**
     * מתג ההסגר הכבוי משתיק גם את הכרטיס.
     *
     * `PurgeSiteThreatsJob` חוזר מיד כשהמתג כבוי, כלומר אף כלל אינו נבדק.
     * כרטיס שממשיך להציג "פעיל" היה מבטיח מעקב באתרים שאיש אינו סורק.
     */
    public function test_the_rules_card_goes_quiet_when_the_quarantine_switch_is_off(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);

        config()->set('security.quarantine.enabled', false);

        $card = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'תוספים שהוספתם למעקב');

        $this->assertFalse($card['active']);
        $this->assertStringContainsString('לא נבדקים כרגע', $card['detail']);
    }

    /**
     * טבלה שעדיין לא קיימת אינה עוצרת את הסריקה.
     *
     * קוד שנפרס לפני המיגרציה שלו הוא מצב רגיל בדקה שאחרי פריסה — ובדיוק אז
     * אסור שסריקת האבטחה תיפול ותדווח על כל האתרים כנקיים.
     */
    public function test_a_missing_table_leaves_the_built_ins_working(): void
    {
        Schema::drop('security_rules');

        $this->assertSame(['sys_maint'], ThreatQuarantine::users());
        $this->assertSame(['wp-file-manager'], ThreatQuarantine::plugins());
    }

    private function guardedSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/md-agent/v1/mcp',
            'mcp_secret' => 'secret',
        ]);
    }

    /** A JSON-RPC tools/call reply carrying `$text`. */
    private function toolResult(string $text): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ]];
    }

    /**
     * `wp_guard_status` on a site where the plugin's own list found nothing.
     *
     * No `panel_rules` key: that is exactly how a plugin older than 1.7.0
     * announces it cannot hold a "delete immediately" list, which most sites
     * will be the day this ships.
     */
    private function cleanGuard(): array
    {
        return $this->toolResult(json_encode([
            'present' => ['users' => [], 'plugins' => []],
            'actions' => [],
            'last_id' => 0,
        ]));
    }

    /**
     * The same, from a 1.7.0 site — which reports the list it is holding.
     *
     * @param  list<string>  $users
     * @param  list<string>  $plugins
     */
    private function guard17(array $users = [], array $plugins = [], array $present = ['users' => [], 'plugins' => []], array $actions = []): array
    {
        return $this->toolResult(json_encode([
            'present' => $present,
            'actions' => $actions,
            'last_id' => 0,
            'panel_rules' => ['users' => $users, 'plugins' => $plugins],
            'panel_limits' => ['max' => 50, 'per_sweep' => 5, 'never_plugins' => ['multioto-agent']],
        ]));
    }

    /** Was a given tool called at all? */
    private function called(string $tool): bool
    {
        $seen = false;

        Http::recorded(function ($request) use ($tool, &$seen): void {
            if (data_get($request->data(), 'params.name') === $tool) {
                $seen = true;
            }
        });

        return $seen;
    }

    private function sweep(Site $site): void
    {
        (new PurgeSiteThreatsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));
    }

    /**
     * הבדיקה שבלעדיה כל התכונה מדומה.
     *
     * באתר עם תוסף 1.5.0 ומעלה השומר עונה מהרשימה המקובעת בתוך התוסף — הוא
     * מעולם לא שמע על הכללים שהוספנו כאן ולעולם לא ישמע, כי זו בדיוק הערובה
     * שמונעת מפאנל שנפרץ להרחיב את מה שאתר מוחק. לכן הפאנל חייב לחפש אותם
     * בעצמו, מול המלאי הרגיל. בלי המעבר הזה כלל שנוסף היה נראה פעיל במסך
     * ולא נבדק באף אתר מעודכן — כלומר ברוב האתרים.
     */
    public function test_a_panel_rule_is_looked_for_on_a_site_that_guards_itself(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->cleanGuard())
            ->push($this->toolResult(json_encode([
                ['plugin' => 'akismet/akismet.php'],
                ['plugin' => 'wp-shell-kit/loader.php', 'name' => 'ניהול תוכן'],
            ])));

        $this->sweep($site);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        // Found, not purged: nothing removed it, and a green shield over a
        // threat that is still on the site is worse than no shield at all.
        $this->assertSame('threat_found', $event->type);
        $this->assertStringContainsString('wp-shell-kit', $event->title);
        $this->assertStringContainsString('לאיתור ודיווח בלבד', (string) $event->detail);
    }

    /** אותו דבר לשם משתמש, שנקרא בכלי אחר. */
    public function test_a_panel_user_rule_is_looked_for_on_a_site_that_guards_itself(): void
    {
        SecurityRule::create(['type' => SecurityRule::USER, 'value' => 'intruder']);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->cleanGuard())
            ->push($this->toolResult(json_encode([
                'total' => 1,
                'users' => [['id' => 7, 'login' => 'intruder', 'roles' => ['subscriber']]],
            ])));

        $this->sweep($site);

        $this->assertStringContainsString(
            'intruder',
            SiteEvent::where('site_id', $site->id)->sole()->title,
        );
    }

    /**
     * אתר בלי כללים משלנו אינו משלם דבר.
     *
     * זו הסריקה שרצה כל שעה על כל אתר מחובר. מעבר נוסף שקורא מלאי גם כשאין מה
     * לחפש היה מוסיף קריאה לכל אתר בכל שעה בשביל כלום.
     */
    public function test_a_site_with_no_panel_rules_is_not_read_a_second_time(): void
    {
        $site = $this->guardedSite();

        Http::fake(['*' => Http::response($this->cleanGuard())]);

        $this->sweep($site);

        Http::assertSentCount(1);
    }

    /**
     * ממצא עומד אינו נרשם מחדש בכל שעה.
     *
     * שום דבר אינו מסיר את הפריטים האלה, ולכן הם יימצאו שוב בכל סריקה. רישום
     * בכל שעה היה קובר את הממצא האחד שהוא חדש תחת מאה שאינם, וצוות שלמד לדלג
     * על ההתראה הזאת נמצא במצב גרוע יותר מצוות שלא קיבל אותה מעולם.
     */
    public function test_a_standing_finding_is_not_filed_again_every_hour(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);
        $site = $this->guardedSite();
        $inventory = json_encode([['plugin' => 'wp-shell-kit/loader.php']]);

        Http::fakeSequence()
            ->push($this->cleanGuard())
            ->push($this->toolResult($inventory))
            ->push($this->cleanGuard())
            ->push($this->toolResult($inventory));

        $this->sweep($site);
        $this->sweep($site);

        $this->assertSame(1, SiteEvent::where('site_id', $site->id)->count());
    }

    /** כלל מושהה אינו נסרק — גם לא באתר מוגן. */
    public function test_a_disabled_rule_is_not_swept_for(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'enabled' => false]);
        $site = $this->guardedSite();

        Http::fake(['*' => Http::response($this->cleanGuard())]);

        $this->sweep($site);

        Http::assertSentCount(1);
        $this->assertSame(0, SiteEvent::where('site_id', $site->id)->count());
    }

    // ---- "מחק מיד" ----------------------------------------------------------

    /** כלל רגיל אינו נשלח לאתר בכלל — הוא לאיתור, ואין מה לאכוף. */
    public function test_a_watch_only_rule_is_never_pushed_to_a_site(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit']);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->guard17())
            ->push($this->toolResult(json_encode([])));

        $this->sweep($site);

        $this->assertFalse($this->called('wp_guard_rules'));
    }

    /** כלל שמסומן "מחק מיד" נשלח לאתר שיכול להחזיק אותו. */
    public function test_a_delete_rule_is_pushed_to_a_site_that_can_hold_it(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        SecurityRule::create(['type' => SecurityRule::USER, 'value' => 'intruder', 'auto_remove' => true]);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->guard17())                                   // nothing held yet
            ->push($this->toolResult(json_encode(['users' => ['intruder'], 'plugins' => ['wp-shell-kit']])))
            ->push($this->guard17(['intruder'], ['wp-shell-kit']));     // re-read

        $this->sweep($site);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'params.name') === 'wp_guard_rules'
            && data_get($request->data(), 'params.arguments.plugins') === ['wp-shell-kit']
            && data_get($request->data(), 'params.arguments.users') === ['intruder']);
    }

    /**
     * רשימה שהאתר כבר מחזיק אינה נשלחת שוב.
     *
     * הסריקה הזאת רצה כל שעה על כל אתר מחובר. דחיפה בכל פעם הייתה קריאה נוספת
     * לכל אתר בכל שעה בשביל אפס שינוי.
     */
    public function test_a_list_already_in_force_is_not_pushed_again(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        $site = $this->guardedSite();

        Http::fake(['*' => Http::response($this->guard17([], ['wp-shell-kit']))]);

        $this->sweep($site);

        $this->assertFalse($this->called('wp_guard_rules'));
        Http::assertSentCount(1);
    }

    /**
     * אתר שעזב וחזר מגיבוי מקבל את הרשימה מחדש.
     *
     * אתר ששוחזר חוזר עם ה-option שהיה לו ביום הגיבוי, ושום דבר אחר לא היה שם
     * לב. ההשוואה מול מה שהאתר מדווח היא גם התיקון.
     */
    public function test_a_site_holding_a_stale_list_is_corrected(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->guard17([], ['something-else']))   // what the backup had
            ->push($this->toolResult(json_encode(['plugins' => ['wp-shell-kit']])))
            ->push($this->guard17([], ['wp-shell-kit']));

        $this->sweep($site);

        $this->assertTrue($this->called('wp_guard_rules'));
    }

    /**
     * כלל שנאכף באתר אינו מדווח גם מכאן.
     *
     * האתר מוחק ורושם ביומן שלו, והפאנל מרוקן את היומן — כלומר חיפוש נוסף מכאן
     * היה מייצר שני דיווחים על אותו ממצא, ואחד מהם היה אומר "לא הוסר" על משהו
     * שדווקא כן הוסר.
     */
    public function test_a_pushed_rule_is_not_also_reported_by_the_panel_sweep(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        $site = $this->guardedSite();

        Http::fake(['*' => Http::response($this->guard17([], ['wp-shell-kit']))]);

        $this->sweep($site);

        // One call: the status. No inventory read, no second finding.
        Http::assertSentCount(1);
        $this->assertSame(0, SiteEvent::where('site_id', $site->id)->count());
    }

    /**
     * הדיווח שהתכונה הזאת חייבת: "סימנת מחק מיד, ובאתר הזה זה לא קרה".
     *
     * זה המצב המסוכן — לא זה שבו שום דבר לא נמחק, אלא זה שבו מישהו סימן תיבה
     * ומאמין שהטיפול בוצע. אתר עם תוסף ישן אינו יכול להחזיק את הרשימה, ולכן
     * הכלל נסרק מכאן ומדווח במפורש כלא נאכף, עם גרסת התוסף שחוסמת אותו.
     */
    public function test_a_delete_rule_on_an_old_plugin_is_reported_as_not_enforced(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        $site = $this->guardedSite();
        $site->update(['agent_plugin_version' => '1.6.2']);

        Http::fakeSequence()
            ->push($this->cleanGuard())   // no panel_rules — cannot hold a list
            ->push($this->toolResult(json_encode([['plugin' => 'wp-shell-kit/loader.php']])));

        $this->sweep($site);

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertStringContainsString('מחק מיד', $event->title);
        $this->assertStringContainsString('1.6.2', (string) $event->detail);
        $this->assertStringContainsString('1.7.0', (string) $event->detail);
    }

    /** דחיפה שנכשלה חוזרת לדיווח, ולא מניחה שהכלל נאכף. */
    public function test_a_failed_push_falls_back_to_reporting(): void
    {
        SecurityRule::create(['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'auto_remove' => true]);
        $site = $this->guardedSite();

        Http::fakeSequence()
            ->push($this->guard17())
            ->pushStatus(500)                                                      // the push fails
            ->push($this->toolResult(json_encode([['plugin' => 'wp-shell-kit/loader.php']])));

        $this->sweep($site);

        $this->assertStringContainsString(
            'wp-shell-kit',
            SiteEvent::where('site_id', $site->id)->sole()->title,
        );
    }

    /** הכרטיס שמבטיח מחיקה אוטומטית מונה גם כלל שסומן "מחק מיד". */
    public function test_the_deletion_card_lists_a_rule_marked_for_immediate_removal(): void
    {
        $this->actingAs($this->admin());
        SecurityRule::create(['type' => SecurityRule::USER, 'value' => 'intruder', 'auto_remove' => true]);

        $card = collect(Livewire::test(SecurityPosture::class)->instance()->rules())
            ->firstWhere('title', 'משתמשים שנמחקים מיד עם הופעתם');

        $this->assertStringContainsString('sys_maint', $card['detail']);
        $this->assertStringContainsString('intruder', $card['detail']);
        // And the difference between the two halves is not glossed over.
        $this->assertStringContainsString('1.7.0', $card['detail']);
    }

    /** השמירה אומרת בחזרה מה נמחק, בשמו. */
    public function test_the_save_says_which_rules_delete(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(SecurityPosture::class)
            ->callAction('manageRules', data: ['rules' => [
                ['type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit', 'note' => null, 'enabled' => true, 'auto_remove' => true],
                ['type' => SecurityRule::USER, 'value' => 'watched', 'note' => null, 'enabled' => true, 'auto_remove' => false],
            ]])
            ->assertNotified();

        $this->assertTrue(SecurityRule::where('value', 'wp-shell-kit')->sole()->auto_remove);
        $this->assertFalse(SecurityRule::where('value', 'watched')->sole()->auto_remove);
        $this->assertSame(['wp-shell-kit'], ThreatQuarantine::autoRemovePlugins());
        $this->assertSame([], ThreatQuarantine::autoRemoveUsers());
    }

    /** וכלל מושהה אינו נדחף, גם אם הוא מסומן "מחק מיד". */
    public function test_a_disabled_delete_rule_is_not_pushed(): void
    {
        SecurityRule::create([
            'type' => SecurityRule::PLUGIN, 'value' => 'wp-shell-kit',
            'enabled' => false, 'auto_remove' => true,
        ]);

        $this->assertSame([], ThreatQuarantine::autoRemovePlugins());

        $site = $this->guardedSite();
        Http::fake(['*' => Http::response($this->guard17())]);

        $this->sweep($site);

        $this->assertFalse($this->called('wp_guard_rules'));
    }
}
