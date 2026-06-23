<?php

use App\Models\Mangaka;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Models\Work;

test('relationships and casts', function (): void {
    $mangaka = Mangaka::factory()->create();
    $series = Series::factory()->for($mangaka)->create();
    $work = Work::factory()
        ->for($mangaka)
        ->for($series)
        ->create(['entries' => ['001.jpg', '002.jpg']]);

    ReadingProgress::create(['work_id' => $work->id, 'current_page' => 3]);

    $this->assertTrue($mangaka->works->contains($work));
    $this->assertTrue($mangaka->series->contains($series));
    $this->assertTrue($series->works->contains($work));
    $this->assertEquals($mangaka->id, $work->mangaka->id);
    $this->assertEquals($series->id, $work->series->id);
    $this->assertSame(['001.jpg', '002.jpg'], $work->entries);
    $this->assertSame(3, $work->readingProgress->current_page);
});

test('work without series has null series', function (): void {
    $mangaka = Mangaka::factory()->create();
    $work = Work::factory()->for($mangaka)->create();

    $this->assertNull($work->series_id);
    $this->assertNull($work->series);
});
