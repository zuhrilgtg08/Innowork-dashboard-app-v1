<?php

namespace Tests\Feature;

use App\Models\ArmStatus;
use App\Models\Detection;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H-1 Vision Sorting repair guards (§2, §6, §7, §8):
 * SortingEvent persistence, ingest competition fields, preflight gates.
 */
class SortingPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-sorting-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ml.secret' => $this->secret]);
    }

    /** POST a payload signed with the shared secret. */
    private function postSigned(string $uri, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $this->secret);

        return $this->call(
            'POST', $uri, [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ML_SIGNATURE' => $signature,
            ],
            $body,
        );
    }

    private function makeDetection(): Detection
    {
        return Detection::create([
            'code' => 'TST-'.strtoupper(Str::random(6)),
            'camera' => 'ICAM-300',
            'status' => 'passed',
            'detected_at' => now(),
        ]);
    }

    public function test_sorting_event_persists_all_fields(): void
    {
        $detection = $this->makeDetection();
        $session = SortingSession::current();
        $uuid = (string) Str::uuid();

        $event = SortingEvent::create([
            'event_uuid' => $uuid,
            'detection_id' => $detection->id,
            'sorting_session_id' => $session->id,
            'status' => 'pending',
            'color' => 'red',
            'destination' => 'BOWL_RED',
            'confidence' => 97.3,
            'command_payload' => ['action' => 'sort', 'color' => 'red'],
            'source' => 'ml_service',
            'processed' => false,
        ]);

        $this->assertDatabaseHas('sorting_events', [
            'event_uuid' => $uuid,
            'detection_id' => $detection->id,
            'sorting_session_id' => $session->id,
            'status' => 'pending',
            'color' => 'red',
            'destination' => 'BOWL_RED',
            'source' => 'ml_service',
        ]);

        $fresh = $event->fresh();
        $this->assertEquals($session->id, $fresh->sorting_session_id);
        $this->assertEquals(['action' => 'sort', 'color' => 'red'], $fresh->command_payload);
        $this->assertFalse((bool) $fresh->processed);
    }

    public function test_ingest_persists_competition_fields(): void
    {
        $eventUuid = (string) Str::uuid();

        $res = $this->postSigned('/api/camera/detection', [
            'status' => 'passed',
            'confidence' => 96.2,
            'color' => 'red',
            'center_x' => 320,
            'center_y' => 240,
            'in_pick_zone' => true,
            'competition_event_id' => $eventUuid,
        ]);

        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('detections', [
            'color' => 'red',
            'center_x' => 320,
            'center_y' => 240,
            'in_pick_zone' => true,
            'competition_event_id' => $eventUuid,
        ]);
    }

    public function test_preflight_reports_bowl_full(): void
    {
        $session = SortingSession::current();
        foreach (range(1, 3) as $i) {
            SortingEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'detection_id' => $this->makeDetection()->id,
                'sorting_session_id' => $session->id,
                'status' => 'completed',
                'color' => 'red',
                'destination' => 'BOWL_RED',
                'confidence' => 90.0,
                'source' => 'ml_service',
            ]);
        }

        $full = $this->postSigned('/api/sorting/preflight', ['color' => 'red']);
        $full->assertOk()->assertJson(['ok' => true, 'bowl_full' => true, 'count' => 3, 'max' => 3]);

        $open = $this->postSigned('/api/sorting/preflight', ['color' => 'green']);
        $open->assertOk()->assertJson(['ok' => true, 'bowl_full' => false, 'count' => 0]);
    }

    public function test_preflight_reports_arm_busy(): void
    {
        ArmStatus::current()->update(['state' => 'picking']);
        $busy = $this->postSigned('/api/sorting/preflight', ['color' => 'green']);
        $busy->assertOk()->assertJson(['ok' => true, 'arm_busy' => true, 'arm_state' => 'picking']);

        ArmStatus::current()->update(['state' => 'ready']);
        $ready = $this->postSigned('/api/sorting/preflight', ['color' => 'green']);
        $ready->assertOk()->assertJson(['ok' => true, 'arm_busy' => false, 'arm_state' => 'ready']);
    }

    public function test_preflight_rejects_unknown_color(): void
    {
        $this->postSigned('/api/sorting/preflight', ['color' => 'blue'])->assertStatus(422);
    }
}
