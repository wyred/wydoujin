<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Work;

beforeEach(function () {
    $this->withoutVite();
});

test('index lists mangaka with work counts', function (): void {
    $m = Mangaka::factory()->create(['name' => 'Z.A.P.']);
    Work::factory()->for($m)->create();
    Work::factory()->for($m)->create();

    $this->get('/mangaka')->assertOk()
        ->assertSee('Z.A.P.')
        ->assertSee('href="/mangaka/'.$m->slug.'"', false);
});

test('index empty state', function (): void {
    $this->get('/mangaka')->assertOk()->assertSee('No mangaka');
});

test('index shows numbered pagination with jump links', function (): void {
    // 24 per page → 50 mangaka spans 3 pages. / 1ページ24件。
    foreach (range(1, 50) as $i) {
        Mangaka::factory()->create(['name' => sprintf('Artist %02d', $i)]);
    }

    $this->get('/mangaka?page=2')->assertOk()
        ->assertSee('aria-current="page"', false)   // current page marker
        ->assertSee('href="http://localhost/mangaka?page=1"', false)  // jump back
        ->assertSee('href="http://localhost/mangaka?page=3"', false)  // jump forward
        ->assertDontSee('Page 2 of');                // old text is gone
});

test('show separates series and standalone works', function (): void {
    $m = Mangaka::factory()->create(['name' => 'CircleA']);
    $series = Series::factory()->for($m)->create(['name' => 'MyShelf']);
    Work::factory()->for($m)->create(['title' => 'SeriesVol1', 'series_id' => $series->id, 'sort_title' => 'SeriesVol1']);
    Work::factory()->for($m)->create(['title' => 'LoneWork', 'series_id' => null, 'sort_title' => 'LoneWork']);

    $this->get('/mangaka/'.$m->slug)->assertOk()
        ->assertSee('MyShelf')
        ->assertSee('href="/series/'.$series->id.'"', false)
        ->assertSee('LoneWork')
        ->assertSee('href="/work/'.Work::where('title', 'LoneWork')->first()->id.'"', false);
});

test('index filters by q', function (): void {
    Mangaka::factory()->create(['name' => 'AlphaArtist']);
    Mangaka::factory()->create(['name' => 'BetaArtist']);

    $this->get('/mangaka?q=alpha')->assertOk()
        ->assertSee('AlphaArtist')
        ->assertDontSee('BetaArtist');
});

test('q treats LIKE metacharacters literally', function (): void {
    // % and _ must not act as wildcards; ! (the escape char) must match itself.
    Mangaka::factory()->create(['name' => 'Percent%Name']);
    Mangaka::factory()->create(['name' => 'PercentXName']);
    Mangaka::factory()->create(['name' => 'Bang!Name']);

    $this->get('/mangaka?q='.urlencode('Percent%'))->assertOk()
        ->assertSee('Percent%Name')
        ->assertDontSee('PercentXName');

    $this->get('/mangaka?q='.urlencode('Bang!N'))->assertOk()
        ->assertSee('Bang!Name');
});

test('pagination links carry q', function (): void {
    // 24 per page → 30 matches span 2 pages. / 1ページ24件。
    foreach (range(1, 30) as $i) {
        Mangaka::factory()->create(['name' => sprintf('Match %02d', $i)]);
    }
    Mangaka::factory()->create(['name' => 'ZOther']);

    // Page URLs are HTML-escaped in Blade, so & renders as &amp;.
    $this->get('/mangaka?q=Match')->assertOk()
        ->assertSee('q=Match&amp;page=2', false)
        ->assertDontSee('ZOther');

    // The JSON path renders the same paginator, so its links carry q too.
    $res = $this->get('/mangaka?format=json&q=Match');
    $this->assertStringContainsString('q=Match&amp;page=2', $res->json('pagination'));
});

test('json endpoint returns total, cards html, and pagination html', function (): void {
    Mangaka::factory()->create(['name' => 'JsonArtist']);

    $res = $this->getJson('/mangaka')->assertOk()
        ->assertJsonStructure(['total', 'html', 'pagination']);
    expect($res->json('total'))->toBe(1);
    $this->assertStringContainsString('JsonArtist', $res->json('html'));
});

test('json respects q and format=json', function (): void {
    Mangaka::factory()->create(['name' => 'KeepMe']);
    Mangaka::factory()->create(['name' => 'DropMe']);

    $res = $this->get('/mangaka?format=json&q=Keep')->assertOk();
    expect($res->json('total'))->toBe(1);
    $this->assertStringContainsString('KeepMe', $res->json('html'));
    $this->assertStringNotContainsString('DropMe', $res->json('html'));
});

test('index renders live search wiring', function (): void {
    Mangaka::factory()->create(['name' => 'WiredArtist']);

    $this->get('/mangaka')->assertOk()
        ->assertSee('aria-label="Search mangaka"', false)
        ->assertSee('x-data="mangakaIndex', false)
        ->assertSee('x-ref="grid"', false)
        ->assertSee('x-ref="pagination"', false)
        ->assertSee('No mangaka match');   // empty-state element present in DOM (Alpine-hidden)
});

test('search input pre-fills from q for the no-js path', function (): void {
    Mangaka::factory()->create(['name' => 'PrefillArtist']);

    $this->get('/mangaka?q=Prefill')->assertOk()
        ->assertSee('value="Prefill"', false);
});
