<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Models\Site;
use App\Services\SiteAgent\SiteActionProposer;
use Throwable;

/** Observes real proposal validation without retaining model values or exception text. */
final class EvaluationSiteActionProposer extends SiteActionProposer
{
    private const LIMIT = 50;

    private const MESSAGES = [
        'target_not_read' => 'יש לקרוא את היעד בסבב הנוכחי לפני הכנת הצעה.',
        'invalid_identity' => 'מזהה היעד אינו תקין או אינו תואם לפריט שנקרא.',
        'invalid_value' => 'חסר ערך נדרש או שהערך אינו עומד בכללי השדה.',
        'unsupported_field' => 'הבקשה כוללת שדה שאינו נתמך לעריכה.',
        'permission_denied' => 'אין הרשאה לפעולה המבוקשת.',
        'stale_state' => 'מצב הפריט השתנה או שאין שינוי חדש להכין.',
        'read_failed' => 'לא ניתן היה לקרוא ולאמת את נתוני האתר.',
        'unsupported_action' => 'הפעולה אינה נתמכת ביכולות הזמינות.',
        'validation_rejected' => 'הצעת השינוי לא עברה את האימות.',
    ];

    private array $observed = [];

    public function propose(Site $site, string $name, array $input, array $seen, ?string $ownerRequest = null): array
    {
        try {
            $result = parent::propose($site, $name, $input, $seen, $ownerRequest);
        } catch (Throwable $error) {
            $this->observe($site, $name, $input, 'exception', 'validation_rejected');

            throw $error;
        }

        $rejected = isset($result['error']);
        $kind = $rejected ? 'rejected' : (isset($result['plan'], $result['preview']) ? 'prepared' : 'invalid_result');
        $code = $rejected && is_string($result['error']) ? $this->rejectionCode($result['error'])
            : ($kind === 'prepared' ? null : 'validation_rejected');
        $this->observe($site, $name, $input, $kind, $code);

        return $result;
    }

    public function diagnostics(): array
    {
        return $this->observed;
    }

    private function observe(Site $site, string $name, array $input, string $kind, ?string $code): void
    {
        if (count($this->observed) >= self::LIMIT) {
            return;
        }

        $this->observed[] = [
            'tool' => $this->isProposal($name) ? $this->safeField($name, $site) : 'unknown_tool',
            'result_kind' => $kind,
            'rejection_code' => $code,
            'validation_message' => $code !== null ? self::MESSAGES[$code] : null,
            'input_keys' => $this->fieldNames(array_keys($input), $site),
            'value_keys' => is_array($input['values'] ?? null) ? $this->fieldNames(array_keys($input['values']), $site) : [],
        ];
    }

    /** Classify known validation language; arbitrary provider/exception text is never exported. */
    private function rejectionCode(string $message): string
    {
        $patterns = [
            'target_not_read' => '/לא הופיע באף קריאה|חפשו אותו קודם|יש לקרוא[^\n]*בסבב הנוכחי|קרא[^\n]*לפני[^\n]*הצע/u',
            'read_failed' => '/לא הצלחתי לקרוא|לא התקבל[^\n]*מהאתר|האתר החזיר תשובה לא תקינה/u',
            'permission_denied' => '/כבויה בחשבון|הרשאה|הרשאות|אינה מורשית/u',
            'unsupported_field' => '/שדות נתמכים|שדה[^\n]*(?:אינו ניתן לעריכה|מוגן|אינו נתמך)|שדות[^\n]*אינם נתמכים/u',
            'stale_state' => '/השתנ|כבר במצב המבוקש|אין מה לשנות|פג תוקף/u',
            'invalid_identity' => '/מזהה[^\n]*(?:תקין|לא נמצא)|אינו דף הבית|האתר החזיר פריט אחר|אינו תואם|לא נמצא/u',
            'unsupported_action' => '/אין כלי בשם|אינה נתמכת|אינו נתמך|אין תמיכה|דורש[^\n]*עדכון|דורשת[^\n]*עדכון|עדכון[^\n]*תוסף|בלתי הפי/u',
            'invalid_value' => '/ערך|ערכים|חייב|חסר|טווח|true|false|אורך|תאריך|שעה|מחיר/u',
        ];
        foreach ($patterns as $code => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return $code;
            }
        }

        return 'validation_rejected';
    }

    private function fieldNames(array $keys, Site $site): array
    {
        return array_values(array_unique(array_map(fn (mixed $key): string => $this->safeField($key, $site), array_slice($keys, 0, 30))));
    }

    private function safeField(mixed $field, Site $site): string
    {
        if (! is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1) {
            return 'unknown_field';
        }
        foreach ([(string) config('billing.ai.api_key'), (string) $site->mcp_secret] as $secret) {
            if ($secret !== '' && str_contains($field, $secret)) {
                return 'redacted_field';
            }
        }

        return $field;
    }
}
