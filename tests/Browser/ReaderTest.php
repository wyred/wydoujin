<?php

use Tests\Feature\Reader\ServesReadableWork;

uses(ServesReadableWork::class);

// Reader: chrome visibility, keyboard navigation, and RTL/LTR toggle.
// リーダー：クロム表示、キーボードナビゲーション、RTL/LTR切り替えを検証。
//
// NOTE: The reader chrome auto-hides after 2500ms idle. Page-indicator text
// visibility is asserted immediately on load before idle fires. Navigation is
// verified via assertScript on the Alpine component's page property rather than
// assertSee, since the chrome may have hidden by the time the assertion runs.
// 注：クロムは2500ms後に自動非表示。ページ表示はロード直後に確認。
// ナビゲーションはAlpineのpage属性をassertScriptで検証。

beforeEach(function (): void {
    $this->setUpReaderEnv();
});

afterEach(function (): void {
    $this->tearDownReaderEnv();
});

test('reader chrome renders with title and page indicator', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg', '003.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Chrome is visible on load; title and page indicator are present.
    // ロード直後はクロムが表示され、タイトルとページ表示が確認できる。
    $page->assertSee($work->title)
        ->assertSee('1 / 3')
        ->assertNoJavaScriptErrors();
});

test('reader ArrowLeft advances to next page in RTL mode via keyboard event', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg', '003.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Verify starting state. / 開始状態を確認。
    $page->assertScript('document.querySelector("[x-data]").__x?.$data?.page ?? Alpine.$data(document.querySelector("[x-data]"))?.page', 1);

    // In RTL (default), ArrowLeft → goLeft() → next() advances the page.
    // RTLデフォルトでArrowLeftがgoLeft()→next()でページを進める。
    $page->script('document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true }))');

    $page->assertScript('Alpine.$data(document.querySelector("[x-data]")).page', 2)
        ->assertNoJavaScriptErrors();
});

test('reader settings panel opens and shows direction controls', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Click the ⚙ settings button in the top chrome. / 上部クロムの⚙ボタンをクリック。
    $page->click('⚙');

    $page->assertSee('RTL')
        ->assertSee('LTR')
        ->assertNoJavaScriptErrors();
});

test('reader can switch from RTL to LTR via settings panel', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Open settings, click LTR. / 設定を開きLTRをクリック。
    $page->click('⚙');
    $page->click('LTR');

    // In LTR mode, ArrowRight → goRight() → next() advances the page.
    // LTRモードでArrowRightがページを進める。
    $page->script('document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true }))');

    $page->assertScript('Alpine.$data(document.querySelector("[x-data]")).page', 2)
        ->assertNoJavaScriptErrors();
});
