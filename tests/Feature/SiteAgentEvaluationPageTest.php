<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteAgent;
use App\Filament\Pages\SiteAgentEvaluation;
use App\Models\User;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SiteAgentEvaluationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.api_key' => 'private-evaluation-api-key',
        ]);
    }

    public function test_admin_opening_the_page_sees_cost_and_scope_without_starting_a_run_or_exposing_credentials(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnNull();
        $runs->shouldNotReceive('start');

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSuccessful()
            ->assertSeeText('הפעלת 352 התרחישים')
            ->assertSeeText('752 תרחישי שיחה עם הבוט')
            ->assertSeeText('התרחישים שכבר עברו בדוח שסופק הוסרו מהרצות חדשות')
            ->assertDontSeeText('כל 800 התרחישים')
            ->assertSeeText('google')
            ->assertSeeText('gemini-3.1-flash-lite')
            ->assertSeeText('הריצה צורכת שימוש בתשלום אצל ספק ה־AI')
            ->assertSeeText('מטמון Gemini מופעל בהרצות חדשות')
            ->assertSeeText('היסטוריית השיחות ונתוני האתר המדומה אינם נשמרים במטמון המשותף')
            ->assertSeeText('מטמון אינו מבטל את העלות')
            ->assertSeeText('אם המטמון אינו זמין, ההרצה נעצרת')
            ->assertSeeText('אינה משנה אתרי לקוחות')
            ->assertSeeText('אינה שולחת הודעות ללקוחות ואינה מחייבת אותם')
            ->assertSeeText('אין כאן סקירה אנושית של איכות כל תשובה')
            ->assertSeeText('טרם הופעלה בדיקה')
            ->assertDontSee('private-evaluation-api-key');
    }

    public function test_explicit_start_only_delegates_to_the_queue_service_as_the_current_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $summary = null;
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnUsing(function () use (&$summary) {
            return $summary;
        });
        $runs->shouldReceive('start')->once()->with($admin->id, 'original')->andReturnUsing(function () use (&$summary) {
            $summary = $this->summary();

            return $summary['id'];
        });

        Livewire::test(SiteAgentEvaluation::class)
            ->call('start')
            ->assertHasNoErrors()
            ->assertNotified('הבדיקה נוספה לתור')
            ->assertSeeText('ממתינה בתור');

        $this->assertSame('queued', $summary['status']);
        Http::assertNothingSent();
    }

    #[DataProvider('selectedSuites')]
    public function test_selected_suite_controls_the_button_count_and_queued_manifest(string $suite, int $count): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnNull();
        $runs->shouldReceive('start')->once()->with($admin->id, $suite)->andReturn((string) Str::uuid());

        Livewire::test(SiteAgentEvaluation::class)
            ->set('suite', $suite)
            ->assertSeeText('הפעלת '.$count.' התרחישים')
            ->call('start')
            ->assertHasNoErrors()
            ->assertNotified('הבדיקה נוספה לתור');
        Http::assertNothingSent();
    }

    public static function selectedSuites(): array
    {
        return [['original', 352], ['round2', 400], ['all', 752]];
    }

    public function test_invalid_livewire_suite_never_reaches_the_queue_service(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnNull();
        $runs->shouldNotReceive('start');

        Livewire::test(SiteAgentEvaluation::class)
            ->set('suite', '../untrusted')
            ->call('start')
            ->assertHasErrors(['suite']);
        Http::assertNothingSent();
    }

    #[DataProvider('missingAiConfiguration')]
    public function test_unconfigured_ai_cannot_start_even_by_calling_the_action_directly(array $override): void
    {
        $this->actingAs(User::factory()->create());
        config($override);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnNull();
        $runs->shouldNotReceive('start');

        Livewire::test(SiteAgentEvaluation::class)
            ->call('start')
            ->assertHasErrors(['evaluation'])
            ->assertSeeText('כדי להתחיל יש להפעיל את סוכן ה־AI');

        Http::assertNothingSent();
    }

    public static function missingAiConfiguration(): array
    {
        return [
            'disabled' => [['billing.ai.enabled' => false]],
            'missing key' => [['billing.ai.api_key' => '']],
            'missing provider' => [['billing.ai.provider' => '']],
            'missing model' => [['billing.ai.model' => '']],
        ];
    }

    public function test_polling_reads_real_progress_and_keeps_failed_and_blocked_cases_visible(): void
    {
        $this->actingAs(User::factory()->create());
        $summary = $this->summary(['status' => 'running', 'completed' => 3, 'passed' => 1, 'failed' => 1, 'blocked' => 1]);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnUsing(function () use (&$summary) {
            return $summary;
        });

        $page = Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeText('הבדיקה מתבצעת')
            ->assertSeeText('הושלמו 3 מתוך 400')
            ->assertSeeText('נכשלו בבדיקה')
            ->assertSeeText('נחסמו ולא נבדקו במלואם')
            ->assertSeeHtml('wire:poll.5s="$refresh"');

        $summary = [...$summary, 'status' => 'completed', 'completed' => 400, 'passed' => 396, 'failed' => 3, 'blocked' => 1];
        $page->call('$refresh')
            ->assertSeeText('הושלמו 400 מתוך 400')
            ->assertSeeText('הסיום כשלעצמו אינו מעיד שכל התרחישים עברו')
            ->assertSeeText('396')
            ->assertDontSeeHtml('wire:poll.5s="$refresh"');
    }

    public function test_cancel_requests_cooperative_stop_for_the_named_run(): void
    {
        $this->actingAs(User::factory()->create());
        $summary = $this->summary(['status' => 'running']);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnUsing(function () use (&$summary) {
            return $summary;
        });
        $runs->shouldReceive('cancel')->once()->with($summary['id'])->andReturnUsing(function () use (&$summary): void {
            $summary['status'] = 'cancel_requested';
        });

        Livewire::test(SiteAgentEvaluation::class)
            ->call('cancel', $summary['id'])
            ->assertNotified('בקשת העצירה נרשמה')
            ->assertSeeText('ממתינה לסיום התרחיש הנוכחי ולעצירה')
            ->assertDontSeeText('עצירה אחרי התרחיש הנוכחי');
    }

    public function test_provider_confirmed_cache_usage_is_shown_as_tokens_without_claiming_money_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($this->summary([
            'cache_enabled' => true,
            'cache_status' => ['state' => 'active', 'name' => 'cachedContents/private-handle', 'expires_at' => '2026-10-09T12:00:00Z'],
            'input_tokens' => 12500,
            'cached_input_tokens' => 10000,
            'uncached_input_tokens' => 2500,
            'cache_hit_requests' => 7,
            'cache_management_requests' => 2,
            'cache_management_responses' => 1,
        ]));

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeText('מטמון תקף נמצא בבדיקה האחרונה')
            ->assertSeeText('טוקני קלט מהמטמון שאושרו על ידי הספק')
            ->assertSeeText('12,500')
            ->assertSeeText('10,000')
            ->assertSeeText('2,500')
            ->assertSeeText('80.0%')
            ->assertSeeText('קריאות לניהול המטמון / תשובות שהתקבלו')
            ->assertSeeText('2 / 1')
            ->assertSeeText('אינו אחוז החיסכון הכספי')
            ->assertDontSee('cachedContents/private-handle');
    }

    public function test_legacy_report_without_cache_metrics_is_not_presented_as_zero_cache_usage(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($this->summary([
            'status' => 'completed', 'input_tokens' => 23000, 'total' => 800,
            'completed' => 50, 'passed' => 48, 'failed' => 2,
        ]));

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeText('לא נמדד בריצה זו')
            ->assertSeeText('הושלמו 50 מתוך 800')
            ->assertDontSeeText('חלק המטמון מתוך טוקני הקלט')
            ->assertDontSeeText('0.0%')
            ->assertDontSeeText('טוקני קלט מהמטמון שאושרו על ידי הספק');
    }

    public function test_new_run_with_zero_input_does_not_divide_by_zero_or_claim_a_cache_hit(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($this->summary([
            'cache_enabled' => true,
            'cache_status' => ['state' => 'idle'],
            'input_tokens' => 0, 'cached_input_tokens' => 0, 'uncached_input_tokens' => 0,
            'cache_hit_requests' => 0, 'cache_management_requests' => 0, 'cache_management_responses' => 0,
        ]));

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSuccessful()
            ->assertSeeText('טרם התקבל מצב מטמון בריצה זו')
            ->assertSeeText('טרם נמדדו טוקני קלט')
            ->assertDontSeeText('חלק המטמון מתוך טוקני הקלט')
            ->assertDontSeeText('מטמון תקף נמצא בבדיקה האחרונה');
    }

    public function test_cache_failure_reason_is_enum_only_and_never_exposes_provider_metadata(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($this->summary([
            'cache_enabled' => true,
            'cache_status' => ['state' => 'fallback', 'reason' => 'cachedContents/private-resource'],
            'input_tokens' => 0, 'cached_input_tokens' => 0, 'uncached_input_tokens' => 0,
        ]));

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeText('המטמון לא היה זמין בבדיקה האחרונה')
            ->assertDontSee('cachedContents/private-resource');
    }

    public function test_other_provider_does_not_claim_shared_gemini_cache_is_enabled(): void
    {
        $this->actingAs(User::factory()->create());
        config(['billing.ai.provider' => 'openai', 'billing.ai.model' => 'test-model']);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturnNull();

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeText('אצל הספק שנבחר אין כאן הבטחה לשימוש במטמון')
            ->assertDontSeeText('מטמון Gemini מופעל בהרצות חדשות');
    }

    public function test_admin_download_uses_a_direct_http_link_instead_of_buffering_json_in_livewire(): void
    {
        $this->actingAs(User::factory()->create());
        $summary = $this->summary(['status' => 'completed', 'completed' => 400, 'passed' => 399, 'failed' => 1]);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($summary);
        $runs->shouldNotReceive('report', 'streamReport');
        $url = route('site-agent.evaluation.download', ['run' => $summary['id']]);

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeHtml('href="'.$url.'"')
            ->assertDontSeeHtml('wire:click="download(')
            ->call('download', $summary['id'])
            ->assertRedirect($url);
    }

    public function test_model_and_failure_text_are_escaped_in_the_admin_page(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = '<img src=x onerror=alert(1)>';
        config(['billing.ai.model' => $payload]);
        $runs = $this->runs();
        $runs->shouldReceive('latest')->andReturn($this->summary(['status' => 'failed', 'model' => $payload, 'reason' => $payload]));

        Livewire::test(SiteAgentEvaluation::class)
            ->assertSeeHtml(e($payload))
            ->assertDontSeeHtml($payload)
            ->assertSeeText('הריצה נעצרה עקב תקלה');
    }

    public function test_non_admin_cannot_mount_or_invoke_any_public_action(): void
    {
        $this->actingAs(User::factory()->agent()->create());
        $runs = $this->runs();
        $runs->shouldNotReceive('latest', 'start', 'cancel', 'report');

        Livewire::test(SiteAgentEvaluation::class)->assertForbidden();
        foreach (['start' => [], 'cancel' => [(string) Str::uuid()], 'download' => [(string) Str::uuid()]] as $method => $arguments) {
            try {
                app(SiteAgentEvaluation::class)->{$method}(...$arguments);
                $this->fail("Unauthorized {$method} must be rejected before calling the service.");
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_guest_cannot_download_a_report(): void
    {
        $runs = $this->runs();
        $runs->shouldNotReceive('report');

        try {
            app(SiteAgentEvaluation::class)->download((string) Str::uuid());
            $this->fail('An unauthenticated download must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_cancel_and_download_reject_paths_instead_of_treating_them_as_run_ids(): void
    {
        $this->actingAs(User::factory()->create());
        $runs = $this->runs();
        $runs->shouldNotReceive('cancel', 'report');

        foreach (['cancel', 'download'] as $method) {
            try {
                app(SiteAgentEvaluation::class)->{$method}('../../.env');
                $this->fail('Only a UUID can identify a report.');
            } catch (ValidationException $exception) {
                $this->assertSame(['evaluation' => ['מזהה הבדיקה אינו תקין.']], $exception->errors());
            }
        }
    }

    public function test_bot_settings_link_to_the_evaluation_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageSiteAgent::class)
            ->assertSeeText('בדיקות הבוט')
            ->assertSeeHtml('href="'.SiteAgentEvaluation::getUrl().'"');
    }

    private function runs(): MockInterface
    {
        $runs = Mockery::mock(EvaluationRuns::class);
        $this->app->instance(EvaluationRuns::class, $runs);

        return $runs;
    }

    private function summary(array $overrides = []): array
    {
        return [...[
            'id' => (string) Str::uuid(),
            'status' => 'queued',
            'provider' => 'google',
            'model' => 'gemini-3.1-flash-lite',
            'total' => 400,
            'completed' => 0,
            'passed' => 0,
            'failed' => 0,
            'blocked' => 0,
            'created_at' => '2026-10-09T10:00:00+00:00',
            'updated_at' => '2026-10-09T10:00:00+00:00',
            'finished_at' => null,
            'current_case' => null,
            'reason' => null,
        ], ...$overrides];
    }
}
