<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * People on the site — the part of "being an administrator" that most needs a
 * fence around it.
 *
 * The agent is reachable from a phone, over a shared secret, by anyone the
 * panel lets in. So the rule here is not "be careful with administrators", it
 * is that the agent has no vocabulary for them at all: it cannot create one,
 * cannot promote anybody into one, and cannot touch one that exists. Whoever
 * owns the site stays the only one who can hand out that key.
 *
 * Everything below that line — adding an editor, approving a subscriber,
 * moving somebody from contributor to author — is ordinary work, and is
 * supported.
 */
class Multioto_Agent_Users
{
    /**
     * The roles the agent may assign.
     *
     * `administrator` is absent on purpose, and this is an allow-list rather
     * than a deny-list: a role added by a plugin tomorrow (which may carry any
     * capability at all) is refused until somebody decides otherwise, instead
     * of being assignable the moment it appears.
     */
    const ASSIGNABLE = ['subscriber', 'contributor', 'author', 'editor', 'customer', 'shop_manager'];

    /** Never assigned, never modified, never listed as changeable. */
    const PROTECTED_ROLE = 'administrator';

    /** How many users one read may return. */
    const MAX_LIMIT = 100;

    /**
     * Who is on the site.
     *
     * @param  array<string, mixed>  $args
     */
    public static function listUsers(array $args): string
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 25)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $role = sanitize_key((string) ($args['role'] ?? ''));
        $search = trim((string) ($args['search'] ?? ''));

        $query = [
            'number' => $limit,
            'paged' => $page,
            // Stable across pages: relevance ordering reshuffles between calls,
            // so walking pages would skip people and repeat others.
            'orderby' => 'ID',
            'order' => 'ASC',
            'count_total' => true,
        ];

        if ($role !== '') {
            $query['role'] = $role;
        }

        if ($search !== '') {
            $query['search'] = '*'.$search.'*';
            $query['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
        }

        $found = new WP_User_Query($query);
        $total = (int) $found->get_total();

        $users = [];

        foreach ($found->get_results() as $user) {
            $users[] = [
                'id' => (int) $user->ID,
                'login' => (string) $user->user_login,
                'email' => (string) $user->user_email,
                'display_name' => (string) $user->display_name,
                'roles' => array_values((array) $user->roles),
                'registered' => (string) $user->user_registered,
                // Whether this row is one the agent is allowed to move at all,
                // stated up front so a proposal is never built against a user
                // the write tool will refuse.
                'editable' => self::isEditable($user),
                // Key names only — never values. Sites gate registration in
                // many different ways, and the point here is to SEE which
                // mechanism a real site uses before writing anything against a
                // guessed field name. A value could be anything, including
                // personal data, so it does not travel.
                'status_meta_keys' => self::statusMetaKeys((int) $user->ID),
            ];
        }

        return wp_json_encode([
            'total' => $total,
            'returned' => count($users),
            'page' => $page,
            'pages' => (int) ceil($total / $limit),
            'assignable_roles' => self::ASSIGNABLE,
            'users' => $users,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Add a person.
     *
     * No password is chosen here and none is returned. WordPress sends them its
     * own "set your password" link instead — a password that travelled through
     * a chat transcript is a password that lives in a chat transcript.
     *
     * @param  array<string, mixed>  $args
     */
    public static function create(array $args): string
    {
        $email = sanitize_email((string) ($args['email'] ?? ''));

        if ($email === '' || ! is_email($email)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'כתובת אימייל חסרה או אינה תקינה.');
        }

        if (email_exists($email)) {
            throw new Multioto_Agent_Rpc_Error(-32000, "כבר קיים משתמש עם האימייל {$email}.");
        }

        $role = self::assignableRole((string) ($args['role'] ?? 'subscriber'));
        $login = self::freeLogin((string) ($args['login'] ?? ''), $email);

        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            // Long and random: the account is reachable only through the reset
            // link WordPress mails below, never through a value anybody saw.
            'user_pass' => wp_generate_password(24, true, true),
            'display_name' => sanitize_text_field((string) ($args['display_name'] ?? '')) ?: $login,
            'first_name' => sanitize_text_field((string) ($args['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($args['last_name'] ?? '')),
            'role' => $role,
        ]);

        if (is_wp_error($id)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
        }

        $notified = false;

        // Default ON: an account nobody was told about is an account nobody can
        // use, and the caller would have been told it succeeded.
        if (! array_key_exists('notify', $args) || ! empty($args['notify'])) {
            wp_new_user_notification((int) $id, null, 'user');
            $notified = true;
        }

        return wp_json_encode([
            'created_id' => (int) $id,
            'login' => $login,
            'email' => $email,
            'role' => $role,
            'notified' => $notified,
            'password_returned' => false,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Move somebody to a different role — which on most sites IS the approval
     * step: a pending registration becomes a real one by gaining the role that
     * lets it do something.
     *
     * @param  array<string, mixed>  $args
     */
    public static function setRole(array $args): string
    {
        $user = self::user((int) ($args['user_id'] ?? 0));
        $role = self::assignableRole((string) ($args['role'] ?? ''));

        if (in_array(self::PROTECTED_ROLE, (array) $user->roles, true)) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'המשתמש הוא מנהל האתר — שינוי התפקיד שלו אינו נעשה מכאן.');
        }

        $current = array_values((array) $user->roles);

        // set_role() replaces every role at once. A user carrying more than one
        // is almost always a plugin's doing, and flattening them to a single
        // role would quietly remove a capability nobody asked to remove — and
        // the revert, which restores one role, could not put it back.
        if (count($current) > 1) {
            throw new Multioto_Agent_Rpc_Error(-32000, sprintf(
                'למשתמש יש כמה תפקידים (%s) ושינוי מכאן היה מוחק את השאר. יש לטפל בזה ידנית.',
                implode(', ', $current),
            ));
        }

        $previous = $current[0] ?? '';

        if ($previous === $role) {
            return wp_json_encode([
                'user_id' => (int) $user->ID,
                'role' => $role,
                'changed' => false,
                'note' => 'התפקיד כבר היה כזה — לא בוצע שינוי.',
            ], JSON_UNESCAPED_UNICODE);
        }

        $user->set_role($role);

        return wp_json_encode([
            'user_id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'role' => $role,
            'changed' => true,
            // The snapshot behind "undo". Empty when the user had no role at
            // all, in which case there is nothing to restore and the panel is
            // told so rather than being handed a blank to write back.
            'previous' => $previous !== '' ? ['role' => $previous] : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** A role the agent is allowed to hand out, or a refusal that names why. */
    private static function assignableRole(string $role): string
    {
        $role = sanitize_key($role);

        if ($role === self::PROTECTED_ROLE) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'תפקיד מנהל אתר אינו ניתן להקצאה מכאן — זו החלטה של בעל האתר בלבד.');
        }

        if (! in_array($role, self::ASSIGNABLE, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                "התפקיד '%s' אינו ברשימת התפקידים המותרים (%s).",
                $role,
                implode(', ', self::ASSIGNABLE),
            ));
        }

        // Present on this site at all? A role that is not registered would be
        // written to the database and grant nothing.
        if (get_role($role) === null) {
            throw new Multioto_Agent_Rpc_Error(-32602, "התפקיד '{$role}' אינו קיים באתר הזה.");
        }

        return $role;
    }

    private static function user(int $id): WP_User
    {
        $user = $id > 0 ? get_user_by('id', $id) : false;

        if (! $user instanceof WP_User) {
            throw new Multioto_Agent_Rpc_Error(-32602, "משתמש #{$id} לא נמצא.");
        }

        return $user;
    }

    private static function isEditable(WP_User $user): bool
    {
        return ! in_array(self::PROTECTED_ROLE, (array) $user->roles, true)
            && count((array) $user->roles) <= 1;
    }

    /**
     * Meta key names that look like they gate whether an account is active.
     *
     * @return list<string>
     */
    private static function statusMetaKeys(int $userId): array
    {
        $keys = [];

        foreach (array_keys((array) get_user_meta($userId)) as $key) {
            $key = (string) $key;

            // Internal WordPress keys say nothing about approval and would
            // bury the one key that does.
            if (strpos($key, 'wp_') === 0 || strpos($key, '_') === 0) {
                continue;
            }

            foreach (['approv', 'pending', 'status', 'active', 'confirm', 'verif'] as $needle) {
                if (stripos($key, $needle) !== false) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** A login nobody holds, derived from the requested one or from the email. */
    private static function freeLogin(string $requested, string $email): string
    {
        $base = sanitize_user($requested !== '' ? $requested : (string) strstr($email, '@', true), true);
        $base = $base !== '' ? $base : 'user';
        $login = $base;
        $suffix = 1;

        while (username_exists($login) !== null) {
            $login = $base.++$suffix;
        }

        return $login;
    }
}
