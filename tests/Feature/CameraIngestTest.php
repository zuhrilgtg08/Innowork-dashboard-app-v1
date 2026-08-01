<?php

namespace Tests\Feature;

use App\Models\Detection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CameraIngestTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-camera-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ml.secret' => $this->secret]);
    }

    /** POST a payload signed with the shared secret. */
    private function postSigned(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature ??= hash_hmac('sha256', $body, $this->secret);

        return $this->call(
            'POST', '/api/camera/detection', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ML_SIGNATURE' => $signature],
            $body,
        );
    }

    public function test_valid_signature_creates_detection(): void
    {
        Storage::fake('public');

        $jpeg = base64_encode('fake-jpeg-bytes');

        $res = $this->postSigned([
            'status' => 'damaged',
            'confidence' => 92.5,
            'boxes' => [['label' => 'damaged', 'confidence' => 92.5]],
            'camera' => 'ICAM-300',
            'conveyor' => 'LINE-A',
            'frame_jpeg_b64' => $jpeg,
        ]);

        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('detections', [
            'status' => 'damaged',
            'camera' => 'ICAM-300',
            'conveyor' => 'LINE-A',
        ]);

        $detection = Detection::first();
        $this->assertEquals('92.50', $detection->confidence);
        $this->assertNotNull($detection->frame_path);
        Storage::disk('public')->assertExists($detection->frame_path);
    }

    public function test_unknown_status_falls_back_to_recheck(): void
    {
        $this->postSigned(['status' => 'bogus', 'confidence' => 10])->assertOk();

        $this->assertDatabaseHas('detections', ['status' => 'recheck']);
    }

    public function test_it_persists_the_bounding_box_of_each_detection(): void
    {
        Storage::fake('public');

        // A real 2x3 JPEG so getimagesizefromstring() can read the dimensions.
        $image = imagecreatetruecolor(2, 3);
        ob_start();
        imagejpeg($image);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->postSigned([
            'status' => 'damaged',
            'confidence' => 91.2,
            'camera' => 'ICAM-300',
            'frame_jpeg_b64' => base64_encode($jpegBytes),
            'detections' => [
                ['status' => 'damaged', 'confidence' => 91.2, 'bbox' => [10, 20, 110, 220], 'label' => 'damaged'],
                ['status' => 'passed', 'confidence' => 80.0, 'bbox' => [5, 5, 50, 50], 'label' => 'passed'],
            ],
        ])->assertOk();

        $first = Detection::where('status', 'damaged')->firstOrFail();
        $this->assertSame([10, 20, 110, 220], $first->bbox);
        $this->assertSame('damaged', $first->label);
        // Frame dimensions travel with the box so a client can scale it.
        $this->assertSame(2, $first->frame_width);
        $this->assertSame(3, $first->frame_height);

        $second = Detection::where('status', 'passed')->firstOrFail();
        $this->assertSame([5, 5, 50, 50], $second->bbox);
    }

    public function test_a_detection_without_a_box_is_still_accepted(): void
    {
        // The manual/webcam path sends only a top-level verdict, no boxes.
        $this->postSigned([
            'status' => 'passed',
            'confidence' => 99.0,
            'camera' => 'CAM-01',
        ])->assertOk();

        $detection = Detection::firstOrFail();
        $this->assertNull($detection->bbox);
        $this->assertNull($detection->frame_width);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postSigned(['status' => 'passed'], signature: 'deadbeef')
            ->assertForbidden();

        $this->assertDatabaseCount('detections', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $body = json_encode(['status' => 'passed']);

        $this->call('POST', '/api/camera/detection', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertForbidden();
    }
}
