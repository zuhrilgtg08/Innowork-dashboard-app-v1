<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected static $imageIndex = 0;

    public function definition(): array
    {
        $milkProducts = [
            ['name' => 'Susu UHT Full Cream 1L', 'sku' => 'UHT-FC-1L'],
            ['name' => 'Susu UHT Low Fat 1L', 'sku' => 'UHT-LF-1L'],
            ['name' => 'Susu UHT Cokelat 250ml', 'sku' => 'UHT-CHO-250'],
            ['name' => 'Susu UHT Strawberry 250ml', 'sku' => 'UHT-STR-250'],
            ['name' => 'Susu Segar Pasteurisasi 900ml', 'sku' => 'FRM-PST-900'],
            ['name' => 'Susu Segar Murni 500ml', 'sku' => 'FRM-MRN-500'],
            ['name' => 'Susu Bubuk Instant 400g', 'sku' => 'PDR-INS-400'],
            ['name' => 'Susu Bubuk Full Cream 800g', 'sku' => 'PDR-FC-800'],
            ['name' => 'Susu Kental Manis Putih 370g', 'sku' => 'SKM-PTH-370'],
            ['name' => 'Susu Kental Manis Cokelat 370g', 'sku' => 'SKM-CHO-370'],
            ['name' => 'Yogurt Plain 150ml', 'sku' => 'YOG-PLN-150'],
            ['name' => 'Yogurt Strawberry 150ml', 'sku' => 'YOG-STR-150'],
            ['name' => 'Susu Organik Murni 1L', 'sku' => 'ORG-MRN-1L'],
            ['name' => 'Susu Organik Rendah Lemak 1L', 'sku' => 'ORG-LF-1L'],
            ['name' => 'Susu Skim 0% Fat 1L', 'sku' => 'SKM-0PC-1L'],
            ['name' => 'Susu Rendah Lemak 2% 1L', 'sku' => 'RL-2PC-1L'],
            ['name' => 'Susu Rasa Vanilla 250ml', 'sku' => 'RSA-VNL-250'],
            ['name' => 'Susu Rasa Mocca 250ml', 'sku' => 'RSA-MOC-250'],
            ['name' => 'Susu Kambing Segar 500ml', 'sku' => 'KMB-SGR-500'],
            ['name' => 'Susu Kambing Bubuk 200g', 'sku' => 'KMB-BUK-200'],
            ['name' => 'Keju Cheddar 200g', 'sku' => 'KEJ-CHD-200'],
            ['name' => 'Keju Mozzarella 250g', 'sku' => 'KEJ-MOZ-250'],
            ['name' => 'Mentega Murni 200g', 'sku' => 'MTG-MRN-200'],
            ['name' => 'Susu Kedelai Original 250ml', 'sku' => 'KDL-ORG-250'],
            ['name' => 'Susu Almond Vanilla 250ml', 'sku' => 'ALM-VNL-250'],
            ['name' => 'Susu Oat Original 1L', 'sku' => 'OAT-ORG-1L'],
            ['name' => 'Susu Pasteurisasi Cokelat 250ml', 'sku' => 'PST-CHO-250'],
            ['name' => 'Whip Cream 200ml', 'sku' => 'WHC-200'],
            ['name' => 'Susu Evaporasi 405g', 'sku' => 'EVP-405'],
            ['name' => 'Susu Kocok Vanilla 1L', 'sku' => 'KCK-VNL-1L'],
            ['name' => 'Susu Fermentasi Plain 150ml', 'sku' => 'FRM-PLN-150'],
            ['name' => 'Minuman Probiotik 100ml', 'sku' => 'PRO-100'],
            ['name' => 'Susu Pasteurisasi Strawberry 250ml', 'sku' => 'PST-STR-250'],
            ['name' => 'Susu Tinggi Kalsium 1L', 'sku' => 'KLS-1L'],
            ['name' => 'Susu Protein Tinggi 500ml', 'sku' => 'PROT-500'],
            ['name' => 'Greek Yogurt Honey 150g', 'sku' => 'GRK-HNY-150'],
            ['name' => 'Buttermilk 500ml', 'sku' => 'BTM-500'],
            ['name' => 'Susu Pasteurisasi Full Cream 2L', 'sku' => 'PST-FC-2L'],
            ['name' => 'Susu Kambing Pasteurisasi 1L', 'sku' => 'KMB-PST-1L'],
            ['name' => 'Susu Bubuk Omega 400g', 'sku' => 'PDR-OMG-400'],
        ];

        $product = $milkProducts[array_rand($milkProducts)];
        $images = $this->getMilkImages();
        $imageIndex = self::$imageIndex % max(count($images), 1);
        self::$imageIndex++;

        return [
            'code' => 'PRD-'.fake()->unique()->numerify('#####'),
            'name' => $product['name'],
            'category_id' => Category::inRandomOrder()->value('id'),
            'sku' => $product['sku'].'-'.fake()->numerify('###'),
            'status' => fake()->randomElement(['active', 'active', 'active', 'inactive', 'archived']),
            'stock' => fake()->numberBetween(50, 5000),
            'image' => !empty($images) ? $images[$imageIndex] : null,
            'description' => $product['name'].' - kualitas premium untuk konsumen Indonesia. Produk susu segar dan bergizi tinggi.',
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
