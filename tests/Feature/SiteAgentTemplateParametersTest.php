<?php

namespace Tests\Feature;

use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * הפרמטרים של תבנית נשלחים בשם.
 *
 * מטא דוחה היום משתנים מספריים בממשק יצירת התבניות ("אותיות קטנות עם קו תחתון
 * בודד"), ולכן כל תבנית נבנית עם שמות. השתיים אינן מתחלפות זו בזו בשליחה:
 * פרמטר מספרי שנשלח לתבנית בעלת שמות נדחה — והלקוח פשוט לא מקבל כלום, בלי
 * שגיאה שמגיעה לאף מסך אצלנו. זה הכישלון השקט שהבדיקות כאן קיימות בשבילו.
 */
class SiteAgentTemplateParametersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siteagent.whatsapp.phone_number_id' => '123456',
            'siteagent.whatsapp.token' => 'token',
            'siteagent.whatsapp.templates.language' => 'he',
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);
    }

    /** @return array<int, array<string, mixed>> the body parameters that went out */
    private function sentParameters(): array
    {
        $sent = [];

        Http::recorded(function ($request) use (&$sent): void {
            foreach ((array) data_get($request->data(), 'template.components', []) as $component) {
                if (($component['type'] ?? '') === 'body') {
                    $sent = $component['parameters'];
                }
            }
        });

        return $sent;
    }

    /** כל פרמטר יוצא עם השם שהתבנית מכירה. */
    public function test_parameters_go_out_with_their_names(): void
    {
        app(WhatsAppCloudClient::class)->sendTemplate('972501234567', 'site_agent_paused', [
            'domain' => 'example.co.il',
            'action' => 'לחידוש המנוי דברו איתנו',
        ]);

        $this->assertSame([
            ['type' => 'text', 'parameter_name' => 'domain', 'text' => 'example.co.il'],
            ['type' => 'text', 'parameter_name' => 'action', 'text' => 'לחידוש המנוי דברו איתנו'],
        ], $this->sentParameters());
    }

    /**
     * ירידת שורה בפרמטר נדחית על ידי מטא — גם כשהוא בשם.
     *
     * הטקסט של "מה לעשות עכשיו" מורכב משורות, ולפעמים מכיל קישור לעדכון אמצעי
     * תשלום. בלי הכיווץ הזה ההודעה הזאת לא הייתה נשלחת אף פעם.
     */
    public function test_a_multiline_parameter_is_flattened(): void
    {
        app(WhatsAppCloudClient::class)->sendTemplate('972501234567', 'site_agent_paused', [
            'domain' => 'example.co.il',
            'action' => "לעדכון אמצעי התשלום:\nhttps://example.test/card\n\nלכל שאלה: service@multidigital.co.il",
        ]);

        $action = $this->sentParameters()[1]['text'];

        $this->assertStringNotContainsString("\n", $action);
        $this->assertStringContainsString('https://example.test/card', $action);
    }
}
