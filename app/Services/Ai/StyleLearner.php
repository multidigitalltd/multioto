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

        $replies = TicketMessage::query()
            ->where('direction', MessageDirection::Outbound)
            ->where('author', MessageAuthor::Agent)
            ->where('channel', '!=', MessageChannel::InternalNote)
            ->latest('id')
            ->limit($sample)
            ->pluck('body')
            ->map(fn ($body) => trim((string) $body))
            ->filter()
            ->all();

        if (count($replies) < self::MIN_REPLIES) {
            return null;
        }

        $examples = collect($replies)
            ->map(fn ($body) => '— '.Str::limit($body, 500))
            ->implode("\n");

        $result = $this->claude->structured(
            system: 'אתה מנתח סגנון כתיבה של צוות תמיכה. סכם בקצרה (עד 8 נקודות) את הטון, הניסוחים החוזרים, הפתיחות והחתימות וכללי הסגנון שאפשר ללמוד מהתשובות. כתוב בעברית, תמציתי, כהנחיה לסוכן שינסח באותו סגנון בדיוק.',
            prompt: "אלה התשובות האחרונות של הצוות ללקוחות:\n\n{$examples}",
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
