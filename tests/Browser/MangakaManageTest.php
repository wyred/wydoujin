<?php

use App\Models\Mangaka;
use App\Models\Work;

// Mangaka detail page: manage mode checkbox list and series creation.
// 漫画家詳細ページ：管理モードのチェックリストとシリーズ作成を検証。

test('mangaka page shows standalone works', function (): void {
    $m = Mangaka::factory()->create(['name' => 'ManageArtist']);
    Work::factory()->for($m)->create(['title' => 'StandaloneOne']);
    Work::factory()->for($m)->create(['title' => 'StandaloneTwo']);

    $page = visit('/mangaka/'.$m->slug);

    $page->assertSee('ManageArtist')
        ->assertSee('StandaloneOne')
        ->assertSee('StandaloneTwo')
        ->assertNoJavaScriptErrors();
});

test('mangaka page manage button toggles checkable list', function (): void {
    $m = Mangaka::factory()->create(['name' => 'ManageModeArtist']);
    Work::factory()->for($m)->create(['title' => 'ManageWorkAlpha']);
    Work::factory()->for($m)->create(['title' => 'ManageWorkBeta']);

    $page = visit('/mangaka/'.$m->slug);

    // Manage button visible; click it to enter manage mode.
    // Manageボタンをクリックして管理モードへ。
    $page->assertSee('Manage');
    $page->click('Manage');

    // In manage mode the flat checkable list is shown.
    // 管理モードではフラットなチェックリストが表示される。
    $page->assertSee('ManageWorkAlpha')
        ->assertSee('ManageWorkBeta')
        ->assertNoJavaScriptErrors();
});

test('mangaka manage mode shows series creation controls after selecting works', function (): void {
    $m = Mangaka::factory()->create(['name' => 'SeriesCreateArtist']);
    Work::factory()->for($m)->create(['title' => 'SeriesWorkOne']);

    $page = visit('/mangaka/'.$m->slug);

    $page->click('Manage');

    // Select the work via script to avoid strict-mode single-element ambiguity.
    // スクリプトでチェックボックスを選択（strict-mode回避）。
    $page->script('document.querySelector("input[type=checkbox]").click()');

    // The sticky action bar with the "Create series" button appears.
    // スティッキーアクションバーに「Create series」ボタンが表示される。
    $page->assertSee('Create series')
        ->assertNoJavaScriptErrors();
});
