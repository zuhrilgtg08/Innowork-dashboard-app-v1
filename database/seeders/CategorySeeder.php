<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $milkCategories = [
            ['name' => 'Susu UHT', 'description' => 'Susu UHT (Ultra High Temperature) dalam kemasan Tetra Pak, tahan lama tanpa pendinginan', 'sort_order' => 1],
            ['name' => 'Susu Pasteurisasi', 'description' => 'Susu segar pasteurisasi yang dijual dalam keadaan dingin, nutrisi terjaga', 'sort_order' => 2],
            ['name' => 'Susu Bubuk', 'description' => 'Susu bubuk instan untuk segala usia', 'sort_order' => 3],
            ['name' => 'Susu Kental Manis', 'description' => 'Susu kental manis untuk topping dan campuran minuman', 'sort_order' => 4],
            ['name' => 'Susu Organik', 'description' => 'Susu organik dari sapi yang dipelihara secara alami', 'sort_order' => 5],
            ['name' => 'Susu Rendah Lemak', 'description' => 'Susu dengan kandungan lemak rendah, cocok untuk diet', 'sort_order' => 6],
            ['name' => 'Susu Rasa', 'description' => 'Susu dengan berbagai varian rasa cokelat, strawberry, vanilla, dan mocca', 'sort_order' => 7],
            ['name' => 'Yogurt', 'description' => 'Yogurt probiotik segar untuk kesehatan pencernaan', 'sort_order' => 8],
            ['name' => 'Keju', 'description' => 'Keju olahan dan mozzarella dari susu segar', 'sort_order' => 9],
            ['name' => 'Susu Alternatif', 'description' => 'Susu nabati seperti susu kedelai, almond, dan oat', 'sort_order' => 10],
            ['name' => 'Susu Kambing', 'description' => 'Susu kambing segar dan olahan, alternatif bagi yang intoleran laktosa', 'sort_order' => 11],
            ['name' => 'Produk Olahan Susu Lainnya', 'description' => 'Mentega, whip cream, buttermilk, dan produk olahan susu lainnya', 'sort_order' => 12],
        ];

        foreach ($milkCategories as $cat) {
            $images = glob(public_path('assets/images/*.{jpg,jpeg,png,gif,webp}'), GLOB_BRACE);
            $imagePath = !empty($images) ? 'assets/images/' . basename($images[array_rand($images)]) : null;

            Category::updateOrCreate(
                ['slug' => Str::slug($cat['name'])],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'image' => $imagePath,
                    'is_active' => true,
                    'sort_order' => $cat['sort_order'],
                ]
            );
        }
    }
}