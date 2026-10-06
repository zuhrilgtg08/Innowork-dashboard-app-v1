<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Production hardening guards: the Dashboard "Export Report" button must
 * download a real CSV of Detection records that respects the selected
 * range + status filter, and the Vision Sorting routes must stay valid.
 */
class DashboardExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeDetection(string $status, $detectedAt, ?string $code = null, string $camera = 'CAM-01'): Detection
    {
        return Detection::create([
            'code' => $code ?? 'SCN-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'camera' => $camera,
            'conveyor' => 'LINE-A',
            'status' => $status,
            'confidence' => 95.5,
            'detected_at' => $detectedAt,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /** Capture the streamed CSV body from the component action. */
    private function exportCsv(Dashboard $dashboard): string
    {
        $response = $dashboard->exportReport();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function csvRows(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", $csv)),
            fn ($line) => trim($line) !== ''
        ));

        return array_map(fn ($line) => str_getcsv($line), $lines);
    }

    private function detailCodes(string $csv): array
    {
        $rows = $this->csvRows($csv);
        $headerIndex = array_search(['Code', 'Product', 'Camera', 'Line', 'Status', 'Confidence (%)', 'Detected At'], $rows);
        $this->assertNotFalse($headerIndex, 'Missing detail table header');

        return array_column(array_slice($rows, $headerIndex + 1), 0);
    }

    public function test_dashboard_loads_with_export_button(): void
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Export Report', escape: false);
        $response->assertSee('exportReport', escape: false);
        // English-only UI on the dashboard surface.
        $response->assertDontSee('Belum ada deteksi');
        $response->assertDontSee('Cari produk');
    }

    public function test_export_returns_downloadable_csv(): void
    {
        $this->actingAs($this->admin());
        $this->makeDetection('passed', now(), 'SCN-AAA111');
        $this->makeDetection('damaged', now(), 'SCN-BBB222');

        Livewire::test(Dashboard::class)
            ->call('exportReport')
            ->assertFileDownloaded();

        $rows = $this->csvRows($this->exportCsv(new Dashboard));

        // Summary section.
        $this->assertSame(['SortVision Detection Report'], $rows[0]);
        $flat = array_column($rows, 0);
        foreach (['Generated At', 'Selected Range', 'Selected Status', 'Total Detections', 'Passed', 'Unreadable', 'Defective', 'Returned/Recheck', 'Pass Rate', 'Throughput', 'Active Cameras'] as $label) {
            $this->assertContains($label, $flat, "Missing summary row: {$label}");
        }

        // Detail table header + both real records, no fake data.
        $headerIndex = array_search(['Code', 'Product', 'Camera', 'Line', 'Status', 'Confidence (%)', 'Detected At'], $rows);
        $this->assertNotFalse($headerIndex, 'Missing detail table header');
        $detail = array_slice($rows, $headerIndex + 1);
        $codes = array_column($detail, 0);
        $this->assertContains('SCN-AAA111', $codes);
        $this->assertContains('SCN-BBB222', $codes);
        $this->assertCount(2, $detail);
    }

    public function test_export_respects_range_filter(): void
    {
        $this->makeDetection('passed', now()->subDays(10), 'SCN-OLD999');
        $this->makeDetection('passed', now(), 'SCN-NEW111');

        $dashboard = new Dashboard;
        $dashboard->range = 'today';
        $codes = $this->detailCodes($this->exportCsv($dashboard));
        // Today-only export excludes the 10-day-old record.
        $this->assertContains('SCN-NEW111', $codes);
        $this->assertNotContains('SCN-OLD999', $codes);

        $dashboard = new Dashboard;
        $dashboard->range = '30d';
        $codes = $this->detailCodes($this->exportCsv($dashboard));
        $this->assertContains('SCN-NEW111', $codes);
        $this->assertContains('SCN-OLD999', $codes);
    }

    public function test_export_respects_status_filter(): void
    {
        $this->makeDetection('passed', now(), 'SCN-PASS11');
        $this->makeDetection('damaged', now(), 'SCN-DMG222');

        $dashboard = new Dashboard;
        $dashboard->range = 'today';
        $dashboard->statusFilter = 'passed';
        $csv = $this->exportCsv($dashboard);
        $codes = $this->detailCodes($csv);

        $this->assertContains('SCN-PASS11', $codes);
        $this->assertNotContains('SCN-DMG222', $codes);
        // Summary reflects the selected status.
        $this->assertStringContainsString('Passed', $csv);
    }

    public function test_export_with_empty_result_still_valid_csv(): void
    {
        $dashboard = new Dashboard;
        $dashboard->range = 'today';
        $rows = $this->csvRows($this->exportCsv($dashboard));

        $this->assertSame(['SortVision Detection Report'], $rows[0]);
        $headerIndex = array_search(['Code', 'Product', 'Camera', 'Line', 'Status', 'Confidence (%)', 'Detected At'], $rows);
        $this->assertNotFalse($headerIndex, 'Empty export must still contain headers');
        $this->assertCount(0, array_slice($rows, $headerIndex + 1));
    }

    public function test_vision_sorting_routes_remain_valid(): void
    {
        $this->actingAs($this->admin());

        foreach (['sorting.dashboard', 'sorting.events', 'sorting.device-status', 'model-evaluation', 'live-camera', 'dashboard'] as $route) {
            $this->get(route($route))->assertOk("Route {$route} must load");
        }
    }

    public function test_live_camera_prefers_server_stream_in_vision_mode(): void
    {
        config(['services.sorting.competition_mode' => true]);

        $response = $this->actingAs($this->admin())->get(route('live-camera'));

        $response->assertOk();
        // Same-origin proxy — never a hard-coded loopback ML address.
        // Primary view is the YOLO annotated preview; raw is secondary.
        $response->assertSee(route('ml.camera.preview'), escape: false);
        $response->assertSee(route('ml.camera.raw'), escape: false);
        $response->assertDontSee('http://127.0.0.1:8001');
        // Clean English offline state, no webcam permission errors.
        $response->assertSee('YOLO Preview', escape: false);
        $response->assertSee('Camera Stream Unavailable', escape: false);
        $response->assertDontSee('Akses kamera ditolak');
        $response->assertDontSee('Kamera tidak tersedia');
    }
}
