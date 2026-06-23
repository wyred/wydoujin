<?php

// Maintenance page: scan UI renders and the Alpine status component initialises.
// メンテナンスページ：スキャンUIが描画されAlpineステータスコンポーネントが初期化される。
//
// NOTE: A full scan requires a running queue worker. We only assert the page and
// controls render correctly and that no JS errors occur — we do not trigger or
// await an actual scan.
// 注：実際のスキャン実行はキューワーカーが必要なため、ここではUIの描画とJSエラーなしのみ検証。

test('maintenance page renders scan controls', function (): void {
    $page = visit('/maintenance');

    $page->assertSee('Scan now')
        ->assertNoJavaScriptErrors();
});

test('maintenance page shows recent scans section', function (): void {
    $page = visit('/maintenance');

    // The "Recent scans" heading and the empty-state message are server-rendered.
    // 「Recent scans」見出しと空状態メッセージはサーバーレンダリング。
    $page->assertSee('Recent scans')
        ->assertNoJavaScriptErrors();
});
