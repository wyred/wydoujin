<?php

use App\Models\Series;
use Tests\Feature\Series\SeedsMangakaWorks;

uses(SeedsMangakaWorks::class);

test('command detects series and reports', function (): void {
    $this->seedWork('Z.A.P.', '四畳半物語');
    $this->seedWork('Z.A.P.', '四畳半物語 二畳目');

    $this->artisan('wydoujin:series:detect')
        ->expectsOutputToContain('1 series created')
        ->assertSuccessful();

    $this->assertSame(1, Series::count());
    $this->assertSame('四畳半物語', Series::firstOrFail()->name);
});
