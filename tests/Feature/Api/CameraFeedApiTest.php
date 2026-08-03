<?php

namespace Tests\Feature\Api;

use App\Models\Camera;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\MlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CameraFeedApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    /** Swap the ML service so tests never touch the network. */
    private function fakeMl(?array $status, ?string $frame): void
    {
        $mock = Mockery::mock(MlClient::class);
        $mock->shouldReceive('cameraStatus')->andReturn($status);
        $mock->shouldReceive('cameraFrame')->andReturn($frame);
        $this->instance(MlClient::class, $mock);
    }

    public function test_it_lists_cameras_ordered_by_position(): void
    {
        Camera::create(['name' => 'CAM-02', 'conveyor' => 'LINE-B', 'is_active' => true, 'position' => 2]);
        Camera::create(['name' => 'CAM-01', 'conveyor' => 'LINE-A', 'is_active' => true, 'position' => 1]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/cameras')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'conveyor', 'is_active', 'position', 'is_live', 'source_kind']],
                'meta',
            ]);

        $this->assertSame('CAM-01', $response->json('data.0.name'));
        $this->assertSame('CAM-02', $response->json('data.1.name'));
    }

    public function test_it_never_exposes_the_rtsp_url(): void
    {
        // RTSP URLs routinely embed credentials, so they must not reach a client.
        Camera::create([
            'name' => 'ICAM-300',
            'rtsp_url' => 'rtsp://admin:hunter2@10.0.0.5:8550/video',
            'is_active' => true,
            'position' => 1,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/cameras')->assertOk();

        $this->assertArrayNotHasKey('rtsp_url', $response->json('data.0'));
        $response->assertDontSee('hunter2');
        // The client still learns it is a live source, just not how to reach it.
        $this->assertTrue($response->json('data.0.is_live'));
    }

    public function test_it_can_filter_by_active_flag(): void
    {
        Camera::create(['name' => 'ON', 'is_active' => true, 'position' => 1]);
        Camera::create(['name' => 'OFF', 'is_active' => false, 'position' => 2]);

        $this->actingAsAdmin();

        $this->assertSame(1, $this->getJson('/api/cameras?is_active=1')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/cameras?is_active=0')->assertOk()->json('meta.total'));
    }

    public function test_status_reports_the_live_source(): void
    {
        $this->fakeMl(['connected' => true, 'mode' => 'live', 'fps' => 14.6], null);
        $this->actingAsAdmin();

        $this->getJson('/api/cameras/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.service_reachable', true);
    }

    public function test_status_degrades_when_the_ml_service_is_unreachable(): void
    {
        // Must report offline rather than 500 — the screen should still render.
        $this->fakeMl(null, null);
        $this->actingAsAdmin();

        $this->getJson('/api/cameras/status')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.mode', 'offline')
            ->assertJsonPath('data.service_reachable', false);
    }

    public function test_frame_returns_jpeg_bytes_and_forbids_caching(): void
    {
        $this->fakeMl(null, "\xFF\xD8\xFF\xE0fake-jpeg-body");
        $this->actingAsAdmin();

        $response = $this->get('/api/cameras/frame')->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        // A cached frame would freeze the live view.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame("\xFF\xD8\xFF\xE0fake-jpeg-body", $response->getContent());
    }

    public function test_frame_returns_503_when_no_frame_is_available(): void
    {
        $this->fakeMl(null, null);
        $this->actingAsAdmin();

        $this->get('/api/cameras/frame')->assertStatus(503);
    }

    public function test_a_role_without_live_camera_access_cannot_pull_frames(): void
    {
        // Baseline matrix gives operator 'w' on Live Camera but viewer only 'r';
        // no role is denied outright, so grant a denial explicitly.
        RolePermission::updateOrCreate(
            ['role' => 'viewer', 'module' => 'Live Camera'],
            ['access' => '-'],
        );

        $this->fakeMl(['connected' => true, 'mode' => 'live', 'fps' => 10], 'jpeg');
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));

        $this->getJson('/api/cameras')->assertStatus(403);
        $this->get('/api/cameras/frame')->assertStatus(403);
    }

    public function test_guest_cannot_access_the_feed(): void
    {
        $this->getJson('/api/cameras')->assertStatus(401);
        $this->getJson('/api/cameras/frame')->assertStatus(401);
    }
}
