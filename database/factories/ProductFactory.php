<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = ucwords(fake()->words(2, true));

        return [
            'code' => 'PRD-' . fake()->unique()->numerify('#####'),
            'name' => $name,
            'category_id' => \App\Models\Category::inRandomOrder()->first()?->id,
            'sku' => strtoupper(Str::slug($name)) . '-' . fake()->numerify('###'),
            'status' => fake()->randomElement(['active', 'active', 'active', 'inactive', 'archived']),
            'stock' => fake()->numberBetween(0, 5000),
            'description' => fake()->sentence(10),
        ];
    }
}
