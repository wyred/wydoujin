<?php

use App\Models\Mangaka;
use App\Models\Work;

// The cover play-button shortcut: hidden until hover, then click the circle to
// open the reader; clicking the cover elsewhere opens the detail page.
// カバーの再生ボタン：ホバーで表示、円をクリックでリーダー、その他は詳細ページ。

test('play button is hidden by default, reveals on hover, and opens the reader', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create(['title' => 'PlayableWork', 'sort_title' => 'PlayableWork']);

    // /browse renders one card server-side, so the play link selector is unique.
    $page = visit('/browse');
    $page->assertPresent("a[aria-label^='Read']");

    // Hidden by default (no transition is running at load, so opacity is a stable "0").
    $page->assertScript("getComputedStyle(document.querySelector(\"a[aria-label^='Read']\")).opacity", '0');

    // Kill transitions so the post-hover opacity read is deterministic, then hover.
    $page->script("var s=document.createElement('style');s.textContent='*{transition:none !important}';document.head.appendChild(s)");
    $page->hover("a[aria-label^='Read']");
    $page->assertScript("getComputedStyle(document.querySelector(\"a[aria-label^='Read']\")).opacity", '1');

    // Real pointer click on the circle — genuinely hit-tests that the play link is the
    // topmost interactive layer (z-30, pointer-events:auto), not just that its handler fires.
    $page->click("a[aria-label^='Read']");
    $page->assertPathIs('/work/'.$work->id.'/read')
        ->assertNoJavaScriptErrors();
});

test('clicking the cover away from the circle opens the detail page', function (): void {
    $m = Mangaka::factory()->create();
    $work = Work::factory()->for($m)->create(['title' => 'DetailWork', 'sort_title' => 'DetailWork']);

    $page = visit('/browse');

    // The high-level browser API clicks element centers, and the play circle sits at the
    // cover's center — so neutralise it first. A real click on the detail overlay must then
    // pass through the scrim + centering layer (both pointer-events:none) to land on the
    // detail page, proving the layering routes non-circle clicks correctly.
    $page->script("document.querySelector(\"a[aria-label^='Read']\").remove()");
    $page->click("a[aria-hidden='true'][href='/work/".$work->id."']");
    $page->assertPathIs('/work/'.$work->id)
        ->assertNoJavaScriptErrors();
});

test('play button renders without errors in dark mode', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'DarkPlayWork', 'sort_title' => 'DarkPlayWork']);

    $page = visit('/browse');
    $page->click('☾'); // theme toggle → data-dark="true" on <html>

    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true')
        ->assertPresent("a[aria-label^='Read']")
        ->assertNoJavaScriptErrors();
});
