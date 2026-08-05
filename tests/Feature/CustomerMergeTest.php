<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Customers\CustomerMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * מיזוג כרטיס לקוח כפול.
 *
 * אותו עסק מגיע אלינו פעמיים ונפתחים שני כרטיסים, כל אחד עם חצי מההיסטוריה.
 * המיזוג הוא איך ששני החצאים חוזרים להיות לקוח אחד: הכול עובר, שום סכום לא
 * מחושב מחדש, והכרטיס הכפול נמחק רק אחרי שווידאנו שלא נשאר בו כלום.
 */
class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    private function merger(): CustomerMerger
    {
        return app(CustomerMerger::class);
    }

    /** כל מה שתלוי בכרטיס הכפול עובר לשורד, והכפול נעלם. */
    public function test_it_moves_everything_and_removes_the_duplicate(): void
    {
        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();

        $site = Site::factory()->create(['customer_id' => $duplicate->id]);
        $subscription = Subscription::factory()->create(['customer_id' => $duplicate->id]);
        $token = PaymentToken::factory()->create(['customer_id' => $duplicate->id]);
        $ticket = Ticket::create([
            'customer_id' => $duplicate->id, 'channel' => TicketChannel::Email,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
        ]);

        $moved = $this->merger()->merge($duplicate, $survivor);

        $this->assertSame($survivor->id, $site->fresh()->customer_id);
        $this->assertSame($survivor->id, $subscription->fresh()->customer_id);
        $this->assertSame($survivor->id, $token->fresh()->customer_id);
        $this->assertSame($survivor->id, $ticket->fresh()->customer_id);
        $this->assertModelMissing($duplicate);
        $this->assertSame(1, $moved['sites']);
    }

    /**
     * ההגנה האמיתית: הטבלאות נגזרות מהסכימה, ולכן טבלה שתיווצר בעתיד מכוסה
     * ביום שהיא נוצרת. מיזוג שמשאיר שורות מאחור הוא כשל שאי אפשר לזהות מהכרטיס
     * ששרד — הוא פשוט נראה תקין וחסר.
     */
    public function test_no_table_that_points_at_a_customer_is_left_behind(): void
    {
        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();

        // Rows in the corners a hand-written list forgets — the outbound log, a
        // task, a contact — and not only in the obvious sites/subscriptions.
        Site::factory()->create(['customer_id' => $duplicate->id]);
        Contact::factory()->create(['customer_id' => $duplicate->id]);
        Task::factory()->create(['customer_id' => $duplicate->id]);
        NotificationLog::factory()->create(['customer_id' => $duplicate->id]);
        DB::table('pending_actions')->insert([
            'customer_id' => $duplicate->id, 'type' => 'ticket_reply', 'status' => 'pending',
            'summary' => 'בדיקה', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->merger()->merge($duplicate, $survivor);

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! Schema::hasColumn($table, 'customer_id')) {
                continue;
            }

            $this->assertFalse(
                DB::table($table)->where('customer_id', $duplicate->id)->exists(),
                "נשארו רשומות בטבלה {$table} אחרי המיזוג.",
            );
        }
    }

    /** הכרטיס ששורד קובע: מיזוג משלים חורים ולא דורס ערך שמישהו הקליד. */
    public function test_it_fills_blanks_without_overwriting_the_survivor(): void
    {
        $survivor = Customer::factory()->create(['email' => 'real@example.com', 'phone' => null, 'address' => null]);
        $duplicate = Customer::factory()->create(['email' => 'old@example.com', 'phone' => '+972501234567', 'address' => 'הרצל 1']);

        $this->merger()->merge($duplicate, $survivor);
        $survivor->refresh();

        $this->assertSame('real@example.com', $survivor->email);
        $this->assertSame('+972501234567', $survivor->phone);
        $this->assertSame('הרצל 1', $survivor->address);
    }

    /**
     * מי שביקש להפסיק לקבל דיוור אמר את זה על עצמו, לא על שורה בטבלה. מיזוג
     * שמחזיר אותו לרשימה הוא המערכת שמחליטה שהיא יודעת טוב יותר.
     */
    public function test_an_opt_out_survives_the_merge(): void
    {
        $survivor = Customer::factory()->create(['marketing_opt_out_at' => null]);
        $duplicate = Customer::factory()->create(['marketing_opt_out_at' => now(), 'marketing_opt_out_channel' => 'email']);

        $this->merger()->merge($duplicate, $survivor);

        $this->assertTrue($survivor->refresh()->hasOptedOutOfMarketing());
    }

    /** הסכם חתום עובר כיחידה אחת — תאריך בלי חתימה אינו הסכם. */
    public function test_a_signed_agreement_moves_as_one_piece(): void
    {
        $survivor = Customer::factory()->create(['terms_accepted_at' => null]);
        $duplicate = Customer::factory()->create([
            'terms_accepted_at' => now()->subMonth(),
            'signature_path' => 'signatures/a.png',
            'signed_pdf_path' => 'agreements/a.pdf',
        ]);

        $this->merger()->merge($duplicate, $survivor);
        $survivor->refresh();

        $this->assertNotNull($survivor->terms_accepted_at);
        $this->assertSame('signatures/a.png', $survivor->signature_path);
        $this->assertSame('agreements/a.pdf', $survivor->signed_pdf_path);
    }

    /** כרטיס האשראי עובר, ואם לשורד לא היה ברירת מחדל — הוא מקבל אחת. */
    public function test_the_survivor_inherits_a_default_card_when_it_had_none(): void
    {
        $survivor = Customer::factory()->create(['default_token_id' => null]);
        $duplicate = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $duplicate->id]);
        $duplicate->update(['default_token_id' => $token->id]);

        $this->merger()->merge($duplicate, $survivor);

        $this->assertSame($token->id, $survivor->refresh()->default_token_id);
    }

    /** לשורד יש כבר איש קשר ראשי — הנכנס אינו מדיח אותו. */
    public function test_the_surviving_primary_contact_keeps_the_title(): void
    {
        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();
        $ours = Contact::factory()->create(['customer_id' => $survivor->id, 'is_primary' => true]);
        $theirs = Contact::factory()->create(['customer_id' => $duplicate->id, 'is_primary' => true]);

        $this->merger()->merge($duplicate, $survivor);

        $this->assertTrue($ours->fresh()->is_primary);
        $this->assertFalse($theirs->fresh()->is_primary);
    }

    /** ולשורד בלי ראשי — הנכנס ממלא את המקום הריק במקום להיות מודח לחינם. */
    public function test_an_incoming_primary_fills_an_empty_slot(): void
    {
        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();
        Contact::factory()->create(['customer_id' => $survivor->id, 'is_primary' => false]);
        $theirs = Contact::factory()->create(['customer_id' => $duplicate->id, 'is_primary' => true]);

        $this->merger()->merge($duplicate, $survivor);

        $this->assertTrue($theirs->fresh()->is_primary);
    }

    /** מיזוג כרטיס לתוך עצמו אינו מיזוג — ובעיקר, הוא היה מוחק את הכרטיס. */
    public function test_it_refuses_to_merge_a_card_into_itself(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->merger()->merge($customer, $customer);
    }

    /** המיזוג נרשם ביומן הפעולות — מי מיזג את מי, ומה עבר. */
    public function test_it_is_written_to_the_audit_log(): void
    {
        $this->actingAs(User::factory()->create());
        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create(['name' => 'עסק כפול']);
        Site::factory()->create(['customer_id' => $duplicate->id]);

        $this->merger()->merge($duplicate, $survivor);

        $entry = AuditLog::query()->where('description', 'like', 'מיזוג כרטיס לקוח%')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('עסק כפול', $entry->description);
        $this->assertSame(1, $entry->changes['moved']['sites']);
    }

    /** ההערות של הכרטיס הכפול לא נזרקות — הן נספחות לכרטיס ששרד. */
    public function test_notes_from_the_duplicate_are_appended(): void
    {
        $survivor = Customer::factory()->create(['notes' => 'לקוח ותיק']);
        $duplicate = Customer::factory()->create(['notes' => 'ביקש חשבונית לחברה']);

        $this->merger()->merge($duplicate, $survivor);

        $notes = (string) $survivor->refresh()->notes;
        $this->assertStringContainsString('לקוח ותיק', $notes);
        $this->assertStringContainsString('ביקש חשבונית לחברה', $notes);
    }

    /** מנהל רואה את הפעולה בכרטיס הלקוח, ואיש צוות רגיל לא. */
    public function test_only_an_admin_sees_the_merge_action(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create());
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertActionVisible('mergeCustomer');

        $this->actingAs(User::factory()->agent()->create());
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertActionHidden('mergeCustomer');
    }

    /** מיזוג מהמסך עצמו: הכפול נבחר, מאושר, ונעלם. */
    public function test_the_screen_merges_the_selected_duplicate(): void
    {
        $this->actingAs(User::factory()->create());

        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();
        $site = Site::factory()->create(['customer_id' => $duplicate->id]);

        Livewire::test(ViewCustomer::class, ['record' => $survivor->getRouteKey()])
            ->callAction('mergeCustomer', ['duplicate_id' => $duplicate->id, 'understood' => true])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($duplicate);
        $this->assertSame($survivor->id, $site->fresh()->customer_id);
    }

    /** בלי סימון ההבנה שהמחיקה סופית, המסך לא ממזג. */
    public function test_the_screen_will_not_merge_without_the_acknowledgement(): void
    {
        $this->actingAs(User::factory()->create());

        $survivor = Customer::factory()->create();
        $duplicate = Customer::factory()->create();

        Livewire::test(ViewCustomer::class, ['record' => $survivor->getRouteKey()])
            ->callAction('mergeCustomer', ['duplicate_id' => $duplicate->id, 'understood' => false])
            ->assertHasActionErrors(['understood']);

        $this->assertModelExists($duplicate);
    }
}
