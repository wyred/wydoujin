<?php

namespace Tests\Feature;

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationships_and_casts(): void
    {
        $mangaka = Mangaka::factory()->create();
        $series = Series::factory()->for($mangaka)->create();
        $work = Work::factory()
            ->for($mangaka)
            ->for($series)
            ->create(['flags' => ['DL版'], 'entries' => ['001.jpg', '002.jpg']]);

        ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

        $this->assertTrue($mangaka->works->contains($work));
        $this->assertTrue($mangaka->series->contains($series));
        $this->assertTrue($series->works->contains($work));
        $this->assertEquals($mangaka->id, $work->mangaka->id);
        $this->assertEquals($series->id, $work->series->id);
        $this->assertSame(['DL版'], $work->flags);
        $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
        $this->assertSame(3, $work->readingProgress->current_page);
    }
}
