<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    protected static $imageIndex = 0;

    public function definition(): array
    {
        $milkCategories = [
            ['name' => 'Susu UHT', 'description' => 'Susu UHT (Ultra High Temperature) dengan daya tahan lama'],
            ['name' => 'Susu Pasteurisasi', 'description' => 'Susu segar yang dipasterisasi untuk menjaga nutrisi'],
            ['name' => 'Susu Bubuk', 'description' => 'Susu bubuk instan dengan berbagai varian rasa'],
            ['name' => 'Susu Kental Manis', 'description' => 'Susu kental manis untuk minuman dan makanan'],
            ['name' => 'Susu Organik', 'description' => 'Susu organik bebas pestisida dan hormon'],
            ['name' => 'Susu Rendah Lemak', 'description' => 'Susu low fat dan skim untuk diet sehat'],
            ['name' => 'Susu Rasa', 'description' => 'Susu dengan berbagai rasa cokelat, strawberry, vanilla'],
            ['name' => 'Susu Kambing', 'description' => 'Susu kambing segar dan olahan susu kambing'],
        ];

        $category = $milkCategories[array_rand($milkCategories)];
        $name = $category['name'];
        $slug = Str::slug($name);

        // Get images from public/assets/images
        $images = $this->getMilkImages();
        $imageIndex = self::$imageIndex % count($images);
        self::$imageIndex++;

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $category['description'],
            'image' => $images[$imageIndex] ?? null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    private function getMilkImages(): array
    {
        $publicImagesPath = public_path('assets/images');
        if (!is_dir($publicImagesPath)) {
            return [];
        }

        $files = glob($publicImagesPath . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
        return array_map(fn($file) => 'assets/images/' . basename($file), $files);
    }
}