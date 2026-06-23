<?php

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;

beforeEach(function (): void {
    $this->withoutVite();
});

// Helper: create a Work with the given page count. / 指定ページ数のWorkを作成。
function workForReaderView(int $pages = 24, array $overrides = []): Work
{
    return Work::factory()->for(Mangaka::factory())->create(array_merge([
        'title' => '四畳半物語', 'page_count' => $pages,
    ], $overrides));
}

test('reader renders with page data and back link', function (): void {
    $work = workForReaderView(24);

    $this->get("/work/{$work->id}/read")->assertOk()
        ->assertSee("reader({$work->id}, 24, 1)", false)   // Alpine init, resume page 1
        ->assertSee('四畳半物語')
        ->assertSee('href="/work/'.$work->id.'"', false);   // back to detail
});

test('resumes at saved page', function (): void {
    $work = workForReaderView(24);
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 5]);

    $this->get("/work/{$work->id}/read")->assertOk()
        ->assertSee("reader({$work->id}, 24, 5)", false);
});

test('completed work starts at page one', function (): void {
    $work = workForReaderView(24);
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 24, 'is_completed' => true]);

    $this->get("/work/{$work->id}/read")->assertOk()
        ->assertSee("reader({$work->id}, 24, 1)", false);
});

test('page query overrides and clamps', function (): void {
    $work = workForReaderView(24);

    $this->get("/work/{$work->id}/read?page=10")->assertSee("reader({$work->id}, 24, 10)", false);
    $this->get("/work/{$work->id}/read?page=999")->assertSee("reader({$work->id}, 24, 24)", false);
    $this->get("/work/{$work->id}/read?page=0")->assertSee("reader({$work->id}, 24, 1)", false);
});

test('zero page work shows no pages', function (): void {
    $work = workForReaderView(0);

    $this->get("/work/{$work->id}/read")->assertOk()->assertSee('No pages');
});
