<?php

namespace App\Services\SiteAgent;

/**
 * Catch approval invitations in model prose that has no saved proposal behind it.
 *
 * This inspects the model's output, never the owner's wording. It is deliberately
 * a narrow protocol guard, not an intent parser or a claim that arbitrary prose
 * can be verified with regular expressions.
 */
final class SiteAgentReplyGuard
{
    public function asksForApproval(string $reply): bool
    {
        $reply = mb_strtolower(mb_substr($reply, 0, 20000, 'UTF-8'), 'UTF-8');
        $reply = str_replace(
            ['״', '“', '”', '„', '«', '»', '׳', '‘', '’', '`', '*', '_', "\u{200E}", "\u{200F}", "\u{200B}"],
            ['"', '"', '"', '"', '"', '"', "'", "'", "'", "'", '', '', '', '', ''],
            $reply,
        );
        $reply = preg_replace('/\s+/u', ' ', $reply) ?? '';

        $directive = '(?:השיבו|השב|השיבי|הגיבו|הגב|הגיבי|כתבו|כתוב|כתבי|שלחו|שלח|שלחי|ענו|ענה|עני|תכתבו|תכתוב|תכתבי|תשיבו|תשיב|תשיבי|לחצו|לחץ|לחצי)';
        $connector = '(?:\s+(?:לי|כאן|בתשובה|בהודעה|רק|במילה|את\s+המילה|על\s+הכפתור|על)){0,3}';
        $yes = '(?:כן|מאשר|מאשרת|מאשרים|מאשרות|אישור|yes)';
        $target = '(?:השינוי|העדכון|הפעולה|ההצעה|זה|זאת|אותו|אותה)';
        $continuation = '(?:עם\s+(?:השינוי|העדכון|הפעולה|ההצעה)|ב(?:שינוי|עדכון|פעולה|הצעה)|לביצוע)';
        $boundary = '(?![\p{L}\p{N}_])';
        $affirmative = '(?:ב\s*)?["\']?'.$yes.$boundary.'["\']?';
        $patterns = [
            // Quoted and unquoted yes/no can both answer an ordinary
            // clarification. Require an execution/approval purpose nearby.
            '(?:(?:לאישור|לביצוע)(?:\s+'.$target.')?|כדי\s+(?:לאשר|לבצע|להחיל))\s*[:—–,-]?\s*'.$directive.$connector.'\s*[:—–-]?\s*'.$affirmative,
            $directive.$connector.'\s*[:—–-]?\s*'.$affirmative.'\s*(?:לאישור|לביצוע|כדי\s+(?:לאשר|לבצע)|ואבצע|ואעדכן)',
            // Questions explicitly asking permission to perform a change.
            'האם\s+(?:לאשר|לבצע|להחיל)'.$boundary,
            '(?:לאשר|לבצע|להחיל)(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            '(?:האם\s+)?(?:להמשיך|להתקדם)\s+'.$continuation.'\s*[?؟]',
            'האם\s+(?:להמשיך|להתקדם)\s+'.$continuation.$boundary,
            '(?:מאשר|מאשרת|מאשרים|מאשרות|מאושר)(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            '(?:תרצה|תרצי|תרצו|רוצה|רוצים|רוצות|אפשר)\s+ש(?:אבצע|אעדכן|אשמור|אחיל)(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            // English providers sometimes answer in English despite the site
            // language. Keep the same distinction between approval and data.
            '(?:reply|respond|type|send|say|answer|click|press)\s+(?:with\s+)?["\']?yes\b["\']?\s+(?:to\s+)?(?:confirm|approve|proceed|apply)\b',
            '(?:to\s+)?(?:confirm|approve|proceed|apply)\s*[,;:]?\s*(?:please\s+)?(?:reply|respond|type|send|say|answer)\s+(?:with\s+)?["\']?yes\b',
            '(?:please|kindly)\s+(?:confirm|approve)\s+(?:(?:the|this|these|proposed)\s+)?(?:changes?|updates?|actions?|proposals?|edits?)\b',
            '(?:^|[.!?]\s+)(?:confirm|approve)\s+(?:(?:the|this|these|proposed)\s+)?(?:changes?|updates?|actions?|proposals?|edits?)\b',
            '(?:^|[.!?]\s+)(?:confirm|approve)\s*\?',
            '(?:shall|should|can|may)\s+i\s+(?:proceed|apply\s+(?:the|this)\s+(?:change|update)|make\s+(?:the|this)\s+change)\s*\?',
            'would\s+you\s+like\s+me\s+to\s+(?:proceed|apply\s+(?:the|this)\s+(?:change|update)|make\s+(?:the|this)\s+change)\s*\?',
            'do\s+you\s+(?:approve|confirm)(?:\s+(?:the|this)\s+(?:change|update|proposal))?\s*\?',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match('/(?<![\p{L}\p{N}_])(?:'.$pattern.')/u', $reply) === 1) {
                return true;
            }
        }

        return false;
    }
}
