<?php

namespace Tests\Feature\Api;

use App\Models\RobotCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual/debug robot command bridge (H-1): VPS <-> ESP32 over HTTPS.
 *
 * Database doubles only — no live ESP32, internet, ML service, camera
 * or MQTT is required.
 */
class RobotCommandApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-device-token';

    private array $headers = ['Authorization' => 'Bearer '.self::TOKEN];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.robot.device_token' => self::TOKEN]);
    }

    private function createCommand(int $g, int $r, int $y, float $x = 100.0, float $yCoord = 50.0): array
    {
        $response = $this->postJson('/api/robot/commands', [
            'x' => $x,
            'y' => $yCoord,
            'G' => $g,
            'R' => $r,
            'Y' => $y,
        ], $this->headers);

        $response->assertStatus(201);

        return $response->json('command');
    }

    private function driveToExecuting(int $id, string $device = 'esp32'): void
    {
        $this->postJson("/api/robot/commands/{$id}/ack", ['device_id' => $device], $this->headers)->assertOk();
        $this->postJson("/api/robot/commands/{$id}/status", [
            'status' => 'EXECUTING',
            'device_id' => $device,
        ], $this->headers)->assertOk();
    }

    public function test_green_command_accepted(): void
    {
        $response = $this->postJson('/api/robot/commands', [
            'x' => 100.0,
            'y' => 50.0,
            'G' => 1,
            'R' => 0,
            'Y' => 0,
        ], $this->headers);

        $response->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.color', 'GREEN')
            ->assertJsonPath('command.status', 'PENDING')
            ->assertJsonPath('command.G', 1)
            ->assertJsonPath('command.R', 0)
            ->assertJsonPath('command.Y', 0)
            ->assertJsonStructure(['command' => ['id', 'uuid', 'x', 'y', 'G', 'R', 'Y', 'color', 'status']]);
    }

    public function test_red_command_accepted(): void
    {
        $command = $this->createCommand(0, 1, 0, 120.0, 60.0);

        $this->assertSame('RED', $command['color']);
        $this->assertSame('PENDING', $command['status']);
    }

    public function test_yellow_command_accepted(): void
    {
        $command = $this->createCommand(0, 0, 1, 140.0, 70.0);

        $this->assertSame('YELLOW', $command['color']);
        $this->assertSame('PENDING', $command['status']);
    }

    public function test_non_one_hot_flags_rejected(): void
    {
        foreach ([[0, 0, 0], [1, 1, 0], [1, 0, 1], [0, 1, 1], [1, 1, 1]] as [$g, $r, $y]) {
            $this->postJson('/api/robot/commands', [
                'x' => 100.0,
                'y' => 50.0,
                'G' => $g,
                'R' => $r,
                'Y' => $y,
            ], $this->headers)->assertStatus(422);
        }

        $this->assertSame(0, RobotCommand::count());
    }

    public function test_device_endpoints_reject_invalid_token(): void
    {
        $this->getJson('/api/robot/next-command', ['Authorization' => 'Bearer wrong-token'])
            ->assertStatus(401)
            ->assertJsonPath('ok', false);

        $this->postJson('/api/robot/commands', [
            'x' => 100.0, 'y' => 50.0, 'G' => 1, 'R' => 0, 'Y' => 0,
        ])->assertStatus(401);
    }

    public function test_next_command_returns_oldest_pending(): void
    {
        $first = $this->createCommand(1, 0, 0);
        $this->createCommand(0, 1, 0);

        $this->getJson('/api/robot/next-command?device_id=esp32-01', $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.id', $first['id'])
            ->assertJsonPath('command.color', 'GREEN');
    }

    public function test_command_is_not_handed_out_twice(): void
    {
        $first = $this->createCommand(1, 0, 0);
        $second = $this->createCommand(0, 1, 0);

        $this->getJson('/api/robot/next-command?device_id=device-a', $this->headers)
            ->assertOk()
            ->assertJsonPath('command.id', $first['id']);

        // A second device must not receive the already-claimed command.
        $this->getJson('/api/robot/next-command?device_id=device-b', $this->headers)
            ->assertOk()
            ->assertJsonPath('command.id', $second['id']);
    }

    public function test_ack_transition_works(): void
    {
        $command = $this->createCommand(1, 0, 0);

        $this->postJson("/api/robot/commands/{$command['id']}/ack", ['device_id' => 'esp32'], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.status', 'ACKNOWLEDGED');

        $this->assertNotNull(RobotCommand::find($command['id'])->acknowledged_at);
    }

    public function test_ack_is_idempotent(): void
    {
        $command = $this->createCommand(1, 0, 0);

        $this->postJson("/api/robot/commands/{$command['id']}/ack", [], $this->headers)->assertOk();
        $firstAckAt = RobotCommand::find($command['id'])->acknowledged_at;

        $this->postJson("/api/robot/commands/{$command['id']}/ack", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'ACKNOWLEDGED');

        $this->assertEquals($firstAckAt, RobotCommand::find($command['id'])->acknowledged_at);
    }

    public function test_executing_transition_works(): void
    {
        $command = $this->createCommand(0, 0, 1);

        $this->postJson("/api/robot/commands/{$command['id']}/ack", [], $this->headers)->assertOk();
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'EXECUTING',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'EXECUTING');

        $this->assertNotNull(RobotCommand::find($command['id'])->executing_at);
    }

    public function test_completed_transition_works(): void
    {
        $command = $this->createCommand(0, 1, 0);
        $this->driveToExecuting($command['id']);

        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'COMPLETED');

        $this->assertNotNull(RobotCommand::find($command['id'])->completed_at);
    }

    public function test_completed_repeat_is_idempotent(): void
    {
        $command = $this->createCommand(0, 1, 0);
        $this->driveToExecuting($command['id']);
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)->assertOk();

        $completedAt = RobotCommand::find($command['id'])->completed_at;

        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'COMPLETED');

        $this->assertEquals($completedAt, RobotCommand::find($command['id'])->completed_at);
    }

    public function test_invalid_transitions_rejected(): void
    {
        $command = $this->createCommand(1, 0, 0);

        // PENDING must go through ACK first.
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)->assertStatus(422);

        $this->driveToExecuting($command['id']);
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)->assertOk();

        // A finished command can never go back to EXECUTING or ACK.
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'EXECUTING',
        ], $this->headers)->assertStatus(422);
        $this->postJson("/api/robot/commands/{$command['id']}/ack", [], $this->headers)->assertStatus(422);
    }

    public function test_failed_is_terminal(): void
    {
        $command = $this->createCommand(0, 0, 1);
        $this->driveToExecuting($command['id']);

        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'FAILED',
            'error' => 'gripper timeout',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'FAILED');

        $failed = RobotCommand::find($command['id']);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame('gripper timeout', $failed->error_message);

        // FAILED can never leave the terminal state...
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'COMPLETED',
        ], $this->headers)->assertStatus(422);

        // ...but repeating FAILED stays a successful no-op.
        $this->postJson("/api/robot/commands/{$command['id']}/status", [
            'status' => 'FAILED',
            'error' => 'gripper timeout',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('command.status', 'FAILED');
    }

    public function test_no_pending_command_returns_null(): void
    {
        $this->getJson('/api/robot/next-command', $this->headers)
            ->assertOk()
            ->assertJson(['ok' => true, 'command' => null]);
    }

    public function test_queue_status_reports_real_counts(): void
    {
        $command = $this->createCommand(1, 0, 0);

        $this->getJson('/api/robot/status', $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('completed', 0)
            ->assertJsonPath('latest_command.id', $command['id']);
    }
}
