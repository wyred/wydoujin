<?php

use App\Models\Mangaka;
use App\Models\Work;

// Dark mode toggle: nav button sets data-dark="true" on <html>.
// ダークモード切り替え：ナビボタンが<html>にdata-dark="true"を設定する。

test('browse page dark mode toggle sets data-dark on html element', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'DarkWork']);

    $page = visit('/browse');

    // Light mode by default: no data-dark attribute.
    // デフォルトはライトモード：data-dark属性なし。
    $page->assertSee('DarkWork');

    // Click the ☾ theme toggle button. / ☾テーマ切り替えボタンをクリック。
    $page->click('☾');

    // After click, data-dark="true" is set on <html>.
    // クリック後、<html>にdata-dark="true"が設定される。
    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true')
        ->assertNoJavaScriptErrors();
});

test('work detail dark mode toggle sets data-dark on html element', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create(['title' => 'DarkDetailWork']);

    $page = visit('/work/'.$w->id);

    $page->assertSee('DarkDetailWork');

    $page->click('☾');

    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true')
        ->assertNoJavaScriptErrors();
});

test('dark mode persists: toggling back removes data-dark', function (): void {
    $m = Mangaka::factory()->create();
    Work::factory()->for($m)->create(['title' => 'ToggleBackWork']);

    $page = visit('/browse');

    // Toggle on then off. / オンにしてからオフに。
    $page->click('☾');
    $page->assertScript('document.documentElement.getAttribute("data-dark")', 'true');

    $page->click('☀');
    $page->assertScript('document.documentElement.hasAttribute("data-dark")', false)
        ->assertNoJavaScriptErrors();
});
