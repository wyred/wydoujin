<?php

use Tests\Feature\Reader\ServesReadableWork;

uses(ServesReadableWork::class);

// Reader: chrome visibility, keyboard navigation, and RTL/LTR toggle.
// リーダー：クロム表示、キーボードナビゲーション、RTL/LTR切り替えを検証。
//
// NOTE: The reader chrome is hidden on load for immersive reading and must be
// summoned (center tap → showChrome()) before its content is visible; it then
// auto-hides after 2500ms idle. Navigation is verified via assertScript on the
// Alpine component's page property rather than assertSee, since the chrome may
// be hidden by the time the assertion runs.
// 注：クロムはロード時非表示（没入）。中央タップで表示し、2500ms後に自動非表示。
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

    // Chrome is hidden on load for immersive reading; a center tap summons it,
    // then the title and page indicator are present.
    // ロード直後はクロム非表示（没入）。中央タップで表示し、タイトルとページ表示を確認。
    $page->script('Alpine.$data(document.querySelector("[x-data]")).showChrome()');

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

test('reader chrome stays hidden while turning pages', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg', '003.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Simulate the idle auto-hide having fired (instead of waiting 2500ms).
    // アイドル自動非表示が起きた状態を再現。
    $page->script('Alpine.$data(document.querySelector("[x-data]")).chrome = false');

    // Turn the page via keyboard — the chrome must stay hidden (immersive reading).
    // キーボードでページ送り — クロムは非表示のまま。
    $page->script('document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true }))');

    $page->assertScript('Alpine.$data(document.querySelector("[x-data]")).page', 2)
        ->assertScript('Alpine.$data(document.querySelector("[x-data]")).chrome', false)
        ->assertNoJavaScriptErrors();
});

test('reader settings panel opens and shows direction controls', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Summon the chrome (hidden on load), then click the ⚙ settings button.
    // クロムを表示してから⚙ボタンをクリック。
    $page->script('Alpine.$data(document.querySelector("[x-data]")).showChrome()');
    $page->click('⚙');

    $page->assertSee('RTL')
        ->assertSee('LTR')
        ->assertNoJavaScriptErrors();
});

test('reader can switch from RTL to LTR via settings panel', function (): void {
    $work = $this->makeReadableWork(['001.jpg', '002.jpg']);

    $page = visit('/work/'.$work->id.'/read');

    // Summon the chrome (hidden on load), open settings, click LTR.
    // クロムを表示し、設定を開きLTRをクリック。
    $page->script('Alpine.$data(document.querySelector("[x-data]")).showChrome()');
    $page->click('⚙');
    $page->click('LTR');

    // In LTR mode, ArrowRight → goRight() → next() advances the page.
    // LTRモードでArrowRightがページを進める。
    $page->script('document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true }))');

    $page->assertScript('Alpine.$data(document.querySelector("[x-data]")).page', 2)
        ->assertNoJavaScriptErrors();
});
