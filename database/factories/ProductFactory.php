<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $categories = ['Electronics', 'Apparel', 'Food & Beverage', 'Automotive Parts', 'Cosmetics', 'Pharmaceutical'];
        $name = ucwords(fake()->words(2, true));

        return [
            'code' => 'PRD-'.fake()->unique()->numerify('#####'),
            'name' => $name,
            'category' => fake()->randomElement($categories),
            'sku' => strtoupper(Str::slug($name)).'-'.fake()->numerify('###'),
            'status' => fake()->randomElement(['active', 'active', 'active', 'inactive', 'archived']),
            'stock' => fake()->numberBetween(0, 5000),
            'description' => fake()->sentence(10),
        ];
    }
}
