<?php

use App\Models\Mangaka;

// /mangaka live search: typing filters the grid after the debounce without a page
// load, clearing restores it, and the URL stays in sync.
// マンガ家検索：入力後デバウンスを経てグリッドがライブ更新される。

test('typing filters the mangaka grid live and clearing restores it', function (): void {
    Mangaka::factory()->create(['name' => 'AlphaArtist']);
    Mangaka::factory()->create(['name' => 'BetaArtist']);

    $page = visit('/mangaka');

    $page->assertSee('AlphaArtist')
        ->assertSee('BetaArtist');

    // fill() fires input events, so x-model + the debounce watcher run as if typed.
    $page->fill("input[aria-label='Search mangaka']", 'Alpha');

    // Debounce (250ms) + fetch, then the grid swaps — see/dont-see assertions retry.
    $page->assertDontSee('BetaArtist')
        ->assertSee('AlphaArtist');

    // URL synced via replaceState, no navigation. / replaceStateでURL同期。
    $page->assertScript('location.search', '?q=Alpha');

    $page->fill("input[aria-label='Search mangaka']", '');
    $page->assertSee('BetaArtist')
        ->assertNoJavaScriptErrors();
});

test('no-match state appears and the clear button restores the grid', function (): void {
    Mangaka::factory()->create(['name' => 'OnlyArtist']);

    $page = visit('/mangaka');

    $page->fill("input[aria-label='Search mangaka']", 'zzz');
    $page->assertSee('No mangaka match');

    $page->click('Clear search');
    $page->assertSee('OnlyArtist')
        ->assertNoJavaScriptErrors();
});

test('live search works in dark mode without errors', function (): void {
    Mangaka::factory()->create(['name' => 'DarkArtist']);
    Mangaka::factory()->create(['name' => 'DarkOther']);

    $page = visit('/mangaka');
    $page->click('☾'); // theme toggle → data-dark="true" on <html>

    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true');

    $page->fill("input[aria-label='Search mangaka']", 'DarkArtist');
    $page->assertDontSee('DarkOther')
        ->assertSee('DarkArtist')
        ->assertNoJavaScriptErrors();
});
