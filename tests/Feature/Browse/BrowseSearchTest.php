<?php

namespace Tests\Feature\Browse;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsTags;
use Tests\TestCase;

class BrowseSearchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private ?Mangaka $m = null;

    /** @param array<string,mixed> $a */
    private function work(array $a): Work
    {
        $this->m ??= Mangaka::factory()->create();

        return Work::factory()->for($this->m)->create($a);
    }

    public function test_browse_renders_grid_and_nav_link(): void
    {
        $this->work(['title' => 'Findable Title', 'sort_title' => 'a']);

        $this->get('/browse')->assertOk()
            ->assertSee('Findable Title')
            ->assertSee('href="/browse"', false)  // nav link
            ->assertSee('No works match');         // empty-state element present in DOM (Alpine-hidden)
    }

    public function test_q_filters_server_rendered_results(): void
    {
        $this->work(['title' => 'Alpha Doujin', 'sort_title' => 'a']);
        $this->work(['title' => 'Beta Manga', 'sort_title' => 'b']);

        $this->get('/browse?q=alpha')->assertOk()
            ->assertSee('Alpha Doujin')
            ->assertDontSee('Beta Manga');
    }

    public function test_facet_filters_results(): void
    {
        $zap = $this->work(['title' => 'ZapWork', 'sort_title' => 'a']); $this->attachTag($zap, 'circle', 'Z.A.P.');
        $foo = $this->work(['title' => 'FooWork', 'sort_title' => 'b']); $this->attachTag($foo, 'circle', 'Foo');

        $url = '/browse?'.http_build_query(['circle' => ['Z.A.P.']]);
        $this->get($url)->assertOk()
            ->assertSee('ZapWork')
            ->assertDontSee('FooWork');
    }

    public function test_excludes_missing(): void
    {
        $this->work(['title' => 'GoneWork', 'sort_title' => 'a', 'is_missing' => true]);

        $this->get('/browse')->assertOk()->assertDontSee('GoneWork');
    }

    public function test_embeds_facet_data_for_alpine(): void
    {
        $w = $this->work(['title' => 'X', 'sort_title' => 'a']); $this->attachTag($w, 'circle', 'Z.A.P.');

        // The facet value ships in the embedded initial-state JSON.
        $this->get('/browse')->assertOk()->assertSee('Z.A.P.');
    }

    public function test_json_endpoint_shape(): void
    {
        $w = $this->work(['title' => 'JsonWork', 'sort_title' => 'a']); $this->attachTag($w, 'circle', 'C1');

        $res = $this->getJson('/browse')->assertOk()
            ->assertJsonStructure([
                'total', 'page', 'hasMore',
                'facets' => ['circle', 'parody', 'event', 'author', 'flag', 'theme'],
                'html',
            ]);
        $this->assertStringContainsString('JsonWork', $res->json('html'));
    }
}
