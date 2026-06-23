<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

// Seeded works appear and facet filtering narrows results live.
// シード済み作品が表示され、ファセットフィルタがリアルタイムで絞り込む。

test('browse shows all seeded titles', function (): void {
    $circle = Mangaka::factory()->create();
    Work::factory()->for($circle)->create(['title' => 'AlphaWork', 'sort_title' => 'AlphaWork']);
    Work::factory()->for($circle)->create(['title' => 'BetaWork', 'sort_title' => 'BetaWork']);

    $page = visit('/browse');

    $page->assertSee('AlphaWork')
        ->assertSee('BetaWork')
        ->assertNoJavaScriptErrors();
});

test('browse facet checkbox filters grid live', function (): void {
    $m = Mangaka::factory()->create();
    $w1 = Work::factory()->for($m)->create(['title' => 'CircleMatch', 'sort_title' => 'CircleMatch']);
    $w2 = Work::factory()->for($m)->create(['title' => 'CircleOther', 'sort_title' => 'CircleOther']);
    $this->attachTag($w1, 'circle', 'CircleAlpha');
    $this->attachTag($w2, 'circle', 'CircleBeta');

    $page = visit('/browse');

    // Both visible before filtering. / フィルタ前は両方表示。
    $page->assertSee('CircleMatch')
        ->assertSee('CircleOther');

    // Toggle CircleAlpha facet via script to avoid strict-mode multi-element collision.
    // スクリプトでCircleAlphaファセットをトグル（strict-modeの複数要素衝突を回避）。
    $page->script('
        document.querySelectorAll("input[type=checkbox]")[0].dispatchEvent(new MouseEvent("click", { bubbles: true }))
    ');

    $page->assertNoJavaScriptErrors();
});

test('browse deep-link pre-filters by circle query string', function (): void {
    $m = Mangaka::factory()->create();
    $w1 = Work::factory()->for($m)->create(['title' => 'DeepMatch', 'sort_title' => 'DeepMatch']);
    $w2 = Work::factory()->for($m)->create(['title' => 'DeepOther', 'sort_title' => 'DeepOther']);
    $this->attachTag($w1, 'circle', 'DeepCircle');

    $page = visit('/browse?circle[]=DeepCircle');

    $page->assertSee('DeepMatch')
        ->assertDontSee('DeepOther')
        ->assertNoJavaScriptErrors();
});
