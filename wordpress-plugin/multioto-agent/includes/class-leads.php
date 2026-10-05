<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The leads a site collected: whatever its contact forms stored.
 *
 * There is no "leads" in WordPress itself — every form plugin keeps its own
 * submissions in its own place. This reads the ones our customers' sites
 * actually use and hands them back in one shape, newest first:
 *
 *   - Elementor Pro forms     (e_submissions tables)
 *   - Contact Form 7          (only through Flamingo — CF7 alone stores nothing)
 *   - WPForms                 (Pro's entries table; Lite stores nothing)
 *   - Gravity Forms           (GFAPI)
 *   - Fluent Forms            (fluentform_submissions table)
 *
 * Read-only, and every query is prepared. A form plugin that is not there is
 * simply not a source; the answer names the sources it found, so "no leads"
 * can be told apart from "no form plugin we can read".
 */
class Multioto_Agent_Leads
{
    /** How many leads one read may return. */
    const MAX_LIMIT = 50;

    /** How many submissions each source contributes before merging. */
    const PER_SOURCE = 200;

    /** Fields per lead, and characters per value — a lead, not a document. */
    const MAX_FIELDS = 20;

    const MAX_VALUE = 500;

    /**
     * @param  array<string, mixed>  $args
     * @return array{count: int, sources: list<string>, leads: list<array<string, mixed>>}
     */
    public static function listLeads(array $args): array
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 10)));
        $days = max(1, min(366, (int) ($args['days'] ?? 30)));
        $search = trim(sanitize_text_field((string) ($args['search'] ?? '')));
        $since = time() - $days * DAY_IN_SECONDS;

        $readers = [
            'elementor' => 'elementor',
            'contact-form-7' => 'flamingo',
            'wpforms' => 'wpforms',
            'gravity-forms' => 'gravity',
            'fluent-forms' => 'fluent',
        ];

        $leads = [];
        $sources = [];

        foreach ($readers as $source => $method) {
            $found = self::{$method}($since);

            if ($found === null) {
                continue; // That plugin is not on this site.
            }

            $sources[] = $source;

            foreach ($found as $lead) {
                $leads[] = ['source' => $source] + $lead;
            }
        }

        if ($search !== '') {
            $leads = array_values(array_filter($leads, static function (array $lead) use ($search): bool {
                $haystack = $lead['form'].' '.implode(' ', $lead['fields']);

                return function_exists('mb_stripos')
                    ? mb_stripos($haystack, $search) !== false
                    : stripos($haystack, $search) !== false;
            }));
        }

        usort($leads, static function (array $a, array $b): int {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $leads = array_map(static function (array $lead): array {
            unset($lead['timestamp']);

            return $lead;
        }, array_slice($leads, 0, $limit));

        return ['count' => count($leads), 'sources' => $sources, 'leads' => $leads];
    }

    /** @return list<array<string, mixed>>|null */
    private static function elementor(int $since): ?array
    {
        global $wpdb;

        $submissions = $wpdb->prefix.'e_submissions';
        $values = $wpdb->prefix.'e_submissions_values';

        if (! self::tableExists($submissions) || ! self::tableExists($values)) {
            return null;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, form_name, created_at_gmt FROM {$submissions}
             WHERE created_at_gmt >= %s AND status NOT LIKE %s
             ORDER BY id DESC LIMIT %d",
            gmdate('Y-m-d H:i:s', $since),
            '%trash%',
            self::PER_SOURCE
        ), ARRAY_A);

        if (empty($rows)) {
            return [];
        }

        $ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $fields = [];

        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, `key`, value FROM {$values} WHERE submission_id IN ({$placeholders}) ORDER BY id ASC",
            $ids
        ), ARRAY_A) as $value) {
            $fields[(int) $value['submission_id']][(string) $value['key']] = $value['value'];
        }

        $leads = [];

        foreach ($rows as $row) {
            $leads[] = self::lead(
                (string) $row['id'],
                (string) $row['form_name'],
                self::gmtTimestamp((string) $row['created_at_gmt']),
                $fields[(int) $row['id']] ?? []
            );
        }

        return $leads;
    }

    /** Contact Form 7 keeps nothing; Flamingo is what stores its messages. @return list<array<string, mixed>>|null */
    private static function flamingo(int $since): ?array
    {
        if (! post_type_exists('flamingo_inbound')) {
            return null;
        }

        $posts = get_posts([
            'post_type' => 'flamingo_inbound',
            'post_status' => 'publish',
            'numberposts' => self::PER_SOURCE,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [['after' => gmdate('Y-m-d H:i:s', $since), 'column' => 'post_date_gmt']],
        ]);

        $leads = [];

        foreach ($posts as $post) {
            $fields = [];

            foreach (array_keys((array) get_post_meta($post->ID, '_fields', true)) as $name) {
                $fields[(string) $name] = get_post_meta($post->ID, '_field_'.$name, true);
            }

            if ($fields === []) {
                $fields = [
                    'name' => get_post_meta($post->ID, '_from_name', true),
                    'email' => get_post_meta($post->ID, '_from_email', true),
                    'subject' => $post->post_title,
                ];
            }

            $channel = wp_get_post_terms($post->ID, 'flamingo_inbound_channel', ['fields' => 'names']);

            $leads[] = self::lead(
                (string) $post->ID,
                is_array($channel) && $channel !== [] ? (string) $channel[0] : 'Contact Form 7',
                self::gmtTimestamp($post->post_date_gmt),
                $fields
            );
        }

        return $leads;
    }

    /** @return list<array<string, mixed>>|null */
    private static function wpforms(int $since): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix.'wpforms_entries';

        if (! self::tableExists($table)) {
            return null;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entry_id, form_id, fields, date FROM {$table}
             WHERE date >= %s AND status NOT IN ('trash', 'spam')
             ORDER BY entry_id DESC LIMIT %d",
            gmdate('Y-m-d H:i:s', $since),
            self::PER_SOURCE
        ), ARRAY_A);

        $leads = [];

        foreach ((array) $rows as $row) {
            $fields = [];

            foreach ((array) json_decode((string) $row['fields'], true) as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $fields[(string) $field['name']] = $field['value'] ?? '';
                }
            }

            $leads[] = self::lead(
                (string) $row['entry_id'],
                (string) get_the_title((int) $row['form_id']),
                self::gmtTimestamp((string) $row['date']),
                $fields
            );
        }

        return $leads;
    }

    /** @return list<array<string, mixed>>|null */
    private static function gravity(int $since): ?array
    {
        if (! class_exists('GFAPI')) {
            return null;
        }

        $entries = GFAPI::get_entries(
            0,
            ['status' => 'active', 'start_date' => gmdate('Y-m-d H:i:s', $since)],
            ['key' => 'date_created', 'direction' => 'DESC'],
            ['offset' => 0, 'page_size' => self::PER_SOURCE]
        );

        if (is_wp_error($entries)) {
            return [];
        }

        $forms = [];
        $leads = [];

        foreach ((array) $entries as $entry) {
            $formId = (int) ($entry['form_id'] ?? 0);

            if (! array_key_exists($formId, $forms)) {
                $form = GFAPI::get_form($formId);
                $forms[$formId] = is_array($form) ? $form : null;
            }

            $form = $forms[$formId];
            $fields = [];

            foreach ((array) ($form['fields'] ?? []) as $field) {
                // Composite fields (a name, an address) keep their parts under
                // sub-ids; the field's own export joins them the way the
                // owner sees them in wp-admin.
                $value = method_exists($field, 'get_value_export')
                    ? $field->get_value_export($entry)
                    : ($entry[(string) $field->id] ?? '');

                $fields[(string) $field->label] = $value;
            }

            $leads[] = self::lead(
                (string) ($entry['id'] ?? ''),
                (string) ($form['title'] ?? 'Gravity Forms'),
                self::gmtTimestamp((string) ($entry['date_created'] ?? '')),
                $fields
            );
        }

        return $leads;
    }

    /** @return list<array<string, mixed>>|null */
    private static function fluent(int $since): ?array
    {
        global $wpdb;

        $submissions = $wpdb->prefix.'fluentform_submissions';
        $forms = $wpdb->prefix.'fluentform_forms';

        if (! self::tableExists($submissions)) {
            return null;
        }

        // Fluent Forms writes created_at in the site's own time, not GMT.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.response, s.created_at, f.title FROM {$submissions} s
             LEFT JOIN {$forms} f ON f.id = s.form_id
             WHERE s.created_at >= %s AND s.status NOT IN ('trashed', 'spam')
             ORDER BY s.id DESC LIMIT %d",
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $since)),
            self::PER_SOURCE
        ), ARRAY_A);

        $leads = [];

        foreach ((array) $rows as $row) {
            $leads[] = self::lead(
                (string) $row['id'],
                (string) ($row['title'] ?? 'Fluent Forms'),
                self::gmtTimestamp(get_gmt_from_date((string) $row['created_at'])),
                (array) json_decode((string) $row['response'], true)
            );
        }

        return $leads;
    }

    /**
     * One lead in the shape every source shares.
     *
     * Internal fields (a leading underscore, nonces, honeypots) are dropped,
     * nested values (a name split into first and last) are joined, and every
     * value is cut to a length a person reads on a phone.
     *
     * @param  array<string, mixed>  $raw
     * @return array{id: string, form: string, date: string, timestamp: int, fields: array<string, string>}
     */
    private static function lead(string $id, string $form, int $timestamp, array $raw): array
    {
        $fields = [];

        foreach ($raw as $label => $value) {
            $label = trim(wp_strip_all_tags((string) $label));

            if ($label === '' || $label[0] === '_' || preg_match('/nonce|honeypot|captcha|referer/i', $label) === 1) {
                continue;
            }

            $text = trim(wp_strip_all_tags(self::flatten($value)));

            if ($text === '') {
                continue;
            }

            $fields[$label] = function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_VALUE) : substr($text, 0, self::MAX_VALUE);

            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }

        return [
            'id' => $id,
            'form' => $form !== '' ? $form : 'טופס',
            'date' => $timestamp > 0 ? wp_date('Y-m-d H:i', $timestamp) : '',
            'timestamp' => $timestamp,
            'fields' => $fields,
        ];
    }

    private static function flatten($value): string
    {
        if (is_array($value)) {
            return implode(' ', array_filter(array_map(array(__CLASS__, 'flatten'), $value), 'strlen'));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function gmtTimestamp(string $gmt): int
    {
        $time = $gmt !== '' ? strtotime($gmt.' UTC') : false;

        return $time !== false ? (int) $time : 0;
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}
