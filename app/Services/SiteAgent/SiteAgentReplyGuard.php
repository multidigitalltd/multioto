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
        $reply = $this->normalize($reply);
        // Keep paragraph boundaries: a yes/no consent question starts a
        // clause, unlike "איזה מוצר תרצי שאעדכן?", which asks for a target.

        $directive = '(?:השיבו|השב|השיבי|השבי|הגיבו|הגב|הגיבי|כתבו|כתוב|כתבי|שלחו|שלח|שלחי|ענו|ענה|עני|תכתבו|תכתוב|תכתבי|תשיבו|תשיב|תשיבי|לחצו|לחץ|לחצי)';
        $connector = '(?:\s+(?:לי|כאן|בתשובה|בהודעה|רק|במילה|את\s+המילה|על\s+הכפתור|על)){0,3}';
        $yes = '(?:כן|מאשר|מאשרת|מאשרים|מאשרות|אישור|yes)';
        $target = '(?:השינוי|העדכון|הפעולה|ההצעה|זה|זאת|אותו|אותה)';
        $continuation = '(?:עם\s+(?:השינוי|העדכון|הפעולה|ההצעה)|ב(?:שינוי|עדכון|פעולה|הצעה)|לביצוע)';
        $boundary = '(?![\p{L}\p{N}_])';
        $affirmative = '(?:ב\s*)?["\']?'.$yes.$boundary.'["\']?';
        $clauseStart = '(?:^\s*|(?<=[.!?؟:;])\s+|(?<=\n)\s*)';
        $consentStart = $clauseStart.'(?:האם\s+)?(?:(?:את|אתה|אתם|אתן)\s+)?(?:תרצה|תרצי|תרצו|רוצה|רוצים|רוצות|אפשר)\s+ש';
        $writeVerb = '(?:אבצע|אעדכן|אשמור|אחיל|אשנה|אגדיר|אהפוך|אסמן|אחליף|אוסיף|אעביר|אצור|איצור|אפרסם|אתזמן|אכבה|אפעיל|אסיר|אמחק|ארשום|אאשר|נבצע|נעדכן|נשמור|נחיל|נשנה|נגדיר|נהפוך|נסמן|נחליף|נוסיף|נעביר|ניצור|נפרסם|נתזמן|נכבה|נפעיל|נסיר|נמחק|נרשום|נאשר)';
        $patterns = [
            // Quoted and unquoted yes/no can both answer an ordinary
            // clarification. Require an execution/approval purpose nearby.
            '(?:(?:לאישור|לביצוע)(?:\s+'.$target.')?|כדי\s+(?:לאשר|לבצע|להחיל))\s*[:—–,-]?\s*(?:אנא\s+|בבקשה\s+)?'.$directive.$connector.'\s*[:—–-]?\s*'.$affirmative,
            $directive.$connector.'\s*[:—–-]?\s*'.$affirmative.'\s*(?:לאישור|לביצוע|כדי\s+(?:לאשר|לבצע)|ואבצע|ואעדכן)',
            // Questions explicitly asking permission to perform a change.
            'האם\s+(?:לאשר|לבצע|להחיל)'.$boundary,
            $clauseStart.'(?:לאשר|לבצע|להחיל)(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            '(?:האם\s+)?(?:להמשיך|להתקדם)\s+'.$continuation.'\s*[?؟]',
            'האם\s+(?:להמשיך|להתקדם)\s+'.$continuation.$boundary,
            $consentStart.'(?:אמשיך|נמשיך|אתקדם|נתקדם)\s+(?:בכך|בזה|עם\s+זה)\s*[?؟]',
            '(?:מאשר|מאשרת|מאשרים|מאשרות|מאושר)(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            $consentStart.$writeVerb.'(?:\s+(?:את\s+)?'.$target.')?\s*[?؟]',
            $consentStart.$writeVerb.'\s+(?:אותו|אותה|אותם|אותן|את\s+[^\s?؟.!]+)'.$boundary.'[^\r\n?؟]{0,180}[?؟]',
            // Preparing an offer is already authorized by the owner's request.
            // A permission question before preparing it adds a phantom step:
            // the first "yes" cannot execute any stored proposal.
            $consentStart.'(?:אגיש|אכין|אציע|נגיש|נכין|נציע)\s+(?:את\s+)?(?:הצעה|ההצעה|שינוי|השינוי|שינויים|השינויים|עדכון|העדכון|פעולה|הפעולה)'.$boundary.'[^\r\n?؟]{0,220}[?؟]',
            '(?:האם\s+)?(?:ליצור|להוסיף|להעביר|לפרסם|לתזמן|להפעיל|לכבות|להסיר|לרשום)\s+[^\r\n?؟]{0,160}[?؟]\s*[^\r\n]{0,40}(?:כן\s*[\/או]+\s*לא|'.$directive.$connector.'\s*[:—–-]?\s*'.$affirmative.')',
            '(?:האם\s+)?(?:את|אתה|אתם|אתן)\s+(?:מאשר|מאשרת|מאשרים|מאשרות)\s+(?:ל|את\s+)[^\r\n?؟]{1,180}[?؟]',
            $clauseStart.'(?:האם\s+)?(?:להגיש|להכין|להציע|ליצור)\s+(?:את\s+)?(?:הצעה|ההצעה|שינוי|השינוי|עדכון|העדכון)'.$boundary.'[^\r\n?؟]{0,180}[?؟]',
            $consentStart.'(?:אצור|איצור|ניצור)\s+(?:הצעה|תצוגה\s+מקדימה)'.$boundary.'[^\r\n?؟]{0,180}[?؟]',
            $consentStart.'(?:אציע|נציע)\s+(?:לרשום|להחליף|להעביר|לשנות|לעדכן|להוסיף|להסיר|ליצור|לפרסם)'.$boundary.'[^\r\n?؟]{0,180}[?؟]',
            '(?:אשמח|נשמח|זקוק|זקוקה|ממתין|ממתינה)\s+(?:לאישורך|לאישורכם|לאישורכן)'.$boundary.'\s+(?:כדי\s+(?:להוסיף|לשנות|לעדכן|להעביר|ליצור|לפרסם|לבצע)|לפני\s+(?:שאגיש|שאכין|שאבצע)|לביצוע|לבצע|להגיש)',
            $consentStart.'(?:אשלח|נעביר|אעביר)\s+(?:את\s+)?(?:הבקשה|השינוי|העדכון)'.$boundary.'[^\r\n?؟]{0,180}(?:לביצוע|לעורך)[?؟]',
            '(?:ברגע|אחרי)\s+ש(?:אגיש|אכין)[^\r\n]{0,200}[.\n]\s*האם\s+להמשיך\s*[?؟]',
            // A claimed handoff to an editor is also unbacked unless the
            // delegate actually ran and returned the saved preview.
            '(?:העברתי|הוגשה|נשלחה)\s+(?:את\s+)?(?:הבקשה|ההצעה)[^\r\n.]{0,180}(?:לטיפול|לעורך|לאישור)',
            // English providers sometimes answer in English despite the site
            // language. Keep the same distinction between approval and data.
            '(?:reply|respond|type|send|say|answer|click|press)\s+(?:with\s+)?["\']?yes\b["\']?\s+(?:to\s+)?(?:confirm|approve|proceed|apply)\b',
            '(?:to\s+)?(?:confirm|approve|proceed|apply)\s*[,;:]?\s*(?:please\s+)?(?:reply|respond|type|send|say|answer)\s+(?:with\s+)?["\']?yes\b',
            '(?:please|kindly)\s+(?:confirm|approve)\s+(?:(?:the|this|these|proposed)\s+)?(?:changes?|updates?|actions?|proposals?|edits?)\b',
            '(?:^|[.!?]\s+)(?:confirm|approve)\s+(?:(?:the|this|these|proposed)\s+)?(?:changes?|updates?|actions?|proposals?|edits?)\b',
            '(?:^|[.!?]\s+)(?:confirm|approve)\s*\?',
            '(?:shall|should|can|may)\s+i\s+(?:proceed|apply\s+(?:the|this)\s+(?:change|update)|make\s+(?:the|this)\s+change)\s*\?',
            'would\s+you\s+like\s+me\s+to\s+(?:proceed|apply\s+(?:the|this)\s+(?:change|update)|make\s+(?:the|this)\s+change)\s*\?',
            '(?:would\s+you\s+like\s+me\s+to|shall\s+i|should\s+i|can\s+i)\s+(?:prepare|submit|create)\s+(?:an?\s+|the\s+)?(?:proposal|preview|change|update)\b[^\r\n?]{0,180}\?',
            'do\s+you\s+(?:approve|confirm)(?:\s+(?:the|this)\s+(?:change|update|proposal))?\s*\?',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match('/(?<![\p{L}\p{N}_])(?:'.$pattern.')/u', $reply) === 1) {
                return true;
            }
        }

        return false;
    }

    /** No support-contact tool exists: neither an offer nor a claimed send is grounded. */
    public function offersUnsupportedHandoff(string $reply): bool
    {
        $reply = $this->normalize($reply);
        $destination = '(?:לצוות(?:\s+(?:התמיכה|הטכני))?|לתמיכה|לשירות\s+הלקוחות)';
        $patterns = [
            '(?<!לא )(?<!אין )(?<!איני )(?<!אינני )(?<!איננו )(?<!אינו )(?<!אינה )(?:(?:אני|אנחנו)\s+)?(?:מעביר|מעבירה|מעבירים|שולח|שולחת|שולחים|אעביר|נעביר|אשלח|נשלח|אפנה|נפנה|פניתי|פנינו|העברתי|העברנו|שלחתי|שלחנו)\s+[^\r\n.!?؟]{0,180}'.$destination,
            'ש?(?:תרצה|תרצי|תרצו|רוצה|רוצים)\s+ש(?:אפנה|נפנה|אעביר|נעביר|אשלח|נשלח)\s+[^\r\n.!?؟]{0,180}'.$destination,
            '(?:אני\s+(?:יכול|יכולה)|אוכל|נוכל)\s+(?:לפנות|להעביר|לשלוח)\s+[^\r\n.!?؟]{0,180}'.$destination,
            '(?:הבקשה|הפנייה|הפניה|פנייתך|קריאת\s+השירות)\s+(?:נשלחה|הועברה|הועברו)\s+[^\r\n.!?؟]{0,180}'.$destination,
            '(?<!לא )(?:(?:אני|אנחנו)\s+)?(?:פתחתי|פתחנו|אפתח|נפתח|פותח|פותחת|פותחים)\s+(?:עבורך\s+|לך\s+)?(?:פנייה|פניה|קריאת\s+שירות|קריאה\s+לתמיכה)',
            '\bi\s+(?:have\s+|will\s+|can\s+)?(?:sent|send|forwarded|forward|contacted|contact)\b[^\r\n.!?]{0,160}\b(?:support|technical\s+team)\b',
            '\b(?:would\s+you\s+like\s+me\s+to|shall\s+i|should\s+i)\s+(?:send|forward|contact)\b[^\r\n.!?]{0,160}\b(?:support|technical\s+team)\b',
            '\byour\s+(?:request|ticket)\s+(?:has\s+been|was)\s+(?:sent|forwarded)\b[^\r\n.!?]{0,160}\bsupport\b',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match('/(?<![\p{L}\p{N}_])(?:'.$pattern.')/u', $reply, $match) === 1
                && preg_match('/\b(?:אותך|אתכם|אתכן)\b/u', $match[0]) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $reply): string
    {
        $reply = mb_strtolower(mb_substr($reply, 0, 20000, 'UTF-8'), 'UTF-8');
        $reply = str_replace(
            ['״', '“', '”', '„', '«', '»', '׳', '‘', '’', '`', '*', '_', "\u{200E}", "\u{200F}", "\u{200B}"],
            ['"', '"', '"', '"', '"', '"', "'", "'", "'", "'", '', '', '', '', ''],
            $reply,
        );
        $reply = str_replace(["\r\n", "\r"], "\n", $reply);

        return preg_replace('/[^\S\r\n]+/u', ' ', $reply) ?? '';
    }
}
