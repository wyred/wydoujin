<?php

use App\Browse\WorkSearch;
use App\Models\Mangaka;
use App\Models\Tag;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(Tests\Concerns\SeedsTags::class);

beforeEach(function () {
    $this->m = null;
});

/** @param array<string,mixed> $a */
function workForWorkSearch(array $a): Work
{
    $m = test()->m ??= Mangaka::factory()->create();

    return Work::factory()->for($m)->create($a);
}

test('excludes missing works', function (): void {
    workForWorkSearch(['title' => 'Seen', 'sort_title' => 'Seen', 'is_missing' => false]);
    workForWorkSearch(['title' => 'Gone', 'sort_title' => 'Gone', 'is_missing' => true]);

    $titles = (new WorkSearch())->results()->pluck('title')->all();

    $this->assertContains('Seen', $titles);
    $this->assertNotContains('Gone', $titles);
});

test('q matches title and title raw case insensitively', function (): void {
    workForWorkSearch(['title' => 'Hello World', 'title_raw' => 'Hello World', 'sort_title' => 'a']);
    workForWorkSearch(['title' => 'zzz', 'title_raw' => '(C99) hello [raw]', 'sort_title' => 'b']); // matches via title_raw
    workForWorkSearch(['title' => 'nope', 'title_raw' => 'nope', 'sort_title' => 'c']);

    $titles = (new WorkSearch(q: 'HELLO'))->results()->pluck('title')->all();

    sort($titles);
    $this->assertSame(['Hello World', 'zzz'], $titles);
});

test('facets or within and and across', function (): void {
    $a = workForWorkSearch(['title' => 'A-P', 'sort_title' => '1']); $this->attachTag($a, 'circle', 'A'); $this->attachTag($a, 'parody', 'P');
    $b = workForWorkSearch(['title' => 'B-P', 'sort_title' => '2']); $this->attachTag($b, 'circle', 'B'); $this->attachTag($b, 'parody', 'P');
    $c = workForWorkSearch(['title' => 'C-Q', 'sort_title' => '3']); $this->attachTag($c, 'circle', 'C'); $this->attachTag($c, 'parody', 'Q');

    $or = (new WorkSearch(circle: ['A', 'B']))->results()->pluck('title')->all();
    sort($or);
    $this->assertSame(['A-P', 'B-P'], $or);

    $and = (new WorkSearch(circle: ['A', 'B'], parody: ['Q']))->results()->pluck('title')->all();
    $this->assertSame([], $and);
});

test('counts are dynamic and exclude own dimension', function (): void {
    $w1 = workForWorkSearch(['title' => 'w1', 'sort_title' => '1']); $this->attachTag($w1, 'circle', 'A'); $this->attachTag($w1, 'parody', 'P');
    $w2 = workForWorkSearch(['title' => 'w2', 'sort_title' => '2']); $this->attachTag($w2, 'circle', 'A'); $this->attachTag($w2, 'parody', 'Q');
    $w3 = workForWorkSearch(['title' => 'w3', 'sort_title' => '3']); $this->attachTag($w3, 'circle', 'B'); $this->attachTag($w3, 'parody', 'P');

    $facets = (new WorkSearch(parody: ['P']))->facets();

    $circle = collect($facets['circle'])->pluck('count', 'value')->all();
    $this->assertSame(['A' => 1, 'B' => 1], $circle);

    $parody = collect($facets['parody'])->pluck('count', 'value')->all();
    $this->assertSame(['P' => 2, 'Q' => 1], $parody);
});

test('selected value kept visible when zero', function (): void {
    $w = workForWorkSearch(['title' => 'only', 'sort_title' => '1']); $this->attachTag($w, 'circle', 'A');

    $facets = (new WorkSearch(circle: ['B']))->facets();

    $circle = collect($facets['circle'])->pluck('count', 'value')->all();
    $this->assertSame(1, $circle['A']);
    $this->assertArrayHasKey('B', $circle);
    $this->assertSame(0, $circle['B']);
});

test('facets cover author and flag dimensions', function (): void {
    $w = workForWorkSearch(['title' => 'x', 'sort_title' => '1']);
    $this->attachTag($w, 'author', 'Z'); $this->attachTag($w, 'flag', 'DL版');

    $facets = (new WorkSearch())->facets();

    $this->assertSame(['circle', 'parody', 'event', 'author', 'flag', 'theme'], array_keys($facets));
    $this->assertSame(['Z' => 1], collect($facets['author'])->pluck('count', 'value')->all());
    $this->assertSame(['DL版' => 1], collect($facets['flag'])->pluck('count', 'value')->all());
});

test('q treats percent and underscore literally', function (): void {
    workForWorkSearch(['title' => '100% Pure', 'sort_title' => '1']);
    workForWorkSearch(['title' => '100 Things', 'sort_title' => '2']);
    workForWorkSearch(['title' => 'a_b match', 'sort_title' => '3']);
    workForWorkSearch(['title' => 'axb other', 'sort_title' => '4']);

    // '%' is escaped → only the literal "100%" title matches (NOT "100 Things").
    $this->assertSame(['100% Pure'], (new WorkSearch(q: '100%'))->results()->pluck('title')->all());
    // '_' is escaped → only the literal "a_b" matches (NOT "axb", where _ would be any-char).
    $this->assertSame(['a_b match'], (new WorkSearch(q: 'a_b'))->results()->pluck('title')->all());
});

test('results ordered by sort title and paginated', function (): void {
    workForWorkSearch(['title' => 'C', 'sort_title' => 'C']);
    workForWorkSearch(['title' => 'A', 'sort_title' => 'A']);
    workForWorkSearch(['title' => 'B', 'sort_title' => 'B']);

    $page = (new WorkSearch())->results(page: 1, perPage: 2);

    $this->assertSame(['A', 'B'], $page->pluck('title')->all());
    $this->assertSame(3, $page->total());
    $this->assertTrue($page->hasMorePages());
});

/**
 * Locks the tombstone-exclusion guard in facets(): whereNull('tags.merged_into_id')
 * ensures alias (tombstone) tags never surface as selectable facet values.
 * トゥームストーン（エイリアス）タグがファセットに現れないことを保証するガードをロック。
 */
test('facets exclude tombstone tags', function (): void {
    $w = workForWorkSearch(['title' => 'x', 'sort_title' => '1']);
    $real = $this->attachTag($w, 'circle', 'Real');

    // Simulate the guard: attach a tombstone tag directly to the work.
    // トゥームストーンタグを直接ピボットに紐付け、ガードが機能するか確認。
    $tomb = Tag::create(['type' => 'circle', 'value' => 'Gone', 'merged_into_id' => $real->id]);
    $w->tags()->attach($tomb->id);

    $circles = collect((new WorkSearch())->facets()['circle'])->pluck('value')->all();

    $this->assertContains('Real', $circles);
    $this->assertNotContains('Gone', $circles);
});
