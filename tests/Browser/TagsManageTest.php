<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

// Tags index: inline rename and merge via the Alpine tagManager component.
// タグ管理ページ：インラインリネームとマージをAlpineコンポーネントで検証。

test('tags page renders existing tags', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create();
    $this->attachTag($w, 'circle', 'RenameableCircle');

    $page = visit('/tags');

    $page->assertSee('RenameableCircle')
        ->assertNoJavaScriptErrors();
});

test('tags page can rename a tag inline', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create();
    $this->attachTag($w, 'circle', 'OldCircleName');

    $page = visit('/tags');

    $page->assertSee('OldCircleName');

    // Click Rename to enter edit mode. / Renameをクリックして編集モードへ。
    $page->click('Rename');

    // Clear the field and type the new name, then save.
    // フィールドをクリアして新しい名前を入力し保存。
    $page->type('input[type="text"]', 'NewCircleName');
    $page->click('Save');

    $page->assertSee('NewCircleName')
        ->assertDontSee('OldCircleName')
        ->assertNoJavaScriptErrors();
});

test('tags page shows merge control when multiple same-type tags exist', function (): void {
    $m = Mangaka::factory()->create();
    $w1 = Work::factory()->for($m)->create();
    $w2 = Work::factory()->for($m)->create();
    $this->attachTag($w1, 'circle', 'MergeSource');
    $this->attachTag($w2, 'circle', 'MergeTarget');

    $page = visit('/tags');

    // Both tags visible; the "Merge into…" select control is present in the DOM.
    // 両タグが表示され、「Merge into…」セレクト要素がDOMに存在する。
    $page->assertSee('MergeSource')
        ->assertSee('MergeTarget')
        ->assertScript('document.querySelector("select option[value=\"\"]") !== null', true)
        ->assertNoJavaScriptErrors();
});
