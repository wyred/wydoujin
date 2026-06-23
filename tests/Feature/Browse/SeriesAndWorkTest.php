<?php

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(Tests\Concerns\SeedsTags::class);

beforeEach(function () {
    $this->withoutVite();
});

test('series lists works in sort order', function (): void {
    $m = Mangaka::factory()->create();
    $series = Series::factory()->for($m)->create(['name' => 'TheSeries']);
    Work::factory()->for($m)->create(['title' => 'Bravo', 'series_id' => $series->id, 'sort_title' => 'Bravo']);
    Work::factory()->for($m)->create(['title' => 'Alpha', 'series_id' => $series->id, 'sort_title' => 'Alpha']);

    $res = $this->get('/series/'.$series->id)->assertOk()
        ->assertSee('TheSeries')->assertSee('Alpha')->assertSee('Bravo');
    $this->assertTrue(strpos($res->getContent(), 'Alpha') < strpos($res->getContent(), 'Bravo'));
});

test('work detail shows metadata badges progress and read cta', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
    $work = Work::factory()->for($m)->create(['title' => '四畳半物語', 'page_count' => 24, 'cover_path' => 'covers/h.webp']);
    $this->attachTag($work, 'circle', 'Z.A.P.');
    $this->attachTag($work, 'author', 'ズッキーニ');
    $this->attachTag($work, 'parody', 'オリジナル');
    $this->attachTag($work, 'event', 'C89');
    $this->attachTag($work, 'flag', 'DL版');
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

    $this->get('/work/'.$work->id)->assertOk()
        ->assertSee('四畳半物語')
        ->assertSee('ズッキーニ')
        ->assertSee('オリジナル')
        ->assertSee('C89')
        ->assertSee('DL版')
        ->assertSee('24 pages')
        ->assertSee('3/24')
        ->assertSee('href="'.e('/browse?'.http_build_query(['parody' => ['オリジナル']])).'"', false) // clickable tag
        ->assertSee('href="/work/'.$work->id.'/read"', false)
        ->assertSee('Continue');
});
