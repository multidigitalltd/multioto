<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * לסוכן אין שעון.
 *
 * כשמבקשים ממנו לפתוח הודעה בחום הוא מנחש, ו"בוקר טוב" בתשע בערב הוא בדיוק סוג
 * הטעות הקטנה שמסגירה ללקוח שאף אחד לא באמת היה שם. השעה נשלחת אליו במקום
 * אחד — זה שדרכו עוברת כל הודעה שהסוכן כותב.
 */
class AiPromptTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.api_key' => 'test-key',
            'billing.ai.provider' => 'anthropic',
            'billing.ai.model' => 'claude-test',
            'app.timezone' => 'Asia/Jerusalem',
        ]);
    }

    /** מה שנשלח לספק בשדה ה-system. */
    private function systemPromptSent(): string
    {
        $sent = '';

        Http::fake(function ($request) use (&$sent) {
            $sent = (string) ($request->data()['system'] ?? '');

            return Http::response([
                'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]);
        });

        app(ClaudeClient::class)->structured('ענה במבנה JSON.', 'שלום', [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ]);

        return $sent;
    }

    /** השעה הנוכחית מגיעה לסוכן. */
    public function test_the_current_time_reaches_the_model(): void
    {
        $this->travelTo(now()->setTime(21, 15));

        $this->assertStringContainsString(now()->format('d/m/Y H:i'), $this->systemPromptSent());
    }

    /** ואיתה הכלל: ברכה תלוית שעה חייבת להתאים לשעה. */
    public function test_the_greeting_rule_travels_with_it(): void
    {
        $prompt = $this->systemPromptSent();

        $this->assertStringContainsString('בוקר טוב', $prompt);
        $this->assertStringContainsString('ערב טוב', $prompt);
        $this->assertStringContainsString('שלום', $prompt);
    }

    /** אזור הזמן נקרא מההגדרות ולא מונח — הצהרה בטוחה היא הצהרה נכונה. */
    public function test_the_timezone_is_read_from_configuration(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);

        $this->assertStringContainsString('Europe/Berlin', $this->systemPromptSent());
    }

    /** ההוראה המקורית לא נדרסת — היא נשארת ראשונה. */
    public function test_the_original_instruction_survives(): void
    {
        $this->assertStringStartsWith('ענה במבנה JSON.', $this->systemPromptSent());
    }
}
