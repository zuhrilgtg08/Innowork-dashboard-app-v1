<?php

namespace App\Console\Commands;

use App\Models\SystemLog;
use App\Services\ArmMqttService;
use App\Services\ConveyorService;
use App\Services\MlClient;
use Illuminate\Console\Command;

/**
 * Periodic liveness probe for the moving parts an operator can't see from the
 * dashboard: the ML service, the MQTT broker (arm + conveyor). Any failure is
 * written to SystemLog (level error) so it surfaces in Logs Sistem. Schedule it
 * (see routes/console.php) for 24/7 operation.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'sortvision:health-check {--quiet-ok : Jangan tulis log kalau semua sehat}';

    protected $description = 'Cek kesehatan ML service & MQTT broker, catat kegagalan ke SystemLog';

    public function handle(MlClient $ml, ArmMqttService $arm, ConveyorService $conveyor): int
    {
        $checks = [
            'ml_service' => $ml->healthy(),
            'mqtt_arm' => $arm->isConnected(),
            'mqtt_conveyor' => $conveyor->isConnected(),
        ];

        $down = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        foreach ($checks as $name => $ok) {
            $this->line(sprintf('%-16s %s', $name, $ok ? '<info>OK</info>' : '<error>DOWN</error>'));
        }

        if ($down === []) {
            if (! $this->option('quiet-ok')) {
                SystemLog::create([
                    'level' => 'info',
                    'source' => 'system',
                    'message' => 'Health check: semua layanan sehat (ML, MQTT arm & conveyor).',
                    'context' => $checks,
                    'logged_at' => now(),
                ]);
            }

            return self::SUCCESS;
        }

        SystemLog::create([
            'level' => 'error',
            'source' => 'system',
            'message' => 'Health check gagal: '.implode(', ', $down).' tidak responsif.',
            'context' => $checks,
            'logged_at' => now(),
        ]);

        return self::FAILURE;
    }
}
