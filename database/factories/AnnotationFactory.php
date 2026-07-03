<?php

namespace Database\Factories;

use App\Models\Annotation;
use App\Models\Detection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Annotation>
 */
class AnnotationFactory extends Factory
{
    protected $model = Annotation::class;

    public function definition(): array
    {
        $label = $this->faker->randomElement(array_keys(Detection::STATUSES));

        return [
            'image_path' => 'annotations/'.$this->faker->uuid().'.jpg',
            'label' => $label,
            'bbox' => [
                round($this->faker->randomFloat(3, 0, 0.5), 3),
                round($this->faker->randomFloat(3, 0, 0.5), 3),
                round($this->faker->randomFloat(3, 0.2, 0.5), 3),
                round($this->faker->randomFloat(3, 0.2, 0.5), 3),
            ],
            'status' => $this->faker->randomElement(array_keys(Annotation::STATUSES)),
            'source' => $this->faker->randomElement(Annotation::SOURCES),
            'confidence' => $this->faker->randomFloat(2, 0.6, 0.99),
        ];
    }

    /**
     * A reviewed, training-ready annotation.
     */
    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'source' => 'human']);
    }
}
