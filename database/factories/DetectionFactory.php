<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Detection>
 */
class DetectionFactory extends Factory
{
    public function definition(): array
    {
        // Weighted so most items pass, matching a healthy production line.
        $status = fake()->randomElement([
            'passed', 'passed', 'passed', 'passed', 'passed', 'passed', 'passed',
            'unreadable', 'damaged', 'scratched', 'returned', 'recheck',
        ]);

        $detectedAt = fake()->dateTimeBetween('-6 days', 'now');

        return [
            'code' => 'SCN-'.strtoupper(fake()->bothify('??##??')),
            'product_id' => Product::inRandomOrder()->value('id'),
            'camera' => fake()->randomElement(['CAM-01', 'CAM-02', 'CAM-03', 'CAM-04']),
            'conveyor' => fake()->randomElement(['LINE-A', 'LINE-B']),
            'status' => $status,
            'qr_value' => $status === 'unreadable' ? null : 'https://qc.local/i/'.fake()->bothify('########'),
            'confidence' => $status === 'unreadable'
                ? fake()->randomFloat(2, 20, 55)
                : fake()->randomFloat(2, 82, 99.9),
            'detected_at' => $detectedAt,
            'created_at' => $detectedAt,
            'updated_at' => $detectedAt,
        ];
    }
}
