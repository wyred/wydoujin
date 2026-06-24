<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(Tests\Concerns\SeedsTags::class);

beforeEach(function () {
    $this->withoutVite();
    $this->m = null;
});

/** @param array<string,mixed> $a */
function workForBrowseSearch(array $a): Work
{
    $m = test()->m ??= Mangaka::factory()->create();

    return Work::factory()->for($m)->create($a);
}

test('browse renders grid and nav link', function (): void {
    workForBrowseSearch(['title' => 'Findable Title', 'sort_title' => 'a']);

    $this->get('/browse')->assertOk()
        ->assertSee('Findable Title')
        ->assertSee('href="/browse"', false)  // nav link
        ->assertSee('No works match');         // empty-state element present in DOM (Alpine-hidden)
});

test('browse wires up infinite-scroll auto-load', function (): void {
    workForBrowseSearch(['title' => 'Scrolly', 'sort_title' => 'a']);

    $this->get('/browse')->assertOk()
        ->assertSee('x-ref="sentinel"', false)        // the observed sentinel
        ->assertSee('IntersectionObserver', false)    // the auto-load wiring
        ->assertSee('Load more');                     // manual fallback still present
});

test('q filters server rendered results', function (): void {
    workForBrowseSearch(['title' => 'Alpha Doujin', 'sort_title' => 'a']);
    workForBrowseSearch(['title' => 'Beta Manga', 'sort_title' => 'b']);

    $this->get('/browse?q=alpha')->assertOk()
        ->assertSee('Alpha Doujin')
        ->assertDontSee('Beta Manga');
});

test('facet filters results', function (): void {
    $zap = workForBrowseSearch(['title' => 'ZapWork', 'sort_title' => 'a']); $this->attachTag($zap, 'circle', 'Z.A.P.');
    $foo = workForBrowseSearch(['title' => 'FooWork', 'sort_title' => 'b']); $this->attachTag($foo, 'circle', 'Foo');

    $url = '/browse?'.http_build_query(['circle' => ['Z.A.P.']]);
    $this->get($url)->assertOk()
        ->assertSee('ZapWork')
        ->assertDontSee('FooWork');
});

test('excludes missing', function (): void {
    workForBrowseSearch(['title' => 'GoneWork', 'sort_title' => 'a', 'is_missing' => true]);

    $this->get('/browse')->assertOk()->assertDontSee('GoneWork');
});

test('embeds facet data for alpine', function (): void {
    $w = workForBrowseSearch(['title' => 'X', 'sort_title' => 'a']); $this->attachTag($w, 'circle', 'Z.A.P.');

    // The facet value ships in the embedded initial-state JSON.
    $this->get('/browse')->assertOk()->assertSee('Z.A.P.');
});

test('json endpoint shape', function (): void {
    $w = workForBrowseSearch(['title' => 'JsonWork', 'sort_title' => 'a']); $this->attachTag($w, 'circle', 'C1');

    $res = $this->getJson('/browse')->assertOk()
        ->assertJsonStructure([
            'total', 'page', 'hasMore',
            'facets' => ['circle', 'parody', 'event', 'author', 'flag', 'theme'],
            'html',
        ]);
    $this->assertStringContainsString('JsonWork', $res->json('html'));
});
