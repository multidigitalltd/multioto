<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageMail;
use App\Mail\NotificationMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\TeamNotifier;
use App\Support\EmailList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EmailListTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_extracts_valid_addresses_and_dedupes(): void
    {
        $this->assertSame(
            ['a@x.co.il', 'b@y.com'],
            EmailList::parse('a@x.co.il, b@y.com ; a@x.co.il'),
        );
        $this->assertSame([], EmailList::parse(''));
        $this->assertSame(['one@x.com'], EmailList::parse("one@x.com\nnot-an-email"));
    }

    public function test_invalid_returns_only_the_bad_entries(): void
    {
        $this->assertSame(['nope', 'also bad'], EmailList::invalid('good@x.com, nope, also bad'));
        $this->assertSame([], EmailList::invalid('a@x.com, b@y.com'));
    }

    public function test_team_notifier_emails_every_recipient(): void
    {
        config(['billing.notifications.team_email' => 'team@multidigital.co.il, riki@m-d.co.il']);
        Mail::fake();

        app(TeamNotifier::class)->alert('כותרת', 'גוף');

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('team@multidigital.co.il') && $mail->hasTo('riki@m-d.co.il'));
    }

    public function test_the_settings_field_accepts_a_comma_separated_list(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageMail::class)
            ->set('data.notifications.team_email', 'team@multidigital.co.il, riki@m-d.co.il')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('team@multidigital.co.il, riki@m-d.co.il', Setting::map()['notifications.team_email'] ?? null);
    }

    public function test_the_settings_field_rejects_an_invalid_address_in_the_list(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageMail::class)
            ->set('data.notifications.team_email', 'team@multidigital.co.il, not-an-email')
            ->call('save')
            ->assertHasErrors('data.notifications.team_email');
    }
}
