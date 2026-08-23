<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\TranscriptionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * הכתבה קולית מול מודל שרץ אצלנו.
 *
 * שני דברים נבדקים כאן יותר מכל: שאודיו לא יוצא לשום מקום עד שמישהו הגדיר
 * במפורש לאן, ושכישלון בתמלול אינו חוזר כטקסט ריק. "לא שמענו" ו"לא נאמר כלום"
 * הן שתי תשובות שונות, ורק הראשונה שווה ניסיון נוסף — ותמלול ריק שנכנס לתיבת
 * ההוראות היה מגיע לסוכן כאילו המנהל לא אמר דבר.
 */
class TranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): void
    {
        config([
            'transcription.enabled' => true,
            'transcription.url' => 'http://whisper:8000/v1/audio/transcriptions',
            'transcription.model' => 'ivrit-ai',
            'transcription.language' => 'he',
        ]);
    }

    private function clip(): UploadedFile
    {
        return UploadedFile::fake()->create('recording.webm', 40, 'audio/webm');
    }

    // ---- the client ---------------------------------------------------------

    /**
     * בלי כתובת מוגדרת — שום אודיו לא נשלח לשום מקום.
     *
     * ברירת מחדל שמנחשת יעד היא בדיוק הדרך שבה הקלטה של מנהל שמדבר על לקוחות
     * מגיעה לשרת שאיש לא בחר.
     */
    public function test_no_audio_leaves_the_machine_until_an_endpoint_is_configured(): void
    {
        config(['transcription.enabled' => true, 'transcription.url' => '']);
        Http::fake();

        $this->assertFalse(app(TranscriptionClient::class)->enabled());
        $this->assertNull(app(TranscriptionClient::class)->transcribe(__FILE__));

        Http::assertNothingSent();
    }

    /** התשובה הרגילה נקראת. */
    public function test_it_reads_the_transcript(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response(['text' => '  תוריד עשרים אחוז   על כל החולצות '])]);

        $this->assertSame(
            'תוריד עשרים אחוז על כל החולצות',
            app(TranscriptionClient::class)->transcribe(__FILE__),
        );
    }

    /**
     * וגם תשובה שמחזירה מקטעים במקום טקסט אחד.
     *
     * whisper.cpp ו-faster-whisper מדברים אותו דיאלקט בקווים כלליים ונבדלים
     * בפרטים — לקוח שהבין רק צורה אחת היה מחזיר ריק מבקשה שהצליחה.
     */
    public function test_it_reads_a_segmented_answer_too(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response(['segments' => [
            ['text' => 'תוסיף את דנה'],
            ['text' => ' כעורכת באתר'],
        ]])]);

        $this->assertSame('תוסיף את דנה כעורכת באתר', app(TranscriptionClient::class)->transcribe(__FILE__));
    }

    /** ותשובה שלא מזוהה מחזירה null — לא מחרוזת ריקה. */
    public function test_an_unfamiliar_answer_is_not_mistaken_for_silence(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response(['something_else' => 'x'])]);

        $this->assertNull(app(TranscriptionClient::class)->transcribe(__FILE__));
    }

    /** וכך גם שגיאת HTTP. */
    public function test_a_failed_request_returns_nothing_rather_than_empty_text(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertNull(app(TranscriptionClient::class)->transcribe(__FILE__));
    }

    // ---- the endpoint -------------------------------------------------------

    /** הנקודה סגורה למי שאינו מחובר. */
    public function test_the_endpoint_is_team_only(): void
    {
        $this->configured();

        $this->postJson(route('agent.transcribe'), ['audio' => $this->clip()])
            ->assertUnauthorized();
    }

    /** כשאין מודל מוגדר — נאמר כך, ולא נכשל בשקט. */
    public function test_it_says_so_when_transcription_is_not_configured(): void
    {
        config(['transcription.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('agent.transcribe'), ['audio' => $this->clip()])
            ->assertStatus(503);
    }

    /** הקלטה תקינה חוזרת כטקסט. */
    public function test_a_recording_comes_back_as_words(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response(['text' => 'תנקה קאש באתר של דנה'])]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('agent.transcribe'), ['audio' => $this->clip()])
            ->assertOk()
            ->assertJson(['text' => 'תנקה קאש באתר של דנה']);
    }

    /**
     * ותמלול שנכשל חוזר כשגיאה עם הסבר — לא כטקסט ריק.
     *
     * טקסט ריק היה נכנס לתיבת ההוראות ומגיע לסוכן כאילו המנהל לא אמר דבר.
     */
    public function test_a_failure_is_an_error_and_not_an_empty_instruction(): void
    {
        $this->configured();
        Http::fake(['*' => Http::response(['unexpected' => true])]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('agent.transcribe'), ['audio' => $this->clip()])
            ->assertStatus(422)
            ->assertJsonMissingPath('text');
    }

    /** וקובץ שאינו אודיו נדחה. */
    public function test_a_file_that_is_not_audio_is_refused(): void
    {
        $this->configured();
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson(route('agent.transcribe'), [
                'audio' => UploadedFile::fake()->create('payload.php', 4, 'text/x-php'),
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
