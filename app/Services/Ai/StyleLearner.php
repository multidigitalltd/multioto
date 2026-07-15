<?php

namespace App\Services\Ai;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Setting;
use App\Models\TicketMessage;
use Illuminate\Support\Str;

/**
 * Learns the team's writing style from past replies: it reads recent agent
 * answers and asks the AI to distil them into a short style guide, which is
 * then stored and fed into every future draft. Not model fine-tuning — the
 * summary rides along in the drafter's system prompt, so corrections the team
 * makes over time steer new drafts toward how they actually write.
 */
class StyleLearner
{
    public function __construct(private ClaudeClient $claude) {}

    public const MIN_REPLIES = 5;

    /**
     * Read the latest agent replies, summarise their style, persist it, and
     * return it. Null when the AI is off or there aren't enough replies yet.
     */
    public function refresh(int $sample = 60): ?string
    {
        if (! $this->claude->isEnabled()) {
            return null;
        }

        // Ratings only ever land on rateable replies, so a rated row IS a reply.
        // Learn primarily from the highly-rated ones, avoid the low-rated ones,
        // and fill the baseline with recent (unrated) team replies.
        $good = TicketMessage::query()
            ->where('quality_rating', '>=', 7)
            ->latest('id')->limit($sample)
            ->get(['body', 'quality_rating']);

        $poor = TicketMessage::query()
            ->whereNotNull('quality_rating')->where('quality_rating', '<=', 4)
            ->latest('id')->limit(10)
            ->get(['body', 'quality_rating']);

        $recent = TicketMessage::query()
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Agent)
            ->where('channel', '!=', MessageChannel::InternalNote)
            ->whereNull('quality_rating')
            ->latest('id')->limit($sample)
            ->pluck('body')
            ->map(fn ($body) => trim((string) $body))
            ->filter();

        // Need enough positive/neutral material to learn a style from.
        if ($good->count() + $recent->count() < self::MIN_REPLIES) {
            return null;
        }

        $rated = fn ($m): string => '— (דירוג '.$m->quality_rating.'/10) '.Str::limit(trim((string) $m->body), 500);

        $prompt = "תשובות שדורגו גבוה — למד מהן בעיקר:\n".($good->map($rated)->implode("\n") ?: '(אין עדיין)');
        if ($recent->isNotEmpty()) {
            $prompt .= "\n\nתשובות אחרונות של הצוות:\n".$recent->map(fn ($b) => '— '.Str::limit($b, 500))->implode("\n");
        }
        if ($poor->isNotEmpty()) {
            $prompt .= "\n\nתשובות שדורגו נמוך — הימנע מהדפוסים האלה:\n".$poor->map($rated)->implode("\n");
        }

        $result = $this->claude->structured(
            system: 'אתה מנתח סגנון כתיבה של צוות תמיכה. סכם בקצרה (עד 8 נקודות) את הטון, הניסוחים החוזרים, הפתיחות והחתימות וכללי הסגנון. תן משקל רב יותר לתשובות שדורגו גבוה והימנע מדפוסים של תשובות שדורגו נמוך. כתוב בעברית, תמציתי, כהנחיה לסוכן שינסח באותו סגנון בדיוק.',
            prompt: $prompt,
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
            ],
        );

        $summary = trim((string) ($result['summary'] ?? ''));
        if ($summary === '') {
            return null;
        }

        Setting::put('ai.style_summary', $summary);
        config(['billing.ai.style_summary' => $summary]); // live for the current request

        return $summary;
    }
}
