<?php

namespace Tests\Feature\Series;

use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectSeriesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMangakaWorks;

    public function test_command_detects_series_and_reports(): void
    {
        $this->seedWork('Z.A.P.', '四畳半物語');
        $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

        $this->artisan('wydoujin:series:detect')
            ->expectsOutputToContain('1 series created')
            ->assertSuccessful();

        $this->assertSame(1, Series::count());
        $this->assertSame('四畳半物語', Series::firstOrFail()->name);
    }
}
