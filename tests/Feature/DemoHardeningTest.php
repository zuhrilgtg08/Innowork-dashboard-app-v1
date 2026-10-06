<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo-day hardening guards: Vision Sorting content, disabled public
 * registration, clean demo navigation, same-origin ML proxy behavior.
 */
class DemoHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_dashboard_shows_vision_sorting_content(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Vision Sorting Overview', escape: false);
        $response->assertSee('Advantech iCAM-300', escape: false);
        foreach (['Total Detections', 'GREEN', 'YELLOW', 'RED', 'Inference FPS', 'Inference Latency'] as $label) {
            $response->assertSee($label, escape: false);
        }
        $response->assertSee('Detection Distribution', escape: false);
        $response->assertSee('Export Report', escape: false);

        // Legacy QC terminology must not appear on the demo dashboard.
        foreach (['QC Overview', 'QR Unreadable', 'Damaged / Scratched', 'Returned / Recheck', 'Pass Rate'] as $legacy) {
            $response->assertDontSee($legacy, escape: false);
        }
    }

    public function test_dashboard_offline_state_is_honest(): void
    {
        // No ML service runs in tests: cards must show placeholders and the
        // offline notice instead of fake statistics.
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('ML Service Offline', escape: false);
        $response->assertSee('Waiting for ML service', escape: false);
    }

    public function test_login_has_no_signup_call_to_action(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Welcome back', escape: false);
        $response->assertSee('Sign in', escape: false);
        $response->assertDontSee('Sign up');
        $response->assertDontSee('/register');
    }

    public function test_guest_branding_uses_demo_copy(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('AI-Powered Vision Sorting System', escape: false);
        $response->assertDontSee('99.2%');
        $response->assertDontSee('Quality Control for Sorting');
    }

    public function test_demo_sidebar_hides_mqtt_sorting_pages(): void
    {
        config(['services.sorting.competition_mode' => true]);

        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Live Camera', escape: false);
        $response->assertSee('Model Evaluation', escape: false);
        // MQTT-driven legacy pages stay reachable by URL but leave the demo nav.
        $response->assertDontSee('Sorting Dashboard');
        $response->assertDontSee('Sorting Events');
        $response->assertDontSee('Device Status');
    }

    public function test_full_sidebar_keeps_sorting_pages_outside_demo_mode(): void
    {
        config(['services.sorting.competition_mode' => false]);

        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Sorting Dashboard', escape: false);
    }

    public function test_ml_proxy_routes_require_auth(): void
    {
        foreach (['ml.camera.raw', 'ml.camera.preview', 'ml.camera.frame', 'ml.camera.preview-frame',
            'ml.detections.latest', 'ml.stats.summary', 'ml.stats.confidence', 'ml.stats.timeline'] as $route) {
            $this->get(route($route))->assertRedirect('/login');
        }
    }

    public function test_ml_proxy_degrades_gracefully_when_offline(): void
    {
        $this->actingAs($this->admin());

        // No ML service runs in tests: JSON endpoints must answer with
        // honest offline payloads instead of throwing.
        $this->get(route('ml.detections.latest'))
            ->assertServiceUnavailable()
            ->assertJson(['ok' => false]);
        $this->get(route('ml.stats.summary'))->assertServiceUnavailable();

        $this->get(route('ml.stats.confidence'))
            ->assertOk()
            ->assertJson(['samples' => [], 'count' => 0]);
        $this->get(route('ml.stats.timeline'))
            ->assertOk()
            ->assertJsonPath('series', []);
    }

    public function test_same_origin_routes_resolve_without_loopback(): void
    {
        $response = $this->actingAs($this->admin())->get(route('live-camera'));

        $response->assertOk();
        $response->assertDontSee('127.0.0.1:8001');
        $response->assertDontSee('192.168.0.100');
    }

    public function test_model_evaluation_loads_with_runtime_panel(): void
    {
        $response = $this->actingAs($this->admin())->get(route('model-evaluation'));

        $response->assertOk();
        $response->assertSee('Live Runtime Detection', escape: false);
        $response->assertSee('Runtime Offline', escape: false);
    }
}
