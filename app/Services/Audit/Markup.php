<?php

namespace App\Services\Audit;

/**
 * Reading HTML without pretending a regex is a parser.
 *
 * One place, because the mistake it guards against was already made once in
 * this feature and then made again somewhere else: attributes in HTML have no
 * order, and `<meta content="…" name="description">` is the same tag as the
 * other way round. A pattern that insists on one order tells a site with a
 * perfectly good description — or a perfectly sane viewport — that it has none.
 *
 * In a document handed to a prospect, that is the finding they check first, and
 * the one that decides whether they believe anything under it.
 */
class Markup
{
    /**
     * The content of a meta tag identified by one of its attributes.
     *
     * @param  string  $attribute  which attribute names the tag — `name` or `property`
     * @param  int  $minimum  the shortest content worth calling an answer
     */
    public static function meta(string $markup, string $attribute, string $value, int $minimum = 1): ?string
    {
        preg_match_all('#<meta\b[^>]*>#i', $markup, $tags);

        foreach ($tags[0] as $tag) {
            // (?<![-\w]) and not \b: \b would also match the tail of data-name=,
            // and a tag matched by the wrong attribute is the same false finding
            // by another route.
            $named = preg_match(
                '#(?<![-\w])'.preg_quote($attribute, '#').'\s*=\s*(["\']?)'.preg_quote($value, '#').'\1[\s/>]#i',
                $tag.' ',
            ) === 1;

            if (! $named || preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $tag, $found) !== 1) {
                continue;
            }

            $content = trim($found[2]);

            if (mb_strlen($content) >= $minimum) {
                return $content;
            }
        }

        return null;
    }
}
