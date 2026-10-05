<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Last-known state of the robotic arm, stored as a cached singleton (same
 * pattern as {@see Setting}). The mqtt:listen consumer writes to it from
 * "arm/status" telemetry; the mobile app reads it via GET /api/arm.
 */
class ArmStatus extends Model
{
    protected $fillable = [
        'state',
        'detail',
        'last_command',
        'telemetry',
        'reported_at',
    ];

    /**
     * Baseline values so the singleton is always well-formed, even when created
     * via firstOrCreate([]) (Postgres defaults don't apply to Eloquent inserts
     * that omit the column).
     */
    protected $attributes = [
        'state' => 'idle',
    ];

    protected function casts(): array
    {
        return [
            'telemetry' => 'array',
            'reported_at' => 'datetime',
        ];
    }

    /**
     * Valid arm states with UI metadata (label + Tailwind color), the single
     * source of truth referenced by the API and any views.
     *
     * @var array<string, array{label: string, color: string}>
     */
    public const STATES = [
        'idle' => ['label' => 'Idle',    'color' => 'gray'],
        'running' => ['label' => 'Running', 'color' => 'green'],
        'error' => ['label' => 'Error',   'color' => 'red'],
        // Mock hardware states (lowercased by listener)
        'ready' => ['label' => 'Ready', 'color' => 'green'],
        'busy' => ['label' => 'Busy', 'color' => 'amber'],
        'picking' => ['label' => 'Picking', 'color' => 'blue'],
        'moving' => ['label' => 'Moving', 'color' => 'indigo'],
        'placing' => ['label' => 'Placing', 'color' => 'purple'],
        'returning' => ['label' => 'Returning', 'color' => 'pink'],
        'completed' => ['label' => 'Completed', 'color' => 'emerald'],
    ];

    /**
     * The singleton arm-status row, cached until changed.
     */
    public static function current(): self
    {
        return Cache::rememberForever('arm_status.singleton', fn () => static::firstOrCreate([]));
    }

    /**
     * Forget the cached singleton after a write.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('arm_status.singleton'));
        static::deleted(fn () => Cache::forget('arm_status.singleton'));
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state]['label'] ?? ucfirst((string) $this->state);
    }

    public function stateColor(): string
    {
        return self::STATES[$this->state]['color'] ?? 'gray';
    }
}
