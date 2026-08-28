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
    public const DEFAULT_FAMILY = 'Rubik';

    /** Faces that carry Hebrew, for when the webfont does not arrive at all. */
    public const FALLBACKS = ["'Segoe UI'", "'Noto Sans Hebrew'", "'Arial Hebrew'", 'Arial', 'sans-serif'];

    /** The weights the panel's own utilities use, whatever the base is set to. */
    public const REQUIRED_WEIGHTS = [400, 500, 600, 700];

    public static function family(): string
    {
        return trim((string) config('billing.branding.panel_font')) ?: self::DEFAULT_FAMILY;
    }

    /**
     * The base text weight — snapped to a weight that can actually be fetched.
     *
     * Snapped, not passed through: Google rejects a `wght` value a family does
     * not list, and a rejected request costs the whole stylesheet rather than
     * one face. Multiples of 100 are what every family publishes.
     *
     * The same number is used in the CSS and in the URL below, so the weight the
     * panel asks for is always one it downloaded. Without that, setting 450 or
     * 800 changed the CSS, fetched nothing, and the browser quietly rendered the
     * nearest weight it happened to have — a knob that reports success and does
     * nothing.
     */
    public static function weight(): int
    {
        $configured = (int) config('billing.branding.panel_font_weight', 500);

        return (int) min(900, max(100, round($configured / 100) * 100));
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

        // The panel's own weights, plus whatever base weight was configured —
        // otherwise a base outside the four would be styled and never fetched.
        $weights = array_unique(array_merge(self::REQUIRED_WEIGHTS, [self::weight()]));
        sort($weights);

        return 'https://fonts.googleapis.com/css2?family='
            .str_replace(' ', '+', self::family())
            .':wght@'.implode(';', $weights)
            .'&display=swap';
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
