<?php

namespace Tests\Feature;

use App\Jobs\SendTicketNotificationJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A customer who only says hello has no "topic" to reflect back. Restating one
 * produced the embarrassing "קיבלנו את ההודעה ששלחת לגבי פנייה לא מזוהה והצגת
 * השירותים בברכת שלום אדיבה" — so a greeting-only opener takes a different,
 * short acknowledgement path.
 */
class GreetingAckTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function greetings(): array
    {
        return [['היי בוקר טוב'], ['שלום'], ['אהלן!'], ['בוקר טוב 🙂'], ['hi'], ['Good morning'], ['שבת שלום']];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function realRequests(): array
    {
        return [
            ['היי, האתר שלי לא עולה'],
            ['שלום, יש לי בעיה בטופס יצירת הקשר'],
            ['בוקר טוב, אפשר לקבל חשבונית על הזמנה 4512?'],
            ['hello, my site is down'],
            ['אני צריך לשנות את הכתובת בעמוד צור קשר בבקשה'],
        ];
    }

    #[DataProvider('greetings')]
    public function test_a_greeting_only_message_has_no_topic(string $message): void
    {
        $this->assertTrue(SendTicketNotificationJob::isGreetingOnly($message), "expected a greeting: {$message}");
    }

    #[DataProvider('realRequests')]
    public function test_a_real_request_is_never_treated_as_a_greeting(string $message): void
    {
        $this->assertFalse(SendTicketNotificationJob::isGreetingOnly($message), "expected a real request: {$message}");
    }

    public function test_an_empty_message_is_not_a_greeting(): void
    {
        // Nothing to greet back — the normal ack path handles it.
        $this->assertFalse(SendTicketNotificationJob::isGreetingOnly('   '));
    }
}
