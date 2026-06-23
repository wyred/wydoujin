<?php

namespace Tests\Feature\Reader;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReaderViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function work(int $pages = 24, array $overrides = []): Work
    {
        return Work::factory()->for(Mangaka::factory())->create(array_merge([
            'title' => '四畳半物語', 'page_count' => $pages,
        ], $overrides));
    }

    public function test_reader_renders_with_page_data_and_back_link(): void
    {
        $work = $this->work(24);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 1)", false)   // Alpine init, resume page 1
            ->assertSee('四畳半物語')
            ->assertSee('href="/work/'.$work->id.'"', false);   // back to detail
    }

    public function test_resumes_at_saved_page(): void
    {
        $work = $this->work(24);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 5]);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 5)", false);
    }

    public function test_completed_work_starts_at_page_one(): void
    {
        $work = $this->work(24);
        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 24, 'is_completed' => true]);

        $this->get("/work/{$work->id}/read")->assertOk()
            ->assertSee("reader({$work->id}, 24, 1)", false);
    }

    public function test_page_query_overrides_and_clamps(): void
    {
        $work = $this->work(24);

        $this->get("/work/{$work->id}/read?page=10")->assertSee("reader({$work->id}, 24, 10)", false);
        $this->get("/work/{$work->id}/read?page=999")->assertSee("reader({$work->id}, 24, 24)", false);
        $this->get("/work/{$work->id}/read?page=0")->assertSee("reader({$work->id}, 24, 1)", false);
    }

    public function test_zero_page_work_shows_no_pages(): void
    {
        $work = $this->work(0);

        $this->get("/work/{$work->id}/read")->assertOk()->assertSee('No pages');
    }
}
