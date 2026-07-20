<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemUpdates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ChangelogDiffTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_outputs_only_releases_new_to_the_incoming_build(): void
    {
        // The running build knows only these two versions.
        config(['changelog.releases' => [
            ['version' => '1.1.0', 'title' => 'קיים', 'highlights' => ['ישן']],
            ['version' => '1.0.0', 'title' => 'ישן יותר', 'highlights' => []],
        ]]);

        // The incoming build adds 1.2.0 on top of the shared 1.1.0 — as JSON
        // (the changelog is data, never executed from an unreviewed branch).
        $incoming = tempnam(sys_get_temp_dir(), 'chlog').'.json';
        file_put_contents($incoming, json_encode([
            ['version' => '1.2.0', 'date' => '2026-07-20', 'title' => 'חדש ומבריק', 'highlights' => ['יתרון א', 'יתרון ב']],
            ['version' => '1.1.0', 'title' => 'קיים', 'highlights' => ['ישן']],
        ], JSON_UNESCAPED_UNICODE));

        // Capture the JSON output and assert it holds only the new release.
        $output = trim($this->artisanOutput('app:changelog-diff', ['incoming' => $incoming]));
        $decoded = json_decode($output, true);

        @unlink($incoming);

        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('1.2.0', $decoded[0]['version']);
        $this->assertSame('חדש ומבריק', $decoded[0]['title']);
        $this->assertSame(['יתרון א', 'יתרון ב'], $decoded[0]['highlights']);
    }

    public function test_a_missing_incoming_file_yields_an_empty_list(): void
    {
        $this->artisan('app:changelog-diff', ['incoming' => '/no/such/file.json'])
            ->expectsOutput('[]')
            ->assertSuccessful();
    }

    public function test_a_non_json_payload_is_not_executed(): void
    {
        // An incoming file that is PHP (or anything non-JSON) must be treated as
        // data — invalid JSON yields an empty list, never code execution.
        $marker = storage_path('app/should-not-exist-'.uniqid().'.txt');
        $incoming = tempnam(sys_get_temp_dir(), 'evil').'.json';
        file_put_contents($incoming, "<?php file_put_contents('{$marker}', 'pwned'); return [];");

        $this->artisan('app:changelog-diff', ['incoming' => $incoming])
            ->expectsOutput('[]')
            ->assertSuccessful();

        @unlink($incoming);
        $this->assertFileDoesNotExist($marker); // the payload never ran
    }

    public function test_the_updates_page_shows_the_pending_highlights(): void
    {
        $this->actingAs(User::factory()->create()); // factory default = admin

        Livewire::test(SystemUpdates::class)
            ->set('available', [
                'behind' => 2,
                'short' => 'e8e0523',
                'releases' => [
                    ['version' => '1.19.0', 'title' => 'תכונה חדשה', 'highlights' => ['שיפור חשוב מאוד']],
                ],
            ])
            ->assertSee('מה מחכה בעדכון הזה')
            ->assertSee('תכונה חדשה')
            ->assertSee('שיפור חשוב מאוד');
    }

    /** Run a console command and return its captured stdout. */
    private function artisanOutput(string $command, array $args = []): string
    {
        $exit = Artisan::call($command, $args);
        $this->assertSame(0, $exit);

        return Artisan::output();
    }
}
