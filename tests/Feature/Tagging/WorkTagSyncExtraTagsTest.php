<?php

use App\Models\Mangaka;
use App\Models\Work;
use App\Parsing\ParsedName;
use App\Tagging\WorkTagSync;

test('derive includes extraTags alongside scalar fields and flags', function (): void {
    $parsed = ParsedName::make(
        title: '虜物語',
        titleRaw: '虜物語',
        circle: 'ns2k',
        author: 'みまさかよろず',
    )->withExtraTags([['parody', '化物語']]);

    $pairs = app(WorkTagSync::class)->derive($parsed);

    expect($pairs)->toContain(['circle', 'ns2k'])
        ->toContain(['author', 'みまさかよろず'])
        ->toContain(['parody', '化物語']);
});

test('rescan re-derives identical tags for a _series work from its stored path', function (): void {
    $mangaka = Mangaka::create(['name' => 'みまさかよろず', 'slug' => 'mimasaka']);
    $work = Work::create([
        'mangaka_id' => $mangaka->id,
        'relative_path' => '_series/化物語/(同人誌) [ns2k (みまさかよろず)] 虜物語.zip',
        'filename' => '(同人誌) [ns2k (みまさかよろず)] 虜物語.zip',
        'title' => '虜物語',
        'title_raw' => '(同人誌) [ns2k (みまさかよろず)] 虜物語',
        'sort_title' => '虜物語',
        'content_hash' => 'hash-series-1',
        'page_count' => 1,
        'entries' => ['001.jpg'],
        'file_size' => 1,
        'file_mtime' => 1,
    ]);

    // sync() with no $parsed → the rescan branch, which must resolve from relative_path.
    app(WorkTagSync::class)->sync($work);

    $pairs = $work->tags()->get()->map(fn ($t) => [$t->type, $t->value])->all();
    expect($pairs)->toContain(['circle', 'ns2k'])
        ->toContain(['author', 'みまさかよろず'])
        ->toContain(['parody', '化物語']); // ← folder-derived, survives a rescan
});
