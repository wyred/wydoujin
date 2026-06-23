<?php

namespace Tests\Feature\Tagging;

use App\Models\Tag;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class WorkTagControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    public function test_attach_creates_links_and_locks(): void
    {
        $work = Work::factory()->create();

        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'theme', 'value' => 'netorare'])
            ->assertStatus(201);

        $tag = Tag::where('type', 'theme')->where('value', 'netorare')->firstOrFail();
        $this->assertTrue($work->fresh()->tags->contains($tag));
        $this->assertTrue($work->fresh()->tags_locked);
    }

    public function test_attach_resolves_alias_to_canonical(): void
    {
        $work = Work::factory()->create();
        $canon = Tag::create(['type' => 'parody', 'value' => 'Fate/Grand Order']);
        Tag::create(['type' => 'parody', 'value' => 'FGO', 'merged_into_id' => $canon->id]);

        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'parody', 'value' => 'FGO'])->assertStatus(201);

        $this->assertSame([$canon->id], $work->fresh()->tags->pluck('id')->all());
    }

    public function test_attach_validates_type_and_value(): void
    {
        $work = Work::factory()->create();
        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'bogus', 'value' => 'x'])->assertStatus(422);
        $this->postJson('/work/'.$work->id.'/tags/attach', ['type' => 'circle', 'value' => '  '])->assertStatus(422);
    }

    public function test_detach_removes_and_locks(): void
    {
        $work = Work::factory()->create();
        $tag = $this->attachTag($work, 'circle', 'A');

        $this->postJson('/work/'.$work->id.'/tags/detach', ['tag_id' => $tag->id])->assertOk();

        $this->assertFalse($work->fresh()->tags->contains($tag));
        $this->assertTrue($work->fresh()->tags_locked);
    }

    public function test_reset_unlocks_and_rederives_from_filename(): void
    {
        // filename parses to circle "Z.A.P." + title; a stray manual tag is wiped on reset.
        $work = Work::factory()->create(['filename' => '[Z.A.P.] Title.zip', 'tags_locked' => true]);
        $this->attachTag($work, 'theme', 'stray');

        $this->postJson('/work/'.$work->id.'/tags/reset')->assertOk();

        $work->refresh();
        $this->assertFalse($work->tags_locked);
        $this->assertSame([['circle', 'Z.A.P.']], $work->tags->map(fn (Tag $t) => [$t->type, $t->value])->all());
    }

    public function test_suggest_returns_matching_canonical_values(): void
    {
        $w = Work::factory()->create();
        $this->attachTag($w, 'circle', 'Zucchini');
        $this->attachTag($w, 'circle', 'Zenith');
        $this->attachTag($w, 'parody', 'Other');

        $this->getJson('/tags/suggest?type=circle&q=Zu')->assertOk()->assertExactJson(['Zucchini']);
    }
}
