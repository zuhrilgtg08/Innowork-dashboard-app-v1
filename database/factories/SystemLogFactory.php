<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SystemLog>
 */
class SystemLogFactory extends Factory
{
    public function definition(): array
    {
        $messages = [
            'info' => [
                'camera' => 'CAM-01 stream reconnected successfully.',
                'conveyor' => 'LINE-A speed set to 1.2 m/s.',
                'system' => 'Scheduled backup completed.',
                'ai' => 'Detection model warm-up finished.',
                'auth' => 'User signed in.',
            ],
            'warning' => [
                'camera' => 'CAM-03 frame drop detected (12%).',
                'conveyor' => 'LINE-B belt tension below threshold.',
                'ai' => 'Low confidence batch flagged for recheck.',
                'system' => 'Disk usage reached 78%.',
            ],
            'error' => [
                'camera' => 'CAM-04 stream timeout after 30s.',
                'conveyor' => 'LINE-A emergency stop triggered.',
                'ai' => 'QR decode failed on 3 consecutive items.',
            ],
            'critical' => [
                'system' => 'Detection service crashed and restarted.',
            ],
        ];

        $level = fake()->randomElement(['info', 'info', 'info', 'warning', 'warning', 'error', 'critical']);
        $source = fake()->randomElement(array_keys($messages[$level]));
        $loggedAt = fake()->dateTimeBetween('-6 days', 'now');

        return [
            'level' => $level,
            'source' => $source,
            'message' => $messages[$level][$source],
            'context' => ['ref' => fake()->uuid()],
            'logged_at' => $loggedAt,
            'created_at' => $loggedAt,
            'updated_at' => $loggedAt,
        ];
    }
}
