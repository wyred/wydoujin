<?php

use App\Jobs\RescanWork;
use App\Models\Work;
use Illuminate\Support\Facades\Bus;

test('rescan endpoint queues a RescanWork job and returns 202', function (): void {
    Bus::fake();
    $work = Work::factory()->create();

    $this->postJson('/work/'.$work->id.'/rescan')
        ->assertStatus(202)
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(RescanWork::class, fn (RescanWork $job) => $job->workId === $work->id);
});

test('rescan endpoint 404s for an unknown work', function (): void {
    Bus::fake();

    $this->postJson('/work/999999/rescan')->assertNotFound();

    Bus::assertNotDispatched(RescanWork::class);
});

test('the work detail page shows a Rescan control', function (): void {
    $work = Work::factory()->create();

    $this->get('/work/'.$work->id)
        ->assertOk()
        ->assertSee('Rescan')
        ->assertSee('/work/'.$work->id.'/rescan');
});
