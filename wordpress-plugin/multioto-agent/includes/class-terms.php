<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Categories, tags, and whatever else a site files its content under.
 *
 * The rule that shapes this file: **a name that does not exist is refused, not
 * created.** `wp_set_object_terms()` will happily invent a term from a string
 * it does not recognise, so a single typo produces a second "מבצעים" category
 * holding one post, on an archive page nobody links to — a change that reports
 * success and quietly splits the site's taxonomy in half. Creating a term is
 * therefore its own tool, proposed and approved on its own.
 *
 * Assignment also defaults to ADDING rather than replacing. Replacing is
 * supported and reversible, but it is the mode that removes something, so it
 * has to be asked for.
 */
class Multioto_Agent_Terms
{
    /** Taxonomies that are plumbing rather than something a person files under. */
    const EXCLUDED = [
        'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area',
        'elementor_library_type', 'elementor_library_category', 'product_type',
        'product_visibility', 'product_shipping_class',
    ];

    const MAX_LIMIT = 200;

    /**
     * Which taxonomies exist — for a given post type, or all of them.
     *
     * @param  array<string, mixed>  $args
     */
    public static function taxonomies(array $args): string
    {
        $type = trim((string) ($args['type'] ?? ''));

        $names = $type !== ''
            ? get_object_taxonomies($type, 'names')
            : get_taxonomies(['show_ui' => true], 'names');

        $out = [];

        foreach ($names as $name) {
            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            $taxonomy = get_taxonomy($name);

            if (! $taxonomy instanceof WP_Taxonomy) {
                continue;
            }

            $out[] = [
                'taxonomy' => $name,
                'label' => (string) $taxonomy->labels->name,
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'post_types' => array_values((array) $taxonomy->object_type),
                'terms' => (int) wp_count_terms(['taxonomy' => $name, 'hide_empty' => false]),
            ];
        }

        return wp_json_encode([
            'type' => $type !== '' ? $type : 'all',
            'taxonomies' => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * The terms inside one taxonomy.
     *
     * @param  array<string, mixed>  $args
     */
    public static function listTerms(array $args): string
    {
        $taxonomy = self::taxonomy($args);
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 100)));

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $limit,
            'search' => trim((string) ($args['search'] ?? '')),
            'orderby' => 'name',
        ]);

        if (is_wp_error($terms)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $terms->get_error_message());
        }

        $total = (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        $out = [];

        foreach ($terms as $term) {
            $out[] = [
                'id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => (int) $term->parent,
                'count' => (int) $term->count,
            ];
        }

        return wp_json_encode([
            'taxonomy' => $taxonomy,
            'total' => $total,
            'returned' => count($out),
            'terms' => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Add a term.
     *
     * @param  array<string, mixed>  $args
     */
    public static function create(array $args): string
    {
        $taxonomy = self::taxonomy($args);
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר שם (name).');
        }

        $parent = (int) ($args['parent'] ?? 0);

        if ($parent > 0 && ! term_exists($parent, $taxonomy)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "קטגוריית האב #{$parent} אינה קיימת ב-{$taxonomy}.");
        }

        $created = wp_insert_term(sanitize_text_field($name), $taxonomy, [
            'slug' => sanitize_title((string) ($args['slug'] ?? '')),
            'description' => sanitize_text_field((string) ($args['description'] ?? '')),
            'parent' => $parent,
        ]);

        if (is_wp_error($created)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $created->get_error_message());
        }

        return wp_json_encode([
            'created_id' => (int) $created['term_id'],
            'taxonomy' => $taxonomy,
            'name' => $name,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * File a piece of content under terms that already exist.
     *
     * @param  array<string, mixed>  $args
     */
    public static function setPostTerms(array $args): string
    {
        $taxonomy = self::taxonomy($args);
        $postId = (int) ($args['id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post instanceof WP_Post) {
            throw new Multioto_Agent_Rpc_Error(-32602, "פריט תוכן #{$postId} לא נמצא.");
        }

        if (! in_array($taxonomy, get_object_taxonomies($post->post_type, 'names'), true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                "לסוג התוכן '%s' אין את הטקסונומיה '%s'.",
                $post->post_type,
                $taxonomy,
            ));
        }

        $termIds = self::resolve($args, $taxonomy);
        $mode = strtolower(trim((string) ($args['mode'] ?? 'add')));

        if (! in_array($mode, ['add', 'replace'], true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "mode חייב להיות add או replace (התקבל '{$mode}').");
        }

        // The whole previous set, before anything is written — the snapshot
        // that makes `replace` reversible.
        $previous = wp_get_object_terms($postId, $taxonomy, ['fields' => 'ids']);
        $previous = is_wp_error($previous) ? [] : array_map('intval', $previous);

        $result = wp_set_object_terms($postId, $termIds, $taxonomy, $mode === 'add');

        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }

        $now = wp_get_object_terms($postId, $taxonomy, ['fields' => 'ids']);

        return wp_json_encode([
            'id' => $postId,
            'taxonomy' => $taxonomy,
            'term_ids' => is_wp_error($now) ? [] : array_map('intval', $now),
            // Carries its own mode: undoing an "add" still means putting the
            // set back exactly as it was, which only `replace` can do.
            'previous' => ['term_ids' => $previous, 'mode' => 'replace'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Term ids from ids or from names — refusing any name the site does not
     * already have.
     *
     * @param  array<string, mixed>  $args
     * @return list<int>
     */
    private static function resolve(array $args, string $taxonomy): array
    {
        $ids = [];
        $missing = [];

        foreach ((array) ($args['term_ids'] ?? []) as $id) {
            $id = (int) $id;

            if ($id > 0 && term_exists($id, $taxonomy)) {
                $ids[] = $id;

                continue;
            }

            $missing[] = "#{$id}";
        }

        foreach ((array) ($args['terms'] ?? []) as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $term = get_term_by('name', $name, $taxonomy);

            if ($term instanceof WP_Term) {
                $ids[] = (int) $term->term_id;

                continue;
            }

            $missing[] = $name;
        }

        if ($missing !== []) {
            throw new Multioto_Agent_Rpc_Error(-32602, sprintf(
                'אינם קיימים ב-%s: %s. יש ליצור אותם קודם (wp_term_create) — שם שגוי היה יוצר קטגוריה חדשה כפולה שאיש אינו רואה.',
                $taxonomy,
                implode(', ', $missing),
            ));
        }

        // An empty set is a legitimate instruction ("file this under nothing")
        // in replace mode, but in add mode it is a call that does nothing and
        // would report success.
        if ($ids === [] && strtolower(trim((string) ($args['mode'] ?? 'add'))) !== 'replace') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא צוינו קטגוריות/תגיות לשיוך.');
        }

        return array_values(array_unique($ids));
    }

    /** @param  array<string, mixed>  $args */
    private static function taxonomy(array $args): string
    {
        $taxonomy = strtolower(trim((string) ($args['taxonomy'] ?? '')));

        if ($taxonomy === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסרה טקסונומיה (taxonomy). ראו wp_taxonomy_list.');
        }

        if (in_array($taxonomy, self::EXCLUDED, true) || ! taxonomy_exists($taxonomy)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "הטקסונומיה '{$taxonomy}' אינה קיימת באתר או שאינה ניתנת לעריכה מכאן.");
        }

        return $taxonomy;
    }
}
