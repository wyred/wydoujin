<?php

namespace Database\Factories;

use App\Models\Mangaka;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkFactory extends Factory
{
    protected $model = Work::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        return [
            'content_hash' => hash('sha256', Str::uuid()->toString()),
            'mangaka_id' => Mangaka::factory(),
            'relative_path' => $this->faker->word().'/'.$title.'.zip',
            'filename' => $title.'.zip',
            'title' => $title,
            'title_raw' => $title,
            'page_count' => $this->faker->numberBetween(1, 200),
            'file_size' => $this->faker->numberBetween(1000, 5_000_000),
            'file_mtime' => time(),
            'last_seen_at' => now(),
        ];
    }
}
