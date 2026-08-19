<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Models\WebhookEvent;
use App\Services\Kesher\KesherClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * נקודת הקצה של קשר מקליטה — ולא פועלת.
 *
 * התיעוד של קשר מתאר את המטענים בקווים כלליים, וה-API רגיש לאותיות: שם שדה
 * שנוחש באות אחת שגויה אינו שגיאה, הוא ערך שפשוט לא מגיע. כתיבת לוגיקת גבייה
 * מול שמות מנוחשים הייתה מייצרת בדיוק את הכשל שהמערכת הזו נבנית שוב ושוב כדי
 * למנוע — קוד שמזיז כסף, מדווח הצלחה, ולא עשה דבר.
 *
 * לכן הנקודה עולה קודם ומאזינה, והמסירות האמיתיות הן שיהיו המפרט.
 */
class KesherWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.kesher.webhook_secret' => 'top-secret']);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(array $payload, string $secret = 'top-secret'): TestResponse
    {
        return $this->postJson(route('webhooks.kesher').'?secret='.$secret, $payload);
    }

    private function collection(string $tranId = '55501'): array
    {
        return ['CrmTranObject' => [
            'TranId' => $tranId,
            'ProjectNumber' => '7',
            'Sum' => 24900,
            'KesherStatus' => 4,
        ]];
    }

    /** מסירה נרשמת בדיוק כפי שהגיעה. */
    public function test_a_delivery_is_recorded_as_sent(): void
    {
        $this->send($this->collection())->assertOk();

        $event = WebhookEvent::where('source', WebhookSource::Kesher)->sole();

        $this->assertSame('transaction', $event->event_type);
        $this->assertSame(24900, data_get($event->payload, 'CrmTranObject.Sum'));
    }

    /** התראה על התחייבות מזוהה כסוג אחר. */
    public function test_an_obligation_notification_is_recorded_under_its_own_type(): void
    {
        $this->send(['obligation_obj' => ['ObligationReference' => 'OB-1', 'ObligationStatus' => 1]])->assertOk();

        $this->assertSame('obligation', WebhookEvent::sole()->event_type);
    }

    /**
     * מסירה כפולה אינה נרשמת פעמיים.
     *
     * רישום כפול של אותה גבייה הוא חיוב כפול וחשבונית כפולה.
     */
    public function test_the_same_delivery_is_recorded_once(): void
    {
        $this->send($this->collection())->assertOk();
        $this->send($this->collection())->assertOk()->assertSee('DUPLICATE');

        $this->assertSame(1, WebhookEvent::count());
    }

    /** ושתי גביות שונות נשארות שני אירועים. */
    public function test_two_different_collections_stay_two_events(): void
    {
        $this->send($this->collection('55501'))->assertOk();
        $this->send($this->collection('55502'))->assertOk();

        $this->assertSame(2, WebhookEvent::count());
    }

    /**
     * מטען בלי מזהה מוכר עדיין מקבל זהות יציבה.
     *
     * הכיוון חשוב: מסירה זהות נופלת על אותה שורה, ומסירה ששונה ולו בשדה אחד
     * נחשבת אירוע נפרד — רישום כפול של גבייה הוא חיוב כפול, והשמטה של גבייה
     * אמיתית היא כסף שלא מופיע.
     */
    public function test_a_payload_with_no_known_id_still_dedupes_on_its_content(): void
    {
        $this->send(['SomethingElse' => ['A' => 1]])->assertOk();
        $this->send(['SomethingElse' => ['A' => 1]])->assertOk();
        $this->send(['SomethingElse' => ['A' => 2]])->assertOk();

        $this->assertSame(2, WebhookEvent::count());
    }

    /**
     * אותה עסקה עם סטטוס שהשתנה היא אירוע חדש — לא כפילות.
     *
     * קשר מודיע על אותה עסקה יותר מפעם אחת בדרכה (ממתין, ואז נגבה). זהות
     * שנבנית ממזהה העסקה בלבד הייתה מתייגת את ההודעה השנייה ככפילות וזורקת
     * אותה — וזו בדיוק המעבר שהשלב הזה קיים כדי לתפוס.
     */
    public function test_the_same_transaction_with_a_changed_status_is_a_new_event(): void
    {
        $waiting = $this->collection();
        $waiting['CrmTranObject']['KesherStatus'] = 8;

        $this->send($waiting)->assertOk();
        $this->send($this->collection())->assertOk();

        $this->assertSame(2, WebhookEvent::count());
    }

    /** בלי הסוד — נדחה, ושום דבר לא נרשם. */
    public function test_a_request_without_the_secret_is_refused(): void
    {
        $this->send($this->collection(), secret: 'wrong')->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    /**
     * וסוד ריק אינו אומר "קבל הכל".
     *
     * זו הצורה שבה אינטגרציה שלא הוגדרה עד הסוף הופכת בשקט לנקודת קצה פתוחה.
     */
    public function test_a_blank_configured_secret_never_means_accept_everything(): void
    {
        config(['billing.kesher.webhook_secret' => '']);

        $this->send($this->collection(), secret: '')->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    /** הלקוח כבוי כל עוד אין הגדרות — אינטגרציה שמזיזה כסף לא נדלקת מעצם הפריסה. */
    public function test_the_client_stays_off_until_it_is_configured(): void
    {
        config(['billing.kesher.enabled' => true, 'billing.kesher.username' => null]);
        Http::fake();

        $this->assertFalse(app(KesherClient::class)->canCall());
        $this->assertNull(app(KesherClient::class)->call('GetObligations'));

        Http::assertNothingSent();
    }

    /**
     * ומי שיש לו רק טוקן יכול להשתמש בשער השני.
     *
     * שני השערים מתאמתים אחרת, והתניית שער הטוקן בסיסמה של השער השני הייתה
     * מחזירה null מכל קריאה בלי לומר למה.
     */
    public function test_a_token_only_setup_can_still_use_the_named_endpoints(): void
    {
        config([
            'billing.kesher.enabled' => true,
            'billing.kesher.username' => null,
            'billing.kesher.password' => null,
            'billing.kesher.token' => 'bearer-token',
        ]);

        $client = app(KesherClient::class);

        $this->assertFalse($client->canCall());
        $this->assertTrue($client->canUseEndpoints());
    }

    /** ותשובת קשר נקראת לפי Status, ולא לפי ניחוש על קודים. */
    public function test_success_is_read_from_the_field_that_answers_it(): void
    {
        $client = app(KesherClient::class);

        $this->assertTrue($client->succeeded(['Status' => true, 'Code' => 944]));
        $this->assertFalse($client->succeeded(['Status' => false, 'Code' => 0]));
        // No Status field (older endpoints) — then the codes are read.
        $this->assertTrue($client->succeeded(['Code' => 458]));
        $this->assertFalse($client->succeeded(['Code' => 4]));
        $this->assertFalse($client->succeeded(null));
    }
}
