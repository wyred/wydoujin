<?php

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\SeedsTags;

uses(Tests\Concerns\SeedsTags::class);

test('cover renders image when path present', function (): void {
    $html = Blade::render('<x-cover path="covers/abc.webp" title="My Title" />');
    $this->assertStringContainsString('<img', $html);
    $this->assertStringContainsString('/covers/abc.webp', $html);
});

test('cover renders placeholder when path null', function (): void {
    $html = Blade::render('<x-cover :path="null" title="Placeholder Me" />');
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringContainsString('Placeholder Me', $html);
});

test('work card links to work and shows progress', function (): void {
    // create the work without the scalar, then attach the circle tag
    $work = Work::factory()->for(Mangaka::factory())->create(['title' => 'カードの題', 'page_count' => 20, 'cover_path' => 'covers/h.webp']);
    $this->attachTag($work, 'circle', 'サークルX');
    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 5]);
    $work->load('readingProgress', 'tags');

    $html = Blade::render('<x-work-card :work="$work" />', ['work' => $work]);
    $this->assertStringContainsString('href="/work/'.$work->id.'"', $html);
    $this->assertStringContainsString('カードの題', $html);
    $this->assertStringContainsString('サークルX', $html);
    $this->assertStringContainsString('5', $html); // progress count
});

test('work card renders a play shortcut to the reader', function (): void {
    $work = Work::factory()->for(Mangaka::factory())->create(['title' => 'PlayMe']);
    $work->load('readingProgress', 'tags');

    $html = Blade::render('<x-work-card :work="$work" />', ['work' => $work]);

    // Detail link is still present (cover + title both point at the work page).
    $this->assertStringContainsString('href="/work/'.$work->id.'"', $html);
    // The play circle links straight to the reader and is labelled for assistive tech.
    $this->assertStringContainsString('href="/work/'.$work->id.'/read"', $html);
    $this->assertStringContainsString('aria-label="Read '.$work->title.'"', $html);
});

test('badge and heading render slot', function (): void {
    $this->assertStringContainsString('オリジナル', Blade::render('<x-badge>オリジナル</x-badge>'));
    $this->assertStringContainsString('var(--color-primary)', Blade::render('<x-badge>x</x-badge>'));
    $this->assertStringContainsString('Recently Added', Blade::render('<x-section-heading>Recently Added</x-section-heading>'));
});
