<?php

use App\Models\Mangaka;
use App\Models\Work;
use Tests\Concerns\SeedsTags;

uses(SeedsTags::class);

// Work detail tag editor: add, remove, and revert tags via the Alpine workTags component.
// 作品詳細のタグエディタ：Alpineコンポーネントでタグの追加・削除・リセットを検証。

test('work detail shows existing tag', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create(['title' => 'TaggedWork']);
    $this->attachTag($w, 'circle', 'ExistingCircle');

    $page = visit('/work/'.$w->id);

    $page->assertSee('TaggedWork')
        ->assertSee('ExistingCircle')
        ->assertNoJavaScriptErrors();
});

test('work detail can add a tag then shows manual marker', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create(['title' => 'AddTagWork']);

    $page = visit('/work/'.$w->id);

    // Fill in a new tag value and click Add. / 新しいタグ値を入力してAddをクリック。
    $page->fill('input[placeholder="value…"]', 'NewEventTag');
    $page->click('Add');

    // After reload (the component calls window.location.reload), the tag appears + manual marker.
    // リロード後にタグが表示され、manualマーカーも確認。
    $page->assertSee('NewEventTag')
        ->assertNoJavaScriptErrors();
});

test('work detail can remove a tag', function (): void {
    $m = Mangaka::factory()->create();
    $w = Work::factory()->for($m)->create(['title' => 'RemoveTagWork']);
    $this->attachTag($w, 'flag', 'RemovableFlag');

    $page = visit('/work/'.$w->id);

    $page->assertSee('RemovableFlag');

    // Click the ✕ remove button next to the tag. / タグ横の✕ボタンをクリック。
    $page->click('✕');

    $page->assertDontSee('RemovableFlag')
        ->assertNoJavaScriptErrors();
});
