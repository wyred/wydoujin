<?php

namespace Tests\Feature\Maintenance;

use App\Jobs\ScanLibrary;
use App\Models\Mangaka;
use App\Models\Scan;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_maintenance_page_renders_nav_history_and_missing_works(): void
    {
        $m = Mangaka::factory()->create(['name' => 'GhostMangaka']);
        Work::factory()->for($m)->create(['title' => 'MissingWork', 'sort_title' => 'MissingWork', 'is_missing' => true]);
        Work::factory()->for($m)->create(['title' => 'PresentWork', 'sort_title' => 'PresentWork', 'is_missing' => false]);
        Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 2], 'started_at' => now(), 'finished_at' => now()]);

        $this->get('/maintenance')->assertOk()
            ->assertSee('href="/maintenance"', false) // nav link
            ->assertSee('MissingWork')                // server-rendered missing list
            ->assertDontSee('PresentWork')            // not-missing excluded
            ->assertSee('Missing works')              // section heading
            ->assertSee('completed');                 // latest scan embedded for Alpine
    }

    public function test_empty_states(): void
    {
        $this->get('/maintenance')->assertOk()
            ->assertSee('No scans yet')
            ->assertSee('No missing works');
    }

    public function test_scan_creates_a_queued_row_and_dispatches_with_its_id(): void
    {
        Queue::fake();

        $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.status', 'queued');

        $scan = Scan::firstOrFail();
        $this->assertSame('queued', $scan->status);
        Queue::assertPushed(ScanLibrary::class, fn (ScanLibrary $job) => $job->scanId === $scan->id && $job->triggeredBy === 'manual');
    }

    public function test_scan_does_not_double_dispatch_when_one_is_active(): void
    {
        Queue::fake();
        $active = Scan::create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

        $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.id', $active->id);

        $this->assertSame(1, Scan::count()); // no new row
        Queue::assertNothingPushed();
    }

    public function test_scan_does_not_double_dispatch_when_a_queued_scan_exists(): void
    {
        Queue::fake();
        $queued = Scan::create(['status' => 'queued', 'triggered_by' => 'manual']);

        $this->postJson('/scan')->assertStatus(202)->assertJsonPath('scan.id', $queued->id);

        $this->assertSame(1, Scan::count());
        Queue::assertNothingPushed();
    }

    public function test_status_returns_latest_scan_or_null(): void
    {
        $this->getJson('/maintenance/status')->assertOk()->assertJsonPath('scan', null);

        Scan::create(['status' => 'completed', 'triggered_by' => 'manual', 'stats' => ['added' => 3], 'started_at' => now(), 'finished_at' => now()]);

        $this->getJson('/maintenance/status')->assertOk()
            ->assertJsonPath('scan.status', 'completed')
            ->assertJsonPath('scan.stats.added', 3);
    }
}
