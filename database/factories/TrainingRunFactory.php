<?php

namespace Database\Factories;

use App\Models\Detection;
use App\Models\TrainingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRun>
 */
class TrainingRunFactory extends Factory
{
    protected $model = TrainingRun::class;

    public function definition(): array
    {
        $epochs = $this->faker->numberBetween(3, 15);

        return [
            'name' => 'yolov8n-qc-'.$this->faker->unique()->numberBetween(1, 9999),
            'status' => 'queued',
            'progress' => 0,
            'current_epoch' => 0,
            'epochs' => $epochs,
            'dataset_train' => $this->faker->numberBetween(20, 200),
            'dataset_val' => $this->faker->numberBetween(5, 50),
        ];
    }

    /**
     * A finished run with realistic per-class metrics on the 0-100 scale.
     */
    public function completed(): static
    {
        return $this->state(function () {
            $perClass = collect(Detection::STATUSES)->keys()->map(fn ($key) => [
                'label' => $key,
                'precision' => $this->faker->randomFloat(1, 85, 99),
                'recall' => $this->faker->randomFloat(1, 80, 99),
                'map50' => $this->faker->randomFloat(1, 82, 99),
                'samples' => $this->faker->numberBetween(10, 80),
            ])->all();

            $epochs = $this->faker->numberBetween(5, 15);

            return [
                'status' => 'completed',
                'progress' => 100,
                'current_epoch' => $epochs,
                'epochs' => $epochs,
                'metrics' => [
                    'map50' => $this->faker->randomFloat(1, 90, 99),
                    'precision' => $this->faker->randomFloat(1, 88, 99),
                    'recall' => $this->faker->randomFloat(1, 85, 99),
                    'per_class' => $perClass,
                ],
                'model_path' => 'models/run-'.$this->faker->numberBetween(1, 999).'/best.pt',
                'started_at' => now()->subMinutes(30),
                'finished_at' => now()->subMinutes(5),
            ];
        });
    }
}
