<?php

namespace Tests\Feature\Browse;

use App\Browse\WorkSearch;
use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSearchTest extends TestCase
{
    use RefreshDatabase;

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
        $this->work(['title' => 'A-P', 'sort_title' => '1', 'circle' => 'A', 'parody' => 'P']);
        $this->work(['title' => 'B-P', 'sort_title' => '2', 'circle' => 'B', 'parody' => 'P']);
        $this->work(['title' => 'C-Q', 'sort_title' => '3', 'circle' => 'C', 'parody' => 'Q']);

        // OR within circle:
        $or = (new WorkSearch(circle: ['A', 'B']))->results()->pluck('title')->all();
        sort($or);
        $this->assertSame(['A-P', 'B-P'], $or);

        // AND across circle + parody:
        $and = (new WorkSearch(circle: ['A', 'B'], parody: ['Q']))->results()->pluck('title')->all();
        $this->assertSame([], $and); // A,B are parody P, not Q
    }

    public function test_counts_are_dynamic_and_exclude_own_dimension(): void
    {
        $this->work(['title' => 'w1', 'sort_title' => '1', 'circle' => 'A', 'parody' => 'P']);
        $this->work(['title' => 'w2', 'sort_title' => '2', 'circle' => 'A', 'parody' => 'Q']);
        $this->work(['title' => 'w3', 'sort_title' => '3', 'circle' => 'B', 'parody' => 'P']);

        $facets = (new WorkSearch(parody: ['P']))->facets();

        // circle counted under parody=P → {A:1 (w1), B:1 (w3)}
        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(['A' => 1, 'B' => 1], $circle);

        // parody EXCLUDES its own selection → counted over all → {P:2, Q:1}
        $parody = collect($facets['parody'])->pluck('count', 'value')->all();
        $this->assertSame(['P' => 2, 'Q' => 1], $parody);
    }

    public function test_selected_value_kept_visible_when_zero(): void
    {
        $this->work(['title' => 'only', 'sort_title' => '1', 'circle' => 'A']);

        $facets = (new WorkSearch(circle: ['B']))->facets(); // B has no works

        $circle = collect($facets['circle'])->pluck('count', 'value')->all();
        $this->assertSame(1, $circle['A']);
        $this->assertArrayHasKey('B', $circle);
        $this->assertSame(0, $circle['B']); // selected → retained at 0
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
}
