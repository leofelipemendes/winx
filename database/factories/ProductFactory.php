<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'price' => number_format(fake()->numberBetween(100, 100000) / 100, 2, '.', ''),
            'stock' => fake()->numberBetween(0, 100),
            'category' => fake()->randomElement(['Electronics', 'Clothing', 'Books', 'Home', 'Sports']),
        ];
    }
}
