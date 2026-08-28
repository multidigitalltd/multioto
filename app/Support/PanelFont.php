<?php

namespace App\Support;

/**
 * Which typeface the panel is set in, and where it comes from.
 *
 * One place, because three others need to agree on it: the panel provider that
 * loads the stylesheet, the CSS that names the family and its fallbacks, and
 * anybody diagnosing why text looks wrong. A family configured in one and
 * hard-coded in another is how a font change appears to do nothing.
 */
class PanelFont
{
    public const DEFAULT_FAMILY = 'Heebo';

    /** Faces that carry Hebrew, for when the webfont does not arrive at all. */
    public const FALLBACKS = ["'Segoe UI'", "'Noto Sans Hebrew'", "'Arial Hebrew'", 'Arial', 'sans-serif'];

    public static function family(): string
    {
        return trim((string) config('billing.branding.panel_font')) ?: self::DEFAULT_FAMILY;
    }

    /**
     * The stylesheet to load.
     *
     * Weights only unless somebody configured otherwise. An `ital` axis asked of
     * a family that has none makes Google reject the entire request — which
     * costs the font altogether, not just its italics.
     */
    public static function url(): string
    {
        $configured = trim((string) config('billing.branding.panel_font_url'));

        if ($configured !== '') {
            return $configured;
        }

        return 'https://fonts.googleapis.com/css2?family='
            .str_replace(' ', '+', self::family())
            .':wght@400;500;600;700&display=swap';
    }

    /**
     * The full CSS stack: the chosen family, then faces that know Hebrew.
     *
     * Filament's own `--font-family` holds a single name, so a webfont that
     * fails to arrive lands on whatever the machine picks — which in Hebrew
     * differs from machine to machine, and is exactly how a panel ends up
     * looking thin and slanted on one person's screen and fine on another's.
     */
    public static function stack(): string
    {
        return "'".self::family()."', ".implode(', ', self::FALLBACKS);
    }
}
