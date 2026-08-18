<?php

namespace Tests\Feature;

use App\Jobs\IngestEmailMessageJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Support\AgentReply;
use App\Services\Support\AttachmentStore;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * מי שהפונה כיתב הוא חלק מהשיחה.
 *
 * כשלקוח כותב לתמיכה ומכתב את רואה החשבון שלו, את השותף או עמית — האדם הזה הוא
 * חלק מהשיחה מהשורה הראשונה. עד עכשיו נקראה רק כותרת ה-From, ולכן המכותב לא היה
 * קיים בשום מקום: הצוות לא ראה שהוא על השרשור, וכל תשובה שנשלחה השאירה אותו
 * בחוץ. מבחינתו ההתכתבות פשוט נפסקה — ומי שכיתב אותו בכוונה לא ידע שהשמטנו אותו.
 */
class TicketCopiedWatchersTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $extra */
    private function ingest(array $extra = [], string $from = 'yossi@customer.co.il'): Ticket
    {
        Mail::fake();

        $event = WebhookEvent::create([
            'source' => 'email',
            'external_id' => 'msg-'.uniqid(),
            'event_type' => 'inbound',
            'payload' => [
                'From' => $from,
                'Subject' => 'האתר לא עולה',
                'TextBody' => 'שלום, האתר שלנו לא נטען.',
                'MessageID' => 'mid-'.uniqid(),
                ...$extra,
            ],
        ]);

        (new IngestEmailMessageJob($event->id))->handle(
            app(TicketIntake::class),
            app(AttachmentStore::class),
            app(AgentReply::class),
        );

        return Ticket::latest('id')->firstOrFail();
    }

    /** מכותב במייל נרשם על הפנייה ונראה. */
    public function test_a_copied_address_becomes_a_visible_participant(): void
    {
        Customer::factory()->create(['email' => 'yossi@customer.co.il']);

        $ticket = $this->ingest(['Cc' => 'accountant@external.co.il']);

        $this->assertSame(['accountant@external.co.il'], $ticket->watchers()->pluck('email')->all());
    }

    /** והשם שהגיע בכותרת נשמר — כתובת עירומה היא שם גרוע. */
    public function test_the_copied_name_is_kept_when_the_header_carries_one(): void
    {
        $ticket = $this->ingest([
            'CcFull' => [['Email' => 'accountant@external.co.il', 'Name' => 'רו״ח דנה']],
        ]);

        $this->assertSame('רו״ח דנה', $ticket->watchers()->sole()->name);
    }

    /**
     * וכך הוא גם מקבל כל תשובה.
     *
     * זו הנקודה: הרישום אינו תיעוד בלבד — הוא מה שמחזיר את המכותב לשיחה שממנה
     * הושמט.
     */
    public function test_the_copied_person_is_on_every_reply(): void
    {
        Customer::factory()->create(['email' => 'office@customer.co.il']);

        $ticket = $this->ingest(['Cc' => 'accountant@external.co.il']);

        $this->assertContains('accountant@external.co.il', $ticket->fresh()->replyCcEmails());
    }

    /**
     * הכתובת שלנו לעולם אינה נרשמת — עותק לעצמנו הוא לולאה.
     */
    public function test_our_own_address_is_never_added(): void
    {
        config(['mail.from.address' => 'support@multidigital.co.il']);

        $ticket = $this->ingest(['Cc' => 'support@multidigital.co.il, accountant@external.co.il']);

        $this->assertSame(['accountant@external.co.il'], $ticket->watchers()->pluck('email')->all());
    }

    /**
     * וגם לא כתובת התמיכה שלנו — זו הכתובת שהמייל הנכנס מגיע אליה.
     *
     * רישום שלה היה מכתב את תיבת התמיכה בכל תשובה, כל תשובה הייתה נקלטת כהודעה
     * נכנסת חדשה על אותה פנייה, והשרשור היה מדבר עם עצמו כל עוד מישהו נותן לו.
     */
    public function test_our_support_inbox_is_never_added(): void
    {
        config(['billing.email.support_address' => 'support@multi.digital']);

        $ticket = $this->ingest(['Cc' => 'support@multi.digital, accountant@external.co.il']);

        $this->assertSame(['accountant@external.co.il'], $ticket->watchers()->pluck('email')->all());
    }

    /** וגם לא איש צוות — הוא כבר מקבל את התראת הצוות, והיה מקבל הכל פעמיים. */
    public function test_a_team_member_is_never_added(): void
    {
        User::factory()->create(['email' => 'agent@multidigital.co.il']);

        $ticket = $this->ingest(['Cc' => 'agent@multidigital.co.il']);

        $this->assertSame(0, $ticket->watchers()->count());
    }

    /** מכותב שנוסף באמצע שרשור נקלט גם הוא — שם ההשמטה כואבת במיוחד. */
    public function test_somebody_copied_mid_thread_is_picked_up(): void
    {
        Customer::factory()->create(['email' => 'yossi@customer.co.il']);

        $ticket = $this->ingest(['Cc' => 'accountant@external.co.il']);

        $this->ingest([
            'Subject' => 'Re: האתר לא עולה '.$ticket->emailTag(),
            'Cc' => 'accountant@external.co.il, lawyer@external.co.il',
        ]);

        $this->assertEqualsCanonicalizing(
            ['accountant@external.co.il', 'lawyer@external.co.il'],
            $ticket->fresh()->watchers()->pluck('email')->all(),
        );
    }

    /** ואותו מכותב בכל הודעה אינו נרשם פעמיים. */
    public function test_the_same_copied_address_is_not_duplicated(): void
    {
        Customer::factory()->create(['email' => 'yossi@customer.co.il']);

        $ticket = $this->ingest(['Cc' => 'accountant@external.co.il']);
        $this->ingest([
            'Subject' => 'Re: האתר לא עולה '.$ticket->emailTag(),
            'Cc' => 'accountant@external.co.il',
        ]);

        $this->assertSame(1, $ticket->fresh()->watchers()->count());
    }

    /**
     * התקרה סופרת רק מי שנוסף עכשיו, לא את מי שכבר רשום.
     *
     * כל הודעה בשרשור מכתבת מחדש את מי שכבר עליו. ספירה חוזרת שלהם הייתה מכלה
     * את כל המכסה על שמות שכבר יש לנו — ודווקא האדם היחיד שההודעה הזו באמת
     * הוסיפה, שמופיע אחרון, היה נופל.
     */
    public function test_the_cap_counts_only_newly_added_people(): void
    {
        $existing = collect(range(1, 10))->map(fn (int $i): string => "person{$i}@external.co.il");

        $ticket = $this->ingest(['Cc' => $existing->implode(', ')]);
        $this->assertSame(10, $ticket->watchers()->count());

        $this->ingest([
            'Subject' => 'Re: האתר לא עולה '.$ticket->emailTag(),
            'Cc' => $existing->push('lawyer@external.co.il')->implode(', '),
        ]);

        $this->assertContains('lawyer@external.co.il', $ticket->fresh()->watchers()->pluck('email')->all());
    }

    /**
     * רשימת תפוצה ארוכה נחתכת — ובמפורש.
     *
     * בלי תקרה, הודעה שנשלחה לרשימת תפוצה הייתה הופכת כל תשובה עתידית לדיוור
     * שאיש לא בחר לשלוח.
     */
    public function test_a_long_distribution_list_is_capped(): void
    {
        $many = collect(range(1, 15))->map(fn (int $i): string => "person{$i}@external.co.il")->implode(', ');

        $ticket = $this->ingest(['Cc' => $many]);

        $this->assertSame(10, $ticket->watchers()->count());
    }
}
