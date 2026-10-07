<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Detection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live Camera fleet grid: renders with cameras present (RTSP + simulator)
 * without undefined-key failures, and shows today's per-camera throughput.
 */
class LiveCameraFleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_camera_renders_fleet_without_undefined_keys(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $product = Product::factory()->create();

        Camera::create([
            'name' => 'ICAM-300', 'conveyor' => 'LINE-A',
            'rtsp_url' => 'rtsp://192.168.0.100:8550/video',
            'is_active' => true, 'position' => 1,
        ]);
        Camera::create([
            'name' => 'CAM-01', 'conveyor' => 'LINE-B',
            'rtsp_url' => null, 'sim_source' => '0',
            'is_active' => true, 'position' => 2,
        ]);

        Detection::factory()->create([
            'product_id' => $product->id, 'camera' => 'ICAM-300',
            'status' => 'passed', 'detected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Detection::factory()->create([
            'product_id' => $product->id, 'camera' => 'CAM-01',
            'status' => 'damaged', 'detected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('live-camera'));

        $response->assertOk();
        $response->assertSee('ICAM-300', escape: false);
        $response->assertSee('CAM-01', escape: false);
        $response->assertSee('RTSP', escape: false);
        $response->assertSee('SIM', escape: false);
        $response->assertDontSee('Undefined array key', escape: false);
        $response->assertDontSee('Undefined variable', escape: false);
    }

    public function test_esp32_send_panel_is_always_visible_in_icam_mode(): void
    {
        config(['services.sorting.competition_mode' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('live-camera'));

        $response->assertOk();
        // Panel kirim robot arm: judul + payload + IP/port default + tombol.
        $response->assertSee('Kirim ke ESP32', escape: false);
        $response->assertSee('192.168.100.77', escape: false);
        $response->assertSee('Kirim', escape: false);
        $response->assertDontSee('Undefined array key', escape: false);
        $response->assertDontSee('Undefined variable', escape: false);
    }
}
