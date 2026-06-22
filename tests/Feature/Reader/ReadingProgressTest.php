<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingProgressTest extends TestCase
{
    use RefreshDatabase;

    private function work(int $pageCount = 10): Work
    {
        return Work::factory()->for(Mangaka::factory())->create(['page_count' => $pageCount]);
    }

    public function test_creates_progress_row_with_timestamps(): void
    {
        $work = $this->work(10);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 3])
            ->assertOk()
            ->assertJson(['current_page' => 3, 'is_completed' => false]);

        $progress = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertSame(3, $progress->current_page);
        $this->assertFalse($progress->is_completed);
        $this->assertNotNull($progress->started_at);
        $this->assertNotNull($progress->last_read_at);
        $this->assertNull($progress->completed_at);
    }

    public function test_reaching_last_page_marks_completed(): void
    {
        $work = $this->work(5);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 5])
            ->assertOk()
            ->assertJson(['current_page' => 5, 'is_completed' => true]);

        $progress = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertTrue($progress->is_completed);
        $this->assertNotNull($progress->completed_at);
    }

    public function test_updates_existing_row_and_preserves_started_at(): void
    {
        $work = $this->work(10);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 2])->assertOk();
        $first = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $startedAt = $first->started_at;

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 6])->assertOk();

        $this->assertSame(1, ReadingProgress::where('work_id', $work->id)->count()); // upsert, not duplicate
        $second = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertSame(6, $second->current_page);
        $this->assertEquals($startedAt, $second->started_at); // started_at unchanged
    }

    public function test_rejects_out_of_range_page(): void
    {
        $work = $this->work(5);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 6])->assertStatus(422);
        $this->postJson("/work/{$work->id}/progress", ['current_page' => 0])->assertStatus(422);
        $this->postJson("/work/{$work->id}/progress", [])->assertStatus(422);
    }

    public function test_preserves_first_completed_at_on_re_completion(): void
    {
        $work = $this->work(5);

        $this->postJson("/work/{$work->id}/progress", ['current_page' => 5])->assertOk();
        $first = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $firstCompletedAt = $first->completed_at;

        // Un-complete, then re-complete; the original completed_at must be retained.
        // 一旦未完了に戻し再完了。最初のcompleted_atを保持すること。
        $this->postJson("/work/{$work->id}/progress", ['current_page' => 3])->assertOk();
        $this->postJson("/work/{$work->id}/progress", ['current_page' => 5])->assertOk();

        $second = ReadingProgress::where('work_id', $work->id)->firstOrFail();
        $this->assertEquals($firstCompletedAt, $second->completed_at);
    }
}
