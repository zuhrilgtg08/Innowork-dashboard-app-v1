<?php

namespace App\Models;

use App\Services\ArmMqttService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A joint-angle recipe for the robotic arm keyed by product category. In Opsi A
 * (no Jetson Nano) the backend owns these presets instead of an on-device
 * inverse-kinematics solver — {@see ArmMqttService::publishCommand()}
 * looks one up and publishes the ready-to-run angles to the ESP32.
 */
class TargetZonePreset extends Model
{
    protected $fillable = [
        'slug',
        'category',
        'label',
        'joint_angles',
    ];

    protected function casts(): array
    {
        return [
            'joint_angles' => 'array',
        ];
    }

    /**
     * Slug of the fallback preset used when a category has no specific entry.
     */
    public const DEFAULT_SLUG = 'default';

    /**
     * Slug of the return/reject zone the arm drops auto-rejected defects into.
     */
    public const RETURN_SLUG = 'return';

    /**
     * Resolve the preset for a product category, falling back to the default.
     * Accepts either a category label ("Food & Beverage") or its slug.
     */
    public static function forCategory(?string $category): ?self
    {
        $slug = Str::slug((string) $category);

        return static::where('slug', $slug)->first()
            ?? static::where('slug', self::DEFAULT_SLUG)->first();
    }

    /**
     * Resolve the return/reject zone preset, falling back to the default.
     */
    public static function forReturn(): ?self
    {
        return static::where('slug', self::RETURN_SLUG)->first()
            ?? static::where('slug', self::DEFAULT_SLUG)->first();
    }

    /**
     * Placeholder presets seeded on install: one per {@see Product::CATEGORIES}
     * plus a default fallback. The 6-axis angles are dummy values spread out so
     * each zone is visibly distinct — the team replaces them with tuned values
     * later. Mirrors {@see RolePermission::defaults()} as the seed source.
     *
     * @return array<int, array{slug: string, category: ?string, label: string, joint_angles: array<int, int>}>
     */
    public static function defaults(): array
    {
        $presets = [[
            'slug' => self::DEFAULT_SLUG,
            'category' => null,
            'label' => 'Default / Uncategorised',
            'joint_angles' => [0, 0, 0, 0, 0, 0],
        ], [
            'slug' => self::RETURN_SLUG,
            'category' => null,
            'label' => 'Return / Reject Zone',
            'joint_angles' => [180, 45, 90, 45, 90, 0],
        ]];

        foreach (Product::CATEGORIES as $i => $category) {
            $base = ($i + 1) * 10;
            $presets[] = [
                'slug' => Str::slug($category),
                'category' => $category,
                'label' => $category.' Zone',
                'joint_angles' => [$base, $base + 5, $base + 10, $base + 15, $base + 20, $base + 25],
            ];
        }

        return $presets;
    }
}
