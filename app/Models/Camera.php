<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical camera on the line. Detections reference a camera by its `name`
 * string (not a FK), so stats join on that. The ml-service consumes active rows
 * to run one capture/inference thread per feed (multi-camera support).
 */
class Camera extends Model
{
    protected $fillable = [
        'name',
        'conveyor',
        'rtsp_url',
        'sim_source',
        'is_active',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Detections captured by this camera (matched by name).
     */
    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class, 'camera', 'name');
    }

    /**
     * Is this feed backed by a real RTSP source (vs. simulator/webcam)?
     */
    public function isLive(): bool
    {
        return ! empty($this->rtsp_url);
    }

    /**
     * Default cameras seeded on install: one live ICAM slot plus a demo webcam,
     * mirroring the pattern of {@see RolePermission::defaults()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'ICAM-300', 'conveyor' => 'LINE-A', 'rtsp_url' => null, 'sim_source' => 'samples/conveyor.mp4', 'is_active' => true, 'position' => 1],
            ['name' => 'CAM-01', 'conveyor' => 'LINE-B', 'rtsp_url' => null, 'sim_source' => '0', 'is_active' => true, 'position' => 2],
        ];
    }
}
