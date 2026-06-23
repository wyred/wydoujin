<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class ComponentsTest extends TestCase
{
    use RefreshDatabase, SeedsTags;

    public function test_cover_renders_image_when_path_present(): void
    {
        $html = Blade::render('<x-cover path="covers/abc.webp" title="My Title" />');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('/covers/abc.webp', $html);
    }

    public function test_cover_renders_placeholder_when_path_null(): void
    {
        $html = Blade::render('<x-cover :path="null" title="Placeholder Me" />');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Placeholder Me', $html);
    }

    public function test_work_card_links_to_work_and_shows_progress(): void
    {
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
    }

    public function test_badge_and_heading_render_slot(): void
    {
        $this->assertStringContainsString('オリジナル', Blade::render('<x-badge>オリジナル</x-badge>'));
        $this->assertStringContainsString('var(--color-primary)', Blade::render('<x-badge>x</x-badge>'));
        $this->assertStringContainsString('Recently Added', Blade::render('<x-section-heading>Recently Added</x-section-heading>'));
    }
}
