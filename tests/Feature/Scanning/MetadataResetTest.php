<?php

use App\Models\Mangaka;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Work;
use App\Scanning\MetadataReset;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Scanning\BuildsLibraryFixtures;

uses(BuildsLibraryFixtures::class);

beforeEach(fn () => $this->bootLibrary());
afterEach(fn () => $this->cleanLibrary());

test('reset wipes tags, tombstones, series and covers but keeps works + progress', function (): void {
    $mangaka = Mangaka::create(['name' => 'Circle', 'slug' => 'circle']);
    $series = Series::create(['mangaka_id' => $mangaka->id, 'name' => 'Saga', 'sort_name' => 'saga', 'is_auto' => false]);

    $canonical = Tag::create(['type' => 'author', 'value' => 'Aoi']);
    $tombstone = Tag::create(['type' => 'author', 'value' => 'Aoy', 'merged_into_id' => $canonical->id]);

    $work = Work::factory()->for($mangaka)->create([
        'content_hash' => 'hash-keepme',
        'cover_path' => 'covers/hash-keepme.webp',
        'tags_locked' => true,
        'series_locked' => true,
        'series_id' => $series->id,
    ]);
    $work->tags()->attach($canonical->id);
    DB::table('reading_progress')->insert(['work_id' => $work->id, 'current_page' => 7, 'is_completed' => false, 'created_at' => now(), 'updated_at' => now()]);

    $coversDir = $this->dataPath.'/covers';
    mkdir($coversDir, 0775, true);
    file_put_contents($coversDir.'/hash-keepme.webp', 'fake');
    file_put_contents($coversDir.'/orphan.webp', 'fake');

    (new MetadataReset($coversDir))->reset();

    // Derived + curated metadata gone.
    expect(Tag::count())->toBe(0);
    expect(DB::table('work_tag')->count())->toBe(0);
    expect(Series::count())->toBe(0);
    expect(glob($coversDir.'/*.webp'))->toBe([]);

    // Work row kept (identity + progress survive), locks + cover_path + series_id cleared.
    $work->refresh();
    expect($work->exists)->toBeTrue();
    expect($work->content_hash)->toBe('hash-keepme');
    expect($work->cover_path)->toBeNull();
    expect((bool) $work->tags_locked)->toBeFalse();
    expect((bool) $work->series_locked)->toBeFalse();
    expect($work->series_id)->toBeNull();
    expect(DB::table('reading_progress')->where('work_id', $work->id)->value('current_page'))->toBe(7);
});

test('reset is a no-op when the covers dir does not exist', function (): void {
    (new MetadataReset($this->dataPath.'/nope'))->reset();

    expect(Tag::count())->toBe(0); // ran cleanly, nothing to delete
});
