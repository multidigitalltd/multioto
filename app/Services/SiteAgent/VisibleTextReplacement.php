<?php

namespace App\Services\SiteAgent;

/** A plain-text edit must never become an edit of markup or link attributes. */
final class VisibleTextReplacement
{
    public static function matchesOnce(string $content, string $find): bool
    {
        if ($find === '' || str_contains($find, "\0") || strip_tags($find) !== $find
            || mb_substr_count($content, $find) !== 1) {
            return false;
        }

        // Preserve boundaries between text nodes: stripping tags alone would
        // turn foo<b>bar</b> into foobar and could authorize a match in an href.
        $visible = preg_replace('/<(script|style)\b[^>]*>.*?(?:<\/\1\s*>|$)/isu', "\0", $content);
        $visible = preg_replace('/<!--.*?(?:-->|$)|<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>/s', "\0", $visible ?? '');

        // An unfinished tag or quoted attribute could contain the match. Do
        // not interpret its remainder as a text node after removing valid tags.
        return is_string($visible) && preg_match('/<[a-z\/!?]/i', $visible) === 0
            && mb_substr_count($visible, $find) === 1;
    }
}
