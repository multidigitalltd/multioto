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

    /** תבנית שאנחנו כותבים את גופה — כל פרמטר יוצא עם שמו. */
    public function test_parameters_go_out_with_their_names(): void
    {
        app(WhatsAppCloudClient::class)->sendTemplate('972501234567', 'site_agent_paused', [
            'domain' => 'example.co.il',
            'action' => 'לחידוש המנוי דברו איתנו',
        ]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'example.co.il', 'parameter_name' => 'domain'],
            ['type' => 'text', 'text' => 'לחידוש המנוי דברו איתנו', 'parameter_name' => 'action'],
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

    /**
     * ותבנית אימות — הקוד יוצא בלי שם, כי אין לו שם לתת.
     *
     * את הגוף של תבנית Authentication כותבת מטא, וה-placeholder של הקוד מוגדר
     * מראש. פרמטר בשם נדחה שם, והלקוח החדש פשוט לא מקבל את הקוד שמפעיל את מה
     * שזה עתה שילם עליו — הכישלון היקר ביותר במוצר הזה.
     */
    public function test_an_authentication_code_goes_out_without_a_name(): void
    {
        app(WhatsAppCloudClient::class)->sendTemplate('972501234567', 'site_agent_code', ['123456'], copyCode: '123456');

        $this->assertSame([['type' => 'text', 'text' => '123456']], $this->sentParameters());
    }
}
