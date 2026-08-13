<?php

namespace App\Services\Agent;

use App\Models\Site;

/**
 * Operations a manager runs on a managed site by pressing a button.
 *
 * Distinct from what the AI proposes. There the machine decides what to do and a
 * human approves it; here a human decides and the machine only carries it out.
 * The approval gate exists to put a person in front of a change — pressing this
 * button IS that person, so there is nothing left to approve.
 *
 * Everything an operation needs lives in one entry here: what it is called, what
 * it actually does, what it costs, which plugin tool performs it, and what goes
 * in the site's change journal afterwards. Adding the next operation is adding
 * one entry — the panel builds its button, the job runs it and the journal
 * records it without another line of code.
 *
 * What an entry must always carry is an honest `cost`: the sentence the operator
 * reads before pressing. An operation whose price is not written down is one
 * somebody will run without knowing they paid it.
 */
class SiteOperations
{
    public const ROTATE_SALTS = 'rotate_salts';

    /**
     * @return array<string, array{label: string, icon: string, color: string, tool: string,
     *     arguments: array<string, mixed>, timeout: int, heading: string, what: string,
     *     cost: string, submit: string, summary: string}>
     */
    public static function all(): array
    {
        return [
            self::ROTATE_SALTS => [
                'label' => 'החלפת מפתחות הצפנה (Secret Keys)',
                'icon' => 'heroicon-o-key',
                'color' => 'warning',
                'tool' => 'wp_salts_rotate',
                'arguments' => [],
                'timeout' => 60,
                'heading' => 'החלפת מפתחות ההצפנה של האתר',
                'what' => 'שמונת המפתחות ב-wp-config.php שוורדפרס חותם בהם עוגיות התחברות וטפסים יוחלפו במפתחות אקראיים חדשים. '
                    .'זו הדרך לסיים בבת אחת כל התחברות פעילה באתר — אחרי חשד לפריצה, סיסמה שדלפה או עובד שעזב.',
                'cost' => 'כל המשתמשים באתר יתנתקו מיד ויצטרכו להתחבר מחדש — כולל אתם וכולל הלקוח. '
                    .'טפסים שפתוחים באותו רגע בדפדפן יבקשו רענון. סיסמאות, תוכן ומסד הנתונים אינם משתנים.',
                'submit' => 'החלף מפתחות ונתק את כולם',
                'summary' => 'החלפת מפתחות ההצפנה (Secret Keys) של וורדפרס',
            ],
        ];
    }

    /** @return array{label: string, icon: string, color: string, tool: string, arguments: array<string, mixed>, timeout: int, heading: string, what: string, cost: string, submit: string, summary: string}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Whether the site's plugin actually offers the tool this operation needs.
     *
     * Read from the capabilities recorded at the last handshake, so a site still
     * running an older plugin is told to update rather than being handed a button
     * that fails. A site whose capabilities were never read is not judged here —
     * "we have not looked" is not "the tool is missing", and the attempt itself
     * reports the truth.
     */
    public static function supportedOn(Site $site, string $key): bool
    {
        $operation = self::find($key);

        if ($operation === null) {
            return false;
        }

        $tools = (array) data_get($site->mcp_capabilities, 'tools', []);

        if ($tools === []) {
            return true;
        }

        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === $operation['tool']) {
                return true;
            }
        }

        return false;
    }
}
