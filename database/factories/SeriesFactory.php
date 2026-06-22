<?php

namespace Database\Factories;

use App\Models\Mangaka;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        return [
            'mangaka_id' => Mangaka::factory(),
            'name' => $this->faker->words(2, true),
            'is_auto' => true,
        ];
    }
}
