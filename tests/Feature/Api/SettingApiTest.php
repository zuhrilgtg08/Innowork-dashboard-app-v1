<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    public function test_it_returns_the_settings_singleton(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'app_name', 'timezone', 'confidence_threshold', 'auto_retrain',
                'email_alerts', 'auto_reject_on_damage', 'camera_source', 'icam_rtsp_url',
            ]])
            ->assertJsonPath('data.app_name', 'SortVision');
    }

    public function test_it_updates_only_the_keys_sent(): void
    {
        $this->actingAsAdmin();
        Setting::current()->update(['app_name' => 'Awal', 'camera_source' => 'webcam']);

        $this->putJson('/api/settings', ['app_name' => 'Baru'])
            ->assertOk()
            ->assertJsonPath('data.app_name', 'Baru')
            // Untouched key keeps its value.
            ->assertJsonPath('data.camera_source', 'webcam');
    }

    public function test_it_busts_the_settings_cache(): void
    {
        $this->actingAsAdmin();

        // Prime the cached singleton, then change it through the API.
        $this->assertSame('SortVision', Setting::current()->app_name);

        $this->putJson('/api/settings', ['app_name' => 'Sesudah'])->assertOk();

        $this->assertSame('Sesudah', Setting::current()->app_name);
    }

    public function test_it_validates_the_confidence_threshold_range(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings', ['confidence_threshold' => 0.1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confidence_threshold']);

        $this->putJson('/api/settings', ['confidence_threshold' => 0.9])
            ->assertOk()
            ->assertJsonPath('data.confidence_threshold', 0.9);
    }

    public function test_it_validates_camera_source_and_timezone(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings', ['camera_source' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['camera_source']);

        $this->putJson('/api/settings', ['timezone' => 'Not/AZone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    public function test_an_empty_update_is_a_no_op(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings', [])
            ->assertOk()
            ->assertJsonPath('data.app_name', 'SortVision');
    }
}
