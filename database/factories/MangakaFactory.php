<?php

namespace Database\Factories;

use App\Models\Mangaka;
use Illuminate\Database\Eloquent\Factories\Factory;

class MangakaFactory extends Factory
{
    protected $model = Mangaka::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        return ['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999)];
    }
}
