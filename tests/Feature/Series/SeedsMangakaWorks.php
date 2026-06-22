<?php

namespace Tests\Feature\Series;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Support\Str;

/** Seeds a Mangaka + Work directly (detection is pure DB; no zip needed). / DB直接投入。 */
trait SeedsMangakaWorks
{
    private int $hashSeq = 0;

    private function mangaka(string $name): Mangaka
    {
        return Mangaka::firstOrCreate(
            ['name' => $name],
            ['slug' => Str::slug($name) ?: 'm-'.substr(sha1($name), 0, 8)],
        );
    }

    /** @param array<string,mixed> $overrides */
    private function seedWork(string $mangaka, string $title, array $overrides = []): Work
    {
        $m = $this->mangaka($mangaka);
        $this->hashSeq++;

        return Work::create(array_merge([
            'content_hash' => str_pad((string) $this->hashSeq, 64, '0', STR_PAD_LEFT),
            'mangaka_id' => $m->id,
            'relative_path' => $mangaka.'/'.$title.'.zip',
            'filename' => $title.'.zip',
            'title' => $title,
            'title_raw' => $title,
            'sort_title' => $title,
        ], $overrides));
    }
}
