<?php

namespace Tests\Feature\Browse;

use App\Browse\WorkSearch;
use App\Models\Mangaka;
use App\Models\Tag;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class WorkSearchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    private ?Mangaka $m = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = null;
    }

    /** @param array<string,mixed> $a */
    private function work(array $a): Work
    {
        $this->m ??= Mangaka::factory()->create();

        return Work::factory()->for($this->m)->create($a);
    }

    public function test_excludes_missing_works(): void
    {
        $this->work(['title' => 'Seen', 'sort_title' => 'Seen', 'is_missing' => false]);
        $this->work(['title' => 'Gone', 'sort_title' => 'Gone', 'is_missing' => true]);

        $titles = (new WorkSearch())->results()->pluck('title')->all();

        $this->assertContains('Seen', $titles);
        $this->assertNotContains('Gone', $titles);
    }

    public function test_q_matches_title_and_title_raw_case_insensitively(): void
    {
        $this->work(['title' => 'Hello World', 'title_raw' => 'Hello World', 'sort_title' => 'a']);
        $this->work(['title' => 'zzz', 'title_raw' => '(C99) hello [raw]', 'sort_title' => 'b']); // matches via title_raw
        $this->work(['title' => 'nope', 'title_raw' => 'nope', 'sort_title' => 'c']);

        $titles = (new WorkSearch(q: 'HELLO'))->results()->pluck('title')->all();

        sort($titles);
        $this->assertSame(['Hello World', 'zzz'], $titles);
    }

    public function test_facets_or_within_and_and_across(): void
    {
        $a = $this->work(['title' => 'A-P', 'sort_title' => '1']); $this->attachTag($a, 'circle', 'A'); $this->attachTag($a, 'parody', 'P');
        $b = $this->work(['title' => 'B-P', 'sort_title' => '2']); $this->attachTag($b, 'circle', 'B'); $this->attachTag($b, 'parody', 'P');
        $c = $this->work(['title' => 'C-Q', 'sort_title' => '3']); $this->attachTag($c, 'circle', 'C'); $this->attachTag($c, 'parody', 'Q');

        $or = (new WorkSearch(circle: ['A', 'B']))->results()->pluck('title')->all();
        sort($or);
        $this->assertSame(['A-P', 'B-P'], $or);

        $and = (new WorkSearch(circle: ['A', 'B'], parody: ['Q']))->results()->pluck('title')->all();
        $this->assertSame([], $and);
    }

    public function test_counts_are_dynamic_and_exclude_own_dimension(): void
    {
        $w1 = $this->work(['title' => 'w1', 'sort_title' => '1']); $this->attachTag($w1, 'circle', 'A'); $this->attachTag($w1, 'parody', 'P');
        $w2 = $this->work(['title' => 'w2', 'sort_title' => '2']); $this->attachTag($w2, 'circle', 'A'); $this->attachTag($w2, 'parody', 'Q');
        $w3 = $this->work(['title' => 'w3', 'sort_title' => '3']); $this->attachTag($w3, 'circle', 'B'); $this->attachTag($w3, 'parody', 'P');

        $facets = (new WorkSearch(parody: ['P']))->facets();

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(['A' => 1, 'B' => 1], $circle);

        $parody = collect($facets['parody'])->pluck('count', 'value')->all();
        $this->assertSame(['P' => 2, 'Q' => 1], $parody);
    }

    public function test_selected_value_kept_visible_when_zero(): void
    {
        $w = $this->work(['title' => 'only', 'sort_title' => '1']); $this->attachTag($w, 'circle', 'A');

        $facets = (new WorkSearch(circle: ['B']))->facets();

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(1, $circle['A']);
        $this->assertArrayHasKey('B', $circle);
        $this->assertSame(0, $circle['B']);
    }

    public function test_facets_cover_author_and_flag_dimensions(): void
    {
        $w = $this->work(['title' => 'x', 'sort_title' => '1']);
        $this->attachTag($w, 'author', 'Z'); $this->attachTag($w, 'flag', 'DL版');

        $facets = (new WorkSearch())->facets();

        $this->assertSame(['circle', 'parody', 'event', 'author', 'flag', 'theme'], array_keys($facets));
        $this->assertSame(['Z' => 1], collect($facets['author'])->pluck('count', 'value')->all());
        $this->assertSame(['DL版' => 1], collect($facets['flag'])->pluck('count', 'value')->all());
    }

    public function test_q_treats_percent_and_underscore_literally(): void
    {
        $this->work(['title' => '100% Pure', 'sort_title' => '1']);
        $this->work(['title' => '100 Things', 'sort_title' => '2']);
        $this->work(['title' => 'a_b match', 'sort_title' => '3']);
        $this->work(['title' => 'axb other', 'sort_title' => '4']);

        // '%' is escaped → only the literal "100%" title matches (NOT "100 Things").
        $this->assertSame(['100% Pure'], (new WorkSearch(q: '100%'))->results()->pluck('title')->all());
        // '_' is escaped → only the literal "a_b" matches (NOT "axb", where _ would be any-char).
        $this->assertSame(['a_b match'], (new WorkSearch(q: 'a_b'))->results()->pluck('title')->all());
    }

    public function test_results_ordered_by_sort_title_and_paginated(): void
    {
        $this->work(['title' => 'C', 'sort_title' => 'C']);
        $this->work(['title' => 'A', 'sort_title' => 'A']);
        $this->work(['title' => 'B', 'sort_title' => 'B']);

        $page = (new WorkSearch())->results(page: 1, perPage: 2);

        $this->assertSame(['A', 'B'], $page->pluck('title')->all());
        $this->assertSame(3, $page->total());
        $this->assertTrue($page->hasMorePages());
    }

    /**
     * Locks the tombstone-exclusion guard in facets(): whereNull('tags.merged_into_id')
     * ensures alias (tombstone) tags never surface as selectable facet values.
     * トゥームストーン（エイリアス）タグがファセットに現れないことを保証するガードをロック。
     */
    public function test_facets_exclude_tombstone_tags(): void
    {
        $w = $this->work(['title' => 'x', 'sort_title' => '1']);
        $real = $this->attachTag($w, 'circle', 'Real');

        // Simulate the guard: attach a tombstone tag directly to the work.
        // トゥームストーンタグを直接ピボットに紐付け、ガードが機能するか確認。
        $tomb = Tag::create(['type' => 'circle', 'value' => 'Gone', 'merged_into_id' => $real->id]);
        $w->tags()->attach($tomb->id);

        $circles = collect((new WorkSearch())->facets()['circle'])->pluck('value')->all();

        $this->assertContains('Real', $circles);
        $this->assertNotContains('Gone', $circles);
    }
}
